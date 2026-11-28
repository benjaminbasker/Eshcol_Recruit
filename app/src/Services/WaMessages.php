<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * The WhatsApp messages Benjamin starts or a stage change starts (28 Nov 2026), through WaBridge:
 * - call_request (he proposes a call; the "No longer interested" button opens /n/<token>, which withdraws them);
 * - missed_call;
 * - selected (stage "Offer made") and not_selected (stage "Client rejected"), each switchable in Settings › WhatsApp;
 * - joining_reminder, the evening before the joining date (scheduled job).
 */
final class WaMessages
{
    public function __construct(private readonly App $app)
    {
    }

    private function wa(): WaBridge
    {
        return new WaBridge($this->app);
    }

    /** The application with what the messages need, or null. */
    private function app(int $appId): ?array
    {
        $a = $this->app->db->one('SELECT a.id, a.candidate_id, a.role_id, a.stage_id, c.full_name, c.first_name, r.title, r.engagement, cl.name AS client_name, cl.legal_name
            FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id LEFT JOIN clients cl ON cl.id = r.client_id WHERE a.id = ?', [$appId]);
        if ($a) {
            $a['first'] = Questionnaires::firstName($a) ?: 'there';
            $a['client_display'] = Engagement::of($a) === 'inhouse' ? Engagement::NAME : ($a['legal_name'] ?: ($a['client_name'] ?: Engagement::NAME));
        }
        return $a;
    }

    // ---- "No longer interested" link ----

    public function noLongerToken(int $appId): string
    {
        return $appId . '-' . Secrets::mac($this->app, (string) $appId, 'no-longer-interested');
    }

    public function appFromToken(string $token): ?int
    {
        if (!preg_match('/^(\d+)-([A-Za-z0-9_-]{22})$/', $token, $m) || !hash_equals(Secrets::mac($this->app, $m[1], 'no-longer-interested'), $m[2])) {
            return null;
        }
        return (int) $m[1];
    }

    /** The candidate said they're no longer interested: the application moves to Withdrew, with a note. */
    public function withdraw(int $appId): ?string
    {
        $db = $this->app->db;
        $a = $db->one('SELECT * FROM applications WHERE id = ?', [$appId]);
        if (!$a) {
            return 'This link is no longer valid.';
        }
        $to = Stages::id($this->app, Stages::WITHDREW);
        if (!$to) {
            return 'Something went wrong. Please reply on WhatsApp instead.';
        }
        if ((int) $a['stage_id'] !== $to) {
            $owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
            (new StageMover($this->app))->move($a, $to, 'Said they are no longer interested (WhatsApp link)', $owner, false);
            $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => null,
                'body' => 'Said they are no longer interested in ' . $db->col('SELECT title FROM roles WHERE id = ?', [$a['role_id']]) . ' (WhatsApp "No longer interested" link). Moved to Withdrew.']);
            Audit::log('candidate_no_longer_interested', 'candidate', (int) $a['candidate_id'], ['application' => $appId]);
        }
        return null;
    }

    // ---- Started by Benjamin ----

    /** @return ?string an error */
    public function callRequest(int $appId, string $date, string $from, string $to): ?string
    {
        $a = $this->app($appId);
        if (!$a) {
            return 'Not found.';
        }
        $ts = strtotime($date);
        $f = strtotime("2000-01-01 $from");
        $t = strtotime("2000-01-01 $to");
        if (!$ts || !$f || !$t || $t <= $f || date('Y-m-d', $ts) < date('Y-m-d')) {
            return 'Choose a day from today and a time window (from before to).';
        }
        $id = $this->wa()->toCandidate((int) $a['candidate_id'], 'call_request', [$a['first'], $a['title'], date('l j F', $ts), date('g:i A', $f), date('g:i A', $t)],
            'call_request', $appId, null, [$this->noLongerToken($appId)]);
        if (!$id) {
            return 'Not sent: no usable mobile number, or they are marked do not contact or turned WhatsApp off.';
        }
        Audit::log('wa_call_request', 'candidate', (int) $a['candidate_id'], ['application' => $appId, 'on' => date('Y-m-d', $ts)]);
        return null;
    }

    public function missedCall(int $appId): ?string
    {
        $a = $this->app($appId);
        if (!$a) {
            return 'Not found.';
        }
        $id = $this->wa()->toCandidate((int) $a['candidate_id'], 'missed_call', [$a['first'], $a['title']], 'missed_call', $appId);
        if (!$id) {
            return 'Not sent: no usable mobile number, or they are marked do not contact or turned WhatsApp off.';
        }
        Audit::log('wa_missed_call', 'candidate', (int) $a['candidate_id'], ['application' => $appId]);
        return null;
    }

    // ---- Stage changes ----

    /** After a stage change: "Offer made" sends selected; "Client rejected" sends not_selected (each can be switched off). */
    public function afterStage(int $appId, int $to): void
    {
        $code = null;
        if (Stages::is($this->app, $to, Stages::OFFER) && $this->app->setting('wa_auto_selected', '1') === '1') {
            $code = 'selected';
        } elseif (Stages::is($this->app, $to, Stages::CLIENT_REJECTED) && $this->app->setting('wa_auto_not_selected', '1') === '1') {
            $code = 'not_selected';
        }
        if (!$code) {
            return;
        }
        $a = $this->app($appId);
        $c = $a ? $this->app->db->one('SELECT red_listed_at FROM candidates WHERE id = ?', [$a['candidate_id']]) : null;
        if (!$a || !empty($c['red_listed_at'])) {
            return;
        }
        if ($this->app->db->col('SELECT 1 FROM whatsapp_queue WHERE template = ? AND related_type = ? AND related_id = ?', [$code, 'stage', $appId])) {
            return; // once per application
        }
        $this->wa()->toCandidate((int) $a['candidate_id'], $code, [$a['first'], $a['title'], $a['client_display']], 'stage', $appId);
    }

    // ---- The scheduled job ----

    /** The evening before joining: joining_reminder, once per joining record. */
    public function joiningReminders(): string
    {
        $db = $this->app->db;
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $time = (string) $this->app->setting('joining_report_time', '9:00 AM');
        $n = 0;
        foreach ($db->all("SELECT * FROM joinings WHERE joining_date = ? AND status IN ('ready','downloaded','imported')", [$tomorrow]) as $j) {
            if (!$j['application_id'] || $db->col("SELECT 1 FROM whatsapp_queue WHERE template = 'joining_reminder' AND related_type = 'joining' AND related_id = ?", [$j['id']])) {
                continue;
            }
            $a = $this->app((int) $j['application_id']);
            if (!$a) {
                continue;
            }
            $p = json_decode((string) $j['payload'], true) ?: [];
            $designation = (string) ($p['job']['designation'] ?? $a['title']);
            $place = trim($a['client_display'] . (!empty($p['job']['work_location']) ? ', ' . $p['job']['work_location'] : ''));
            if ($this->wa()->toCandidate((int) $a['candidate_id'], 'joining_reminder', [$a['first'], $designation, 'tomorrow, ' . date('l j F', strtotime($tomorrow)), $place ?: Engagement::NAME, $time], 'joining', (int) $j['id'])) {
                $n++;
            }
        }
        return $n ? "$n joining reminder(s) queued" : '';
    }

    /**
     * "Remind about the details form" (28 Nov 2026): everyone on these positions who was mailed for their details and
     * hasn't answered, whose application is still open, who can get WhatsApp. One row per person (their lead link).
     * @param list<int> $roleIds
     * @return list<array{lead:int, candidate_id:int, first:string, title:string, role_id:int, expired:bool}>
     */
    public function detailsWaiting(array $roleIds): array
    {
        $roleIds = array_values(array_filter(array_map('intval', $roleIds)));
        if (!$roleIds) {
            return [];
        }
        $in = implode(',', $roleIds);
        $rows = $this->app->db->all("SELECT COALESCE(q.parent_id, q.id) AS lead, q.role_id, r.title, c.id AS candidate_id, c.first_name, c.full_name, l.status AS lead_status
            FROM questionnaires q
            JOIN questionnaires l ON l.id = COALESCE(q.parent_id, q.id)
            JOIN applications a ON a.id = q.application_id
            JOIN stages s ON s.id = a.stage_id AND s.kind = 'active'
            JOIN roles r ON r.id = q.role_id
            JOIN candidates c ON c.id = q.candidate_id
            WHERE q.role_id IN ($in) AND l.status IN ('sent','opened','expired') AND l.token_enc <> '-'
              AND " . Contact::putForwardSql('c') . " AND c.whatsapp_off = 0 AND c.phone_norm IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM whatsapp_queue w WHERE w.related_type = 'details_reminder' AND w.related_id = l.id AND w.status IN ('queued','held'))
            ORDER BY q.id");
        $out = [];
        foreach ($rows as $r) {
            $lead = (int) $r['lead'];
            if (isset($out[$lead])) {
                continue;
            }
            $out[$lead] = ['lead' => $lead, 'candidate_id' => (int) $r['candidate_id'], 'first' => Questionnaires::firstName($r), 'title' => (string) $r['title'],
                'role_id' => (int) $r['role_id'], 'expired' => $r['lead_status'] === 'expired'];
        }
        return array_values($out);
    }

    /** Queue the reminders (questions_request, with their own link) to go at $when. A link that had run out is opened again. Returns how many. */
    public function queueDetailsReminders(array $roleIds, string $when): int
    {
        $db = $this->app->db;
        $Q = new Questionnaires($this->app);
        $W = new WaBridge($this->app);
        $n = 0;
        foreach ($this->detailsWaiting($roleIds) as $p) {
            if ($p['expired']) {
                $db->run("UPDATE questionnaires SET status = 'sent', expires_at = ? WHERE id = ? OR parent_id = ?", [Questionnaires::openUntil($when), $p['lead'], $p['lead']]);
            } else {
                $db->run('UPDATE questionnaires SET expires_at = GREATEST(expires_at, ?) WHERE id = ? OR parent_id = ?', [Questionnaires::openUntil($when), $p['lead'], $p['lead']]);
            }
            $link = $Q->link($p['lead']);
            $token = substr($link, strrpos($link, '/') + 1);
            if ($token === '' || $W->toCandidate($p['candidate_id'], 'questions_request', [$p['first'] !== '' ? $p['first'] : 'there', $p['title']], 'details_reminder', $p['lead'], $when, [$token]) === null) {
                continue;
            }
            $n++;
        }
        Audit::log('wa_details_reminders_queued', 'setting', null, ['roles' => array_map('intval', $roleIds), 'when' => $when, 'count' => $n]);
        return $n;
    }

    /** Reminders queued and not gone yet: [count, earliest time]. */
    public function detailsRemindersQueued(): array
    {
        $r = $this->app->db->one("SELECT COUNT(*) AS n, MIN(not_before) AS t FROM whatsapp_queue WHERE related_type = 'details_reminder' AND status IN ('queued','held')");
        return [(int) ($r['n'] ?? 0), $r['t'] ?? null];
    }

    public function cancelDetailsReminders(): int
    {
        $n = $this->app->db->run("UPDATE whatsapp_queue SET status = 'cancelled', last_error = 'Cancelled by you' WHERE related_type = 'details_reminder' AND status IN ('queued','held')")->rowCount();
        Audit::log('wa_details_reminders_cancelled', 'setting', null, ['count' => $n]);
        return $n;
    }
}