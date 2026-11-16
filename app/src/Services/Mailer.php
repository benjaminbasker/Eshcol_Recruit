<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * Outgoing email. Everything is queued first (email_queue) and sent by the scheduled job,
 * most important first, never passing the daily ceiling.
 *
 * Two modes, switched in Settings:
 *  - test (the default): nothing leaves the server; each email is written to
 *    storage/outbox/ so you can look at it. Always the case on your own PC.
 *  - live: sent through Brevo, exactly as the website's forms are.
 */
final class Mailer
{
    /** Emails sent to many candidates at once: these carry one-click unsubscribe. */
    public const BULK = ['cv_request', 'questionnaire', 'ack'];

    public const PRIORITY = ['test' => 1, 'message' => 5, 'interview' => 10, 'questionnaire' => 30, 'ack' => 50, 'rejection' => 90, 'cv_request' => 95];

    /** @var callable|null replaces the real sending in tests: fn(array $row): string (provider id) or throws MailError */
    public $transport = null;

    public function __construct(private readonly App $app)
    {
    }

    /** Brevo refused the key: [when, message], or null. */
    public function keyProblem(): ?array
    {
        $v = $this->app->setting('mail_key_problem');
        return $v ? explode('|', $v, 2) + [1 => ''] : null;
    }

    public function live(): bool
    {
        return !$this->app->isLocal() && $this->app->setting('mail_live', '0') === '1' && Secrets::has($this->app, 'brevo_api_key');
    }

    public function dailyCap(): int
    {
        return max(1, (int) $this->app->setting('mail_daily_cap', '250'));
    }

    public function sentToday(): int
    {
        return (int) $this->app->db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= CURDATE()");
    }

    /** @param array{kind:string,to_email:string,subject:string,html?:?string,text?:?string,to_name?:?string,candidate_id?:?int,meta?:array,priority?:int,status?:string,hold_reason?:?string,not_before?:string,created_by?:?int} $m */
    /**
     * Emails to clients (16 Nov 2026, Benjamin: "add benjamin@eshcolglobal.com as CC for every mail sent to the client"):
     * the shortlist email and the interview emails to the client. Not the portal sign-in link: whoever clicks it is
     * signed in as the client, so it goes to the client alone.
     */
    public const CLIENT_KINDS = ['shortlist', 'client_interview'];
    public const CLIENT_CC_DEFAULT = 'benjamin@eshcolglobal.com';

    /** The address copied on every client email (setting client_cc; '' to turn it off). */
    public function clientCc(): string
    {
        return trim((string) $this->app->setting('client_cc', self::CLIENT_CC_DEFAULT));
    }

    public function enqueue(array $m): int
    {
        $meta = $m['meta'] ?? null;
        if (in_array($m['kind'], self::CLIENT_KINDS, true) && ($cc = $this->clientCc()) !== '') {
            $meta = is_array($meta) ? $meta : [];
            $meta['cc'] = trim(($meta['cc'] ?? '') . ', ' . $cc, ', ');
        }
        return $this->app->db->insert('email_queue', [
            'kind' => $m['kind'],
            'candidate_id' => $m['candidate_id'] ?? null,
            'to_email' => mb_strtolower(trim($m['to_email'])),
            'to_name' => $m['to_name'] ?? null,
            'subject' => mb_substr($m['subject'], 0, 255),
            'html' => $m['html'] ?? null,
            'text' => $m['text'] ?? null,
            'meta' => $meta === null ? null : json_encode($meta),
            'priority' => $m['priority'] ?? (self::PRIORITY[$m['kind']] ?? 50),
            'status' => $m['status'] ?? 'pending',
            'hold_reason' => $m['hold_reason'] ?? null,
            'not_before' => $m['not_before'] ?? date('Y-m-d H:i:s'),
            'created_by' => $m['created_by'] ?? null,
        ]);
    }

    /** Sends what is due, within today's allowance. Returns a one-line summary for the log. */
    public function processQueue(int $max = 40): string
    {
        $db = $this->app->db;
        $room = min($max, $this->dailyCap() - $this->sentToday());
        if ($room <= 0) {
            $waiting = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'");
            return "daily ceiling reached; $waiting waiting for tomorrow";
        }
        // Key refused earlier? Try just one email an hour until it works again (a new key, or the IP authorised).
        $problem = $this->keyProblem();
        if ($problem && strtotime($problem[0]) > time() - 3600 && $this->transport === null) {
            return '';
        }
        $rows = $db->all("SELECT * FROM email_queue WHERE status = 'pending' AND not_before <= NOW() ORDER BY priority, id LIMIT " . (int) ($problem ? 1 : $room));
        if (!$rows) {
            return ''; // nothing due: say nothing, so the log stays readable
        }
        $sent = $failed = 0;
        foreach ($rows as $row) {
            // A thank-you email waits (up to an hour) while Claude is still reading the CV, so the
            // details page shows what was read. The 5-minute job reads CVs first, so this is rare.
            if ($row['kind'] === 'ack' && $row['candidate_id'] && strtotime((string) $row['created_at']) > time() - 3600
                && $db->col("SELECT 1 FROM cv_ai_reads WHERE candidate_id = ? AND mode = 'auto' AND status IN ('pending','reading')", [$row['candidate_id']])) {
                $db->run('UPDATE email_queue SET not_before = NOW() + INTERVAL 5 MINUTE WHERE id = ?', [$row['id']]);
                continue;
            }
            $this->sendRow($row) ? $sent++ : $failed++;
            if ($this->keyProblem()) {
                return 'paused: Brevo refused the API key; ' . (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'") . ' waiting';
            }
        }
        return "$sent sent" . ($failed ? ", $failed failed" : '') . ($this->live() ? '' : ' (test mode: written to storage/outbox)');
    }

    /** Sends one queued email now. */
    public function sendRow(array $row): bool
    {
        $db = $this->app->db;
        // Last-moment checks: nobody marked do-not-contact gets anything but a test.
        if ($row['candidate_id'] && $row['kind'] !== 'test'
            && $db->col('SELECT NOT (' . Contact::sql('c') . ') FROM candidates c WHERE c.id = ?', [$row['candidate_id']])) {
            $db->run("UPDATE email_queue SET status = 'cancelled', last_error = 'Candidate is marked do not contact, or asked us not to keep their details' WHERE id = ?", [$row['id']]);
            return false;
        }
        // An address that bounced for good, was blocked, complained or unsubscribed gets nothing more (B1, 14 Nov 2026).
        if ($row['kind'] !== 'test' && ($stop = (new EmailHealth($this->app))->stopReason((string) $row['to_email'], $row['candidate_id'] ? (int) $row['candidate_id'] : null))) {
            $db->run("UPDATE email_queue SET status = 'cancelled', last_error = ? WHERE id = ?", ["Not sent: $stop", $row['id']]);
            return false;
        }
        try {
            $providerId = $this->transport ? ($this->transport)($row) : ($this->live() ? $this->viaBrevo($row) : $this->toOutbox($row));
            $db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW(), provider_id = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?", [$providerId, $row['id']]);
            if (!str_starts_with($providerId, 'outbox:')) {
                // Brevo took it: the key works (and is "in use", which keeps Brevo from switching it off after 90 idle days).
                $db->run("DELETE FROM settings WHERE k = 'mail_key_problem'");
                $db->run("INSERT INTO settings (k, v) VALUES ('mail_last_brevo_ok', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
            }
            return true;
        } catch (MailError $e) {
            if ($e->keyProblem) {
                // Not the email's fault: keep it queued, and say so loudly (dashboard + Settings).
                $db->run('UPDATE email_queue SET last_error = ?, not_before = NOW() + INTERVAL 5 MINUTE WHERE id = ?', [mb_substr($e->getMessage(), 0, 255), $row['id']]);
                $db->run("INSERT INTO settings (k, v) VALUES ('mail_key_problem', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [date('Y-m-d H:i:s') . '|' . mb_substr($e->getMessage(), 0, 200)]);
                Audit::log('email_key_refused', 'email', (int) $row['id']);
                return false;
            }
            $attempts = (int) $row['attempts'] + 1;
            $giveUp = $e->permanent || $attempts >= 5;
            // Back off: 10 min, 40 min, 1.5 h, 2.7 h.
            $db->run('UPDATE email_queue SET attempts = ?, last_error = ?, status = ?, not_before = NOW() + INTERVAL ? MINUTE WHERE id = ?',
                [$attempts, mb_substr($e->getMessage(), 0, 255), $giveUp ? 'failed' : 'pending', 10 * $attempts * $attempts, $row['id']]);
            if ($giveUp) {
                Audit::log('email_failed', 'email', (int) $row['id'], ['kind' => $row['kind']]);
            }
            return false;
        }
    }

    private function viaBrevo(array $row): string
    {
        $key = Secrets::get($this->app, 'brevo_api_key');
        $from = (string) $this->app->setting('mail_from_email', 'resume@eshcolglobal.com');
        $payload = [
            'sender' => ['name' => (string) $this->app->setting('mail_from_name', 'Eshcol Global'), 'email' => $from],
            'to' => [array_filter(['email' => $row['to_email'], 'name' => $row['to_name']])],
            'replyTo' => ['email' => $from],
            'subject' => $row['subject'],
            'htmlContent' => $row['html'],
            'textContent' => $row['text'],
            // RFC 3834: tells autoresponders not to reply, so two machines never loop. A personal
            // message is written by a person, and carries the thread headers of the email it answers.
            // Left out when there are none: Brevo refuses an empty list ("Invalid headers").
            'headers' => $this->headersFor($row) ?: null,
            'tags' => ['recruit-' . $row['kind']],
        ];
        $cc = self::ccList($row);
        if ($cc) {
            $payload['cc'] = array_map(fn ($e) => ['email' => $e], $cc);
        }
        $files = $this->attachments($row);
        if ($files) {
            $payload['attachment'] = array_map(fn ($a) => ['name' => $a['name'], 'content' => base64_encode($a['body'])], $files);
        }
        $post = function (array $payload) use ($key): array {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
                CURLOPT_POSTFIELDS => self::brevoJson($payload)]);
            return [(string) curl_exec($ch), (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch)];
        };
        [$body, $code, $err] = $post($payload);
        // Brevo refusing our extra headers (e.g. the thread headers of a reply) must not stop the email: send it without them.
        if ($code === 400 && !empty($payload['headers']) && stripos($body, 'header') !== false) {
            unset($payload['headers']['In-Reply-To'], $payload['headers']['References'], $payload['headers']['List-Unsubscribe'], $payload['headers']['List-Unsubscribe-Post']);
            $payload['headers'] = $payload['headers'] ?: null;
            [$body, $code, $err] = $post($payload);
        }
        if ($code >= 200 && $code < 300) {
            return (string) (json_decode($body, true)['messageId'] ?? 'brevo');
        }
        $msg = $code ? "Brevo said $code: " . mb_substr((string) (json_decode($body, true)['message'] ?? $body), 0, 160) : "Could not reach Brevo: $err";
        // 401/403 = the key (or this server's IP) is refused: pause the queue, the emails are fine.
        // 400 = bad address or content: retrying won't help.
        throw new MailError($msg, $code === 400, in_array($code, [401, 403], true));
    }

    /** The JSON Brevo receives: empty or missing parts left out (an empty list for "headers" is refused). */
    public static function brevoJson(array $payload): string
    {
        return json_encode(array_filter($payload, fn ($v) => $v !== null && $v !== []));
    }

    /** Extra email headers: automatic ones say so; a personal reply threads to the message it answers. */
    public function headersFor(array $row): array
    {
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        $h = !empty($meta['personal']) ? [] : ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'OOF, AutoReply'];
        // Bulk emails to candidates carry one-click unsubscribe (RFC 8058), which Gmail and Yahoo expect from bulk senders.
        if (in_array($row['kind'] ?? '', self::BULK, true) && !empty($row['candidate_id'])) {
            $h['List-Unsubscribe'] = '<' . EmailHealth::unsubscribeUrl($this->app, (int) $row['candidate_id']) . '>';
            $h['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        foreach ((array) ($meta['headers'] ?? []) as $k => $v) {
            if (in_array($k, ['In-Reply-To', 'References'], true) && preg_match('/^<[^<>\s]{3,250}>$/', (string) $v)) {
                $h[$k] = (string) $v;
            }
        }
        return $h;
    }

    private function toOutbox(array $row): string
    {
        $dir = $this->app->storage('outbox');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $base = $dir . '/' . date('Ymd-His') . "-{$row['id']}-{$row['kind']}";
        $cc = self::ccList($row) ? ' | Cc: ' . implode(', ', self::ccList($row)) : '';
        file_put_contents("$base.html", "<!-- To: {$row['to_email']}$cc | Subject: " . htmlspecialchars($row['subject']) . " -->\n" . $row['html']);
        foreach ($this->attachments($row) as $i => $a) {
            file_put_contents("$base-att$i-" . preg_replace('/[^A-Za-z0-9._-]+/', '_', $a['name']), $a['body']);
        }
        if ($row['text'] !== null) {
            file_put_contents("$base.txt", "To: {$row['to_email']}\nSubject: {$row['subject']}\n\n" . $row['text']);
        }
        return 'outbox:' . basename($base);
    }

    /**
     * Files to attach (meta.attachments): each either {path, name} of a stored file, or {ics, name}
     * with calendar text. Missing files are skipped rather than failing the email.
     * @return array<int, array{name:string, body:string}>
     */
    public function attachments(array $row): array
    {
        $out = [];
        foreach ((array) (json_decode((string) ($row['meta'] ?? ''), true)['attachments'] ?? []) as $a) {
            if (isset($a['ics'])) {
                $out[] = ['name' => (string) ($a['name'] ?? 'invite.ics'), 'body' => (string) $a['ics']];
            } elseif (!empty($a['path']) && !str_contains((string) $a['path'], '..') && is_file($p = $this->app->storage($a['path']))) {
                $out[] = ['name' => (string) ($a['name'] ?? basename($p)), 'body' => (string) file_get_contents($p)];
            }
        }
        return $out;
    }

    /** Valid CC addresses stored with the email (meta.cc, comma-separated). */
    public static function ccList(array $row): array
    {
        $cc = (string) (json_decode((string) ($row['meta'] ?? ''), true)['cc'] ?? '');
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', mb_strtolower($cc))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && $e !== mb_strtolower((string) $row['to_email']))));
    }

    /** A cheap, free check that an address can receive mail: right shape, and its domain has mail servers. */
    public static function deliverable(string $email, ?callable $dns = null): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = substr(strrchr($email, '@'), 1);
        $dns ??= fn (string $d) => checkdnsrr($d . '.', 'MX') || checkdnsrr($d . '.', 'A');
        return (bool) $dns($domain);
    }
}
