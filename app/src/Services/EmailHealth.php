<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * Bounces and complaints (B1, 14 Nov 2026). Brevo calls the app's webhook when an email bounces, is
 * blocked, marked as spam or unsubscribed. The app records it on the email and on the candidate, and
 * stops emailing an address that can't or shouldn't get email:
 *  - hard bounce, blocked, spam complaint, unsubscribed: never emailed again (until you clear it);
 *  - soft bounce (mailbox full, server busy): fine once or twice; 3 in 30 days counts as a hard bounce.
 * The webhook's address carries a long secret, so nobody else can post to it.
 */
final class EmailHealth
{
    /** Brevo's event names (both its spellings) => ours. Delivery events are kept on the email only. */
    public const EVENTS = [
        'hard_bounce' => 'hard_bounce', 'hardbounce' => 'hard_bounce', 'soft_bounce' => 'soft_bounce', 'softbounce' => 'soft_bounce',
        'blocked' => 'blocked', 'invalid_email' => 'hard_bounce', 'invalid' => 'hard_bounce', 'spam' => 'complained', 'complaint' => 'complained',
        'unsubscribed' => 'unsubscribed', 'unsubscribe' => 'unsubscribed', 'delivered' => 'delivered', 'deferred' => 'deferred',
    ];
    /** Statuses that stop all email to the address. */
    public const STOP = ['hard_bounce', 'blocked', 'complained', 'unsubscribed'];
    public const LABELS = ['ok' => 'Email working', 'soft_bounce' => 'Email bounced once (mailbox full or server busy)', 'hard_bounce' => 'Email address does not work',
        'blocked' => 'Email blocked by Brevo', 'complained' => 'Marked our email as spam', 'unsubscribed' => 'Unsubscribed from our emails'];

    public function __construct(private readonly App $app)
    {
    }

    /** The secret in the webhook's address, made the first time it is needed. */
    public function token(): string
    {
        $t = (string) $this->app->setting('brevo_webhook_token', '');
        if (strlen($t) < 32) {
            $t = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
            $this->app->db->run("INSERT INTO settings (k, v) VALUES ('brevo_webhook_token', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$t]);
        }
        return $t;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/hooks/brevo/' . $this->token();
    }

    public function validToken(string $t): bool
    {
        $mine = (string) $this->app->setting('brevo_webhook_token', '');
        return strlen($mine) >= 32 && hash_equals($mine, $t);
    }

    /**
     * One event from Brevo (or a list of them). Returns how many were recorded.
     * @param array $payload Brevo's JSON: event, email, message-id, reason, ts_event / date
     */
    public function record(array $payload): int
    {
        if (array_is_list($payload)) {
            return array_sum(array_map(fn ($p) => is_array($p) ? $this->record($p) : 0, $payload));
        }
        $event = self::EVENTS[strtolower(str_replace([' ', '-'], '_', (string) ($payload['event'] ?? '')))] ?? null;
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        if (!$event || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $db = $this->app->db;
        $pid = trim((string) ($payload['message-id'] ?? $payload['message_id'] ?? ''));
        $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 255) ?: null;
        $ts = isset($payload['ts_event']) && is_numeric($payload['ts_event']) ? (int) $payload['ts_event'] : (strtotime((string) ($payload['date'] ?? '')) ?: time());
        $at = date('Y-m-d H:i:s', min($ts, time()));
        $mail = $pid !== '' ? $db->one('SELECT id, candidate_id FROM email_queue WHERE provider_id = ? LIMIT 1', [$pid]) : null;
        $cid = $mail['candidate_id'] ?? $db->col('SELECT id FROM candidates WHERE email_norm = ? LIMIT 1', [$email]) ?: null;
        // Brevo may send the same event twice: once is enough.
        if ($db->col('SELECT 1 FROM email_events WHERE to_email = ? AND event = ? AND event_at = ? AND COALESCE(provider_id, \'\') = ? LIMIT 1', [$email, $event, $at, $pid])) {
            return 0;
        }
        $db->insert('email_events', ['email_id' => $mail['id'] ?? null, 'candidate_id' => $cid, 'to_email' => $email, 'event' => $event, 'reason' => $reason,
            'provider_id' => $pid !== '' ? mb_substr($pid, 0, 190) : null, 'event_at' => $at]);
        if ($mail) {
            $db->run('UPDATE email_queue SET delivery = ?, delivery_at = ? WHERE id = ?', [$event, $at, $mail['id']]);
        }
        if (!in_array($event, ['delivered', 'deferred'], true)) {
            $status = $event;
            if ($event === 'soft_bounce' && (int) $db->col("SELECT COUNT(*) FROM email_events WHERE to_email = ? AND event = 'soft_bounce' AND event_at > NOW() - INTERVAL 30 DAY", [$email]) >= 3) {
                $status = 'hard_bounce';
                $reason = 'Bounced 3 times in 30 days' . ($reason ? ": $reason" : '');
            }
            $this->setStatus($email, $status, $reason, $at);
        }
        return 1;
    }

    /** Puts a status on every candidate with this address. A softer status never replaces a stopping one. */
    private function setStatus(string $email, string $status, ?string $note, string $at): void
    {
        $db = $this->app->db;
        foreach ($db->all('SELECT id, email_status FROM candidates WHERE email_norm = ?', [$email]) as $c) {
            if ($status === 'soft_bounce' && in_array($c['email_status'], self::STOP, true)) {
                continue;
            }
            $db->update('candidates', ['email_status' => $status, 'email_status_at' => $at, 'email_status_note' => $note ? mb_substr($note, 0, 255) : null], (int) $c['id']);
            Audit::log('email_status', 'candidate', (int) $c['id'], ['status' => $status]);
            if (in_array($status, ['complained', 'unsubscribed'], true)) {
                (new Consents($this->app))->record((int) $c['id'], 'withdrawn', $status === 'complained' ? 'spam_complaint' : 'unsubscribed');
            }
        }
    }

    /** One-click unsubscribe (14 Nov 2026): the link for a candidate, carried in the List-Unsubscribe header. */
    public static function unsubscribeUrl(App $app, int $candidateId): string
    {
        return rtrim((string) ($app->config['app']['url'] ?? ''), '/') . '/unsub/' . $candidateId . '-' . \Eshcol\Ats\Secrets::mac($app, (string) $candidateId, 'unsub');
    }

    /** The candidate an unsubscribe token belongs to, or null if it was made up. */
    public static function unsubscribeCandidate(App $app, string $token): ?int
    {
        if (!preg_match('/^(\d{1,10})-([A-Za-z0-9_-]{22})$/', $token, $m)) {
            return null;
        }
        return hash_equals(\Eshcol\Ats\Secrets::mac($app, $m[1], 'unsub'), $m[2]) ? (int) $m[1] : null;
    }

    /** They pressed unsubscribe: no more email to that address (as a Brevo unsubscribe), anything waiting cancelled. */
    public function unsubscribe(int $candidateId): bool
    {
        $db = $this->app->db;
        $email = (string) $db->col('SELECT email_norm FROM candidates WHERE id = ?', [$candidateId]);
        if ($email === '') {
            return false;
        }
        $this->setStatus($email, 'unsubscribed', 'Pressed unsubscribe in our email', date('Y-m-d H:i:s'));
        $db->run("UPDATE email_queue SET status = 'cancelled' WHERE candidate_id = ? AND status IN ('pending','held')", [$candidateId]);
        return true;
    }

    /** Why an address must not be emailed, or null. For the queue's last check before sending. */
    public function stopReason(string $email, ?int $candidateId): ?string
    {
        $db = $this->app->db;
        $s = $candidateId ? $db->col('SELECT email_status FROM candidates WHERE id = ?', [$candidateId]) : null;
        if (!$s || $s === 'ok' || $s === 'soft_bounce') {
            $s = $db->col("SELECT email_status FROM candidates WHERE email_norm = ? AND email_status IN ('hard_bounce','blocked','complained','unsubscribed') LIMIT 1", [mb_strtolower($email)]) ?: $s;
        }
        return $s && in_array($s, self::STOP, true) ? self::LABELS[$s] : null;
    }

    /** You confirm the address works again (they replied, or gave a new one): emails go to it again. */
    public function clear(int $candidateId): void
    {
        $this->app->db->update('candidates', ['email_status' => 'ok', 'email_status_at' => date('Y-m-d H:i:s'), 'email_status_note' => 'Cleared by you'], $candidateId);
        Audit::log('email_status_cleared', 'candidate', $candidateId);
    }

    /** Counts for Settings › Email: the last 30 days, and addresses stopped now. */
    public function summary(): array
    {
        $db = $this->app->db;
        $ev = array_column($db->all('SELECT event, COUNT(*) n FROM email_events WHERE event_at > NOW() - INTERVAL 30 DAY GROUP BY event'), 'n', 'event');
        $sent = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at > NOW() - INTERVAL 30 DAY AND provider_id NOT LIKE 'outbox:%'");
        $hard = (int) ($ev['hard_bounce'] ?? 0) + (int) ($ev['blocked'] ?? 0);
        return ['events' => $ev, 'sent' => $sent, 'bounce_rate' => $sent ? round(100 * $hard / $sent, 1) : null,
            'stopped' => (int) $db->col("SELECT COUNT(*) FROM candidates WHERE email_status IN ('hard_bounce','blocked','complained','unsubscribed')"),
            'connected' => $this->app->setting('brevo_webhook_connected'), 'last_event' => $db->col('SELECT MAX(created_at) FROM email_events')];
    }

    /**
     * Asks Brevo to call the webhook for these events (Settings › Email › "Connect Brevo"). Uses the Brevo key
     * the app already has. Returns a problem, or null.
     */
    public function connectBrevo(): ?string
    {
        $key = Secrets::get($this->app, 'brevo_api_key');
        if (!$key) {
            return 'No Brevo key is saved yet (Settings › Email).';
        }
        $url = $this->webhookUrl();
        if (!str_starts_with($url, 'https://')) {
            return 'The app\'s address must start with https:// for Brevo to call it.';
        }
        $ch = curl_init('https://api.brevo.com/v3/webhooks');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'description' => 'Eshcol Recruit: bounces and complaints', 'type' => 'transactional',
                'events' => ['hardBounce', 'softBounce', 'blocked', 'spam', 'invalid', 'unsubscribed', 'delivered']])]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) {
            $this->app->db->run("INSERT INTO settings (k, v) VALUES ('brevo_webhook_connected', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
            Audit::log('brevo_webhook_connected', null, null);
            return null;
        }
        return $code ? 'Brevo said ' . $code . ': ' . mb_substr((string) (json_decode($body, true)['message'] ?? $body), 0, 200) : 'Could not reach Brevo: ' . curl_error($ch);
    }
}
