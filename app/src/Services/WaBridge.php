<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * WhatsApp to candidates through WABridge (web.wabridge.com/api), the same code as the HRMS's. Each request carries the app key and the auth key (kept in
 * Secrets, typed in by the owner) and the device ID (Settings). Only Meta-approved templates are sent: our template
 * name is matched to WABridge's template ID by "Sync templates". Messages are queued and sent by the scheduled job;
 * delivery is fetched afterwards (getdlr). Not live on this PC, or until the owner switches sending on: messages are
 * then written to storage/wa-outbox instead.
 */
final class WaBridge
{
    public const BASE = 'https://web.wabridge.com/api/';
    /** Final delivery states (no need to ask again). */
    private const FINAL = ['delivered', 'read', 'failed', 'deleted', 'undelivered'];

    /** @var null|callable(string $endpoint, array $payload): array{0: int, 1: string} for tests */
    public static $transport = null;

    public function __construct(private readonly App $app)
    {
    }

    public function configured(): bool
    {
        return Secrets::has($this->app, 'wabridge_app_key') && Secrets::has($this->app, 'wabridge_auth_key') && trim((string) $this->app->setting('wa_device_id', '')) !== '';
    }

    public function live(): bool
    {
        return (!$this->app->isLocal() || self::$transport !== null) && $this->app->setting('wa_live', '0') === '1' && $this->configured();
    }

    /** An Indian mobile as 91XXXXXXXXXX, or null. */
    public static function number(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        return preg_match('/^[6-9]\d{9}$/', $d) ? '91' . $d : null;
    }

    /** @return array{0: ?array, 1: ?string} the JSON reply, or an error */
    private function call(string $endpoint, array $payload): array
    {
        $payload = ['app-key' => (string) Secrets::get($this->app, 'wabridge_app_key'), 'auth-key' => (string) Secrets::get($this->app, 'wabridge_auth_key'),
            'device_id' => trim((string) $this->app->setting('wa_device_id', ''))] + $payload;
        if (self::$transport) {
            [$code, $body] = (self::$transport)($endpoint, $payload);
        } else {
            $ch = curl_init(self::BASE . $endpoint);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 0) {
                return [null, 'WABridge could not be reached: ' . mb_substr(curl_error($ch), 0, 150)];
            }
        }
        $j = json_decode((string) $body, true);
        if (!is_array($j) || empty($j['status'])) {
            return [null, 'WABridge said: ' . mb_substr(is_array($j) ? (string) ($j['message'] ?? 'refused') : "HTTP $code", 0, 300)];
        }
        return [$j, null];
    }

    /** Fetch the approved templates and their WABridge IDs. @return array{0: int, 1: ?string} how many, error */
    public function syncTemplates(): array
    {
        if (!$this->configured()) {
            return [0, 'Enter the WABridge keys and the device ID first.'];
        }
        [$j, $err] = $this->call('gettemplate', ['limit' => 200]);
        if ($err) {
            return [0, $err];
        }
        $db = $this->app->db;
        $n = 0;
        $db->tx(function () use ($db, $j, &$n): void {
            $db->run('DELETE FROM whatsapp_templates');
            foreach ((array) ($j['data'] ?? []) as $t) {
                if (empty($t['name']) || empty($t['id'])) {
                    continue;
                }
                $body = '';
                foreach ((array) ($t['components'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'BODY') {
                        $body = (string) ($c['text'] ?? '');
                    }
                }
                $lang = strtolower(substr((string) ($t['language'] ?? 'en'), 0, 2));
                $db->run('INSERT INTO whatsapp_templates (name, language, template_id, status, category, body, synced_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), status = VALUES(status), category = VALUES(category), body = VALUES(body), synced_at = NOW()',
                    [mb_substr((string) $t['name'], 0, 80), $lang, mb_substr((string) $t['id'], 0, 40), strtoupper((string) ($t['status'] ?? '')), mb_substr((string) ($t['category'] ?? ''), 0, 20) ?: null, $body ?: null]);
                $n++;
            }
        });
        Audit::log('whatsapp_templates_synced', 'settings', null, ['templates' => $n]);
        return [$n, null];
    }

    public function templates(): array
    {
        return $this->app->db->all('SELECT * FROM whatsapp_templates ORDER BY name, language');
    }

    /** Queue a template message. Returns the queue id, or null when there is no usable number. */
    public function enqueue(?string $mobile, string $template, array $variables, array $buttonVars = [], ?string $relatedType = null, ?int $relatedId = null, string $language = 'en', ?int $candidateId = null, ?string $notBefore = null): ?int
    {
        $to = self::number($mobile);
        if (!$to) {
            return null;
        }
        return $this->app->db->insert('whatsapp_queue', ['to_number' => $to, 'template' => $template, 'language' => $language, 'candidate_id' => $candidateId,
            'not_before' => $notBefore ?? date('Y-m-d H:i:s'),
            'variables' => json_encode(array_map(fn ($v) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $v)), 0, 300), array_values($variables)), JSON_UNESCAPED_UNICODE),
            'button_vars' => $buttonVars ? json_encode(array_values(array_map('strval', $buttonVars)), JSON_UNESCAPED_UNICODE) : null,
            'related_type' => $relatedType, 'related_id' => $relatedId]);
    }

    /** For a candidate: their phone, unless they are not to be contacted or turned WhatsApp off. Checked again on sending. */
    public function toCandidate(int $candidateId, string $template, array $variables, ?string $relatedType = null, ?int $relatedId = null, ?string $notBefore = null, array $buttonVars = []): ?int
    {
        $c = $this->app->db->one('SELECT id, phone, do_not_contact, removal_requested_at, whatsapp_off FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c || Contact::silenced($c) || !empty($c['whatsapp_off'])) {
            return null;
        }
        return $this->enqueue($c['phone'], $template, $variables, $buttonVars, $relatedType, $relatedId, 'en', $candidateId, $notBefore);
    }

    /** Send what is waiting (the scheduled job). */
    public function processQueue(int $max = 40): string
    {
        $db = $this->app->db;
        // Last-moment check: anyone since marked do not contact, asking to be removed, or with WhatsApp off gets nothing.
        $db->run("UPDATE whatsapp_queue q JOIN candidates c ON c.id = q.candidate_id SET q.status = 'cancelled', q.last_error = 'Do not contact, removal asked, or WhatsApp turned off'
            WHERE q.status IN ('queued','held') AND (NOT (" . Contact::sql('c') . ') OR c.whatsapp_off = 1)');
        $rows = $db->all("SELECT * FROM whatsapp_queue WHERE status = 'queued' AND attempts < 5 AND not_before <= NOW() ORDER BY id LIMIT " . (int) $max);
        if (!$rows) {
            return '';
        }
        $live = $this->live();
        $sent = $held = $failed = 0;
        foreach ($rows as $r) {
            if (!$live) {
                $dir = $this->app->storage('wa-outbox');
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/' . date('Ymd-His') . '-' . $r['id'] . '.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $db->run("UPDATE whatsapp_queue SET status = 'outbox', sent_at = NOW() WHERE id = ?", [$r['id']]);
                continue;
            }
            $t = $db->one("SELECT * FROM whatsapp_templates WHERE name = ? AND language = ? AND status = 'APPROVED'", [$r['template'], $r['language']])
                ?? $db->one("SELECT * FROM whatsapp_templates WHERE name = ? AND status = 'APPROVED' ORDER BY language = 'en' DESC LIMIT 1", [$r['template']]);
            if (!$t) {
                $db->run("UPDATE whatsapp_queue SET status = 'held', last_error = ? WHERE id = ?", ['The template "' . $r['template'] . '" is not approved yet (or not synced). Sync templates on Settings once Meta approves it.', $r['id']]);
                $held++;
                continue;
            }
            [$j, $err] = $this->call('createmessage', ['destination_number' => $r['to_number'], 'template_id' => $t['template_id'],
                'variables' => json_decode((string) $r['variables'], true) ?: [], 'button_variable' => json_decode((string) ($r['button_vars'] ?? '[]'), true) ?: [], 'media' => '', 'message' => '']);
            if ($err) {
                $db->run("UPDATE whatsapp_queue SET attempts = attempts + 1, last_error = ?, status = IF(attempts + 1 >= 5, 'failed', 'queued') WHERE id = ?", [mb_substr($err, 0, 500), $r['id']]);
                $failed++;
                continue;
            }
            $db->run("UPDATE whatsapp_queue SET status = 'sent', sent_at = NOW(), message_id = ?, last_error = NULL WHERE id = ?", [mb_substr((string) ($j['data']['messageid'] ?? ''), 0, 80) ?: null, $r['id']]);
            $sent++;
        }
        return $live ? "$sent sent" . ($held ? ", $held waiting for an approved template" : '') . ($failed ? ", $failed failed" : '') : count($rows) . ' written to the outbox (not live)';
    }

    /** Messages held for a template that is now approved go back in the queue. */
    public function release(): int
    {
        return $this->app->db->run("UPDATE whatsapp_queue q JOIN whatsapp_templates t ON t.name = q.template AND t.status = 'APPROVED' SET q.status = 'queued', q.last_error = NULL
            WHERE q.status = 'held' AND q.created_at >= NOW() - INTERVAL 3 DAY")->rowCount();
    }

    /** Ask WABridge how the last few days' messages fared (delivered, read, failed). */
    public function pollDelivery(int $max = 60): string
    {
        if (!$this->live()) {
            return '';
        }
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all("SELECT id, message_id FROM whatsapp_queue WHERE status = 'sent' AND message_id IS NOT NULL AND sent_at >= NOW() - INTERVAL 3 DAY
            AND (dlr_status IS NULL OR dlr_status NOT IN ('" . implode("','", self::FINAL) . "')) ORDER BY id LIMIT " . (int) $max) as $r) {
            [$j, $err] = $this->call('getdlr', ['messageid' => $r['message_id']]);
            if ($err) {
                continue;
            }
            $st = strtolower(mb_substr((string) ($j['data']['status'] ?? ''), 0, 20));
            $db->run('UPDATE whatsapp_queue SET dlr_status = ?, dlr_at = NOW(), last_error = ? WHERE id = ?', [$st ?: null, mb_substr(trim((string) ($j['data']['error_description'] ?? '')), 0, 500) ?: null, $r['id']]);
            $n++;
        }
        return $n ? "$n delivery reports" : '';
    }

    public function recent(int $limit = 30): array
    {
        return $this->app->db->all('SELECT id, CONCAT(LEFT(to_number, 4), "xxxxx", RIGHT(to_number, 3)) AS to_masked, template, related_type, status, dlr_status, last_error, created_at, sent_at
            FROM whatsapp_queue ORDER BY id DESC LIMIT ' . (int) $limit);
    }
}
