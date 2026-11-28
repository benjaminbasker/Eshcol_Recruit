<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * The WABridge webhook (28 Nov 2026): https://recruit.../hooks/wabridge/<secret>. WABridge's payload is not
 * documented, so every call is stored as it came and read the common shapes: Meta's own
 * (entry[].changes[].value.messages[] / statuses[]) and flat ones (from / mobile / sender, message / text / body).
 * A reply is matched to a candidate by the last 10 digits of the mobile; "STOP" turns their WhatsApp off.
 */
final class WaInbox
{
    private const STOP = ['stop', 'unsubscribe', 'stop all', 'opt out', 'optout'];

    public function __construct(private readonly App $app)
    {
    }

    public function token(): string
    {
        $t = (string) $this->app->setting('wabridge_webhook_token', '');
        if (strlen($t) < 32) {
            $t = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
            $this->app->db->run("INSERT INTO settings (k, v) VALUES ('wabridge_webhook_token', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$t]);
        }
        return $t;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/hooks/wabridge/' . $this->token();
    }

    public function validToken(string $t): bool
    {
        $mine = (string) $this->app->setting('wabridge_webhook_token', '');
        return strlen($mine) >= 32 && hash_equals($mine, $t);
    }

    /** Store one webhook call. Returns how many rows were recorded (a Meta-style call can hold several). */
    public function record(string $raw): int
    {
        $p = json_decode($raw, true);
        if (!is_array($p)) {
            parse_str($raw, $p); // form-encoded, just in case
        }
        $items = is_array($p) && $p ? $this->items($p) : [];
        if (!$items) {
            $items = [['kind' => 'unknown', 'from' => null, 'body' => null, 'id' => null]];
        }
        $db = $this->app->db;
        $n = 0;
        foreach ($items as $i => $it) {
            $from = FieldExtractor::normalisePhone($it['from'] ?? null);
            $cand = $from ? $db->col('SELECT id FROM candidates WHERE phone_norm = ? ORDER BY updated_at DESC LIMIT 1', [$from]) : null;
            $body = $it['body'] !== null ? mb_substr(trim((string) $it['body']), 0, 1000) : null;
            $db->insert('whatsapp_inbox', ['kind' => $it['kind'], 'from_number' => $from, 'candidate_id' => $cand ?: null, 'body' => $body,
                'message_id' => $it['id'] !== null ? mb_substr((string) $it['id'], 0, 120) : null, 'payload' => $i === 0 ? mb_substr($raw, 0, 200000) : null]);
            $n++;
            if ($it['kind'] === 'status' && $it['id'] !== null && $body) {
                $db->run('UPDATE whatsapp_queue SET dlr_status = ?, dlr_at = NOW() WHERE message_id = ?', [mb_substr(strtolower($body), 0, 20), (string) $it['id']]);
            }
            if ($cand && in_array($it['kind'], ['text', 'button'], true) && in_array(strtolower(trim((string) $body, " .!\t\n")), self::STOP, true)) {
                $db->run('UPDATE candidates SET whatsapp_off = 1 WHERE id = ?', [$cand]);
                $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $cand, 'body' => 'Replied STOP on WhatsApp: WhatsApp messages turned off.']);
            }
        }
        $db->run('DELETE FROM whatsapp_inbox WHERE received_at < NOW() - INTERVAL 1 YEAR');
        return $n;
    }

    /** @return list<array{kind: string, from: ?string, body: ?string, id: ?string}> */
    private function items(array $p): array
    {
        $out = [];
        // Meta's shape (WABridge may pass it on as it is)
        foreach ((array) ($p['entry'] ?? []) as $e) {
            foreach ((array) ($e['changes'] ?? []) as $c) {
                $v = (array) ($c['value'] ?? []);
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    $out[] = $this->message((array) $m);
                }
                foreach ((array) ($v['statuses'] ?? []) as $s) {
                    $out[] = ['kind' => 'status', 'from' => $s['recipient_id'] ?? null, 'body' => isset($s['status']) ? (string) $s['status'] : null, 'id' => $s['id'] ?? null];
                }
            }
        }
        if ($out) {
            return $out;
        }
        // Flat shapes, possibly wrapped in "data"
        $d = isset($p['data']) && is_array($p['data']) ? $p['data'] + $p : $p;
        if (isset($d['messages']) && is_array($d['messages'])) {
            foreach ($d['messages'] as $m) {
                is_array($m) && $out[] = $this->message($m);
            }
            return $out;
        }
        $from = $d['from'] ?? $d['mobile'] ?? $d['sender'] ?? $d['phone'] ?? $d['wa_id'] ?? $d['source_number'] ?? $d['number'] ?? null;
        $text = $d['button_text'] ?? $d['button'] ?? $d['message'] ?? $d['text'] ?? $d['body'] ?? $d['content'] ?? null;
        if (is_array($text)) {
            $text = $text['body'] ?? $text['text'] ?? $text['title'] ?? null;
        }
        $status = $d['status'] ?? $d['dlr_status'] ?? null;
        $isButton = isset($d['button_text']) || isset($d['button']) || ($d['type'] ?? '') === 'button' || ($d['type'] ?? '') === 'interactive';
        if ($text !== null || $from !== null) {
            $out[] = ['kind' => $text !== null ? ($isButton ? 'button' : 'text') : (is_string($status) ? 'status' : 'unknown'),
                'from' => is_scalar($from) ? (string) $from : null, 'body' => is_scalar($text) ? (string) $text : (is_string($status) ? $status : null),
                'id' => isset($d['messageid']) ? (string) $d['messageid'] : (isset($d['message_id']) ? (string) $d['message_id'] : (isset($d['id']) && is_scalar($d['id']) ? (string) $d['id'] : null))];
        }
        return $out;
    }

    private function message(array $m): array
    {
        $body = $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? $m['text']['body'] ?? (is_string($m['text'] ?? null) ? $m['text'] : null);
        $kind = isset($m['button']) || isset($m['interactive']) ? 'button' : (isset($m['text']) ? 'text' : (string) ($m['type'] ?? 'unknown'));
        return ['kind' => mb_substr($kind, 0, 20), 'from' => isset($m['from']) ? (string) $m['from'] : null, 'body' => $body !== null ? (string) $body : null, 'id' => isset($m['id']) ? (string) $m['id'] : null];
    }

    public function recent(int $limit = 25): array
    {
        return $this->app->db->all('SELECT i.id, i.received_at, i.kind, i.body, i.candidate_id, c.full_name,
            CASE WHEN i.from_number IS NULL THEN NULL ELSE CONCAT(LEFT(i.from_number, 2), "xxxxx", RIGHT(i.from_number, 3)) END AS from_masked
            FROM whatsapp_inbox i LEFT JOIN candidates c ON c.id = i.candidate_id WHERE i.kind <> "status" ORDER BY i.id DESC LIMIT ' . (int) $limit);
    }
}
