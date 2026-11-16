<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Checks before a shortlist goes to a client (B11, approved by Benjamin 14 Nov 2026): they warn, and sending
 * anyway needs a short reason; "already sent to this client in the last 6 months" needs you to confirm.
 * Also permission to put a candidate forward, and chasing a client who hasn't decided.
 */
final class SubmissionChecks
{
    public const SAME_CLIENT_MONTHS = 6;
    public const CV_OLD_MONTHS = 12;
    public const CHASE_WORKING_DAYS = 5;

    /** @var callable|null fixes "now" in tests: fn(): int */
    public $clock = null;

    public function __construct(private readonly App $app)
    {
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }

    /** Records permission to put them forward for this position: from the details form, or after a call. */
    public function setPermission(int $applicationId, bool $yes, string $via, ?int $userId): void
    {
        $this->app->db->update('applications', ['permission' => $yes ? 'yes' : 'not_yet', 'permission_at' => date('Y-m-d H:i:s', $this->now()),
            'permission_via' => mb_substr($via, 0, 20)], $applicationId);
        Audit::log('permission_' . ($yes ? 'yes' : 'not_yet'), 'application', $applicationId, ['via' => $via, 'by' => $userId]);
    }

    /**
     * What to look at before sending, per shortlist item: [application_id => [[text, confirm], ...]].
     * "confirm" = must be ticked (already sent to this client); the rest need a reason.
     */
    public function forShortlist(int $shortlistId): array
    {
        $out = [];
        foreach ($this->app->db->all('SELECT application_id FROM shortlist_items WHERE shortlist_id = ?', [$shortlistId]) as $it) {
            $w = $this->forApplication((int) $it['application_id'], $shortlistId);
            if ($w) {
                $out[(int) $it['application_id']] = $w;
            }
        }
        return $out;
    }

    public function forApplication(int $applicationId, ?int $exceptShortlist = null): array
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.*, r.client_id, r.ctc_max_lpa, r.notice_max_days, r.title, c.ctc_min_lpa AS c_min, c.ctc_expected_lpa AS c_exp, c.notice_days, c.cv_dated,
                (SELECT MAX(f.created_at) FROM cv_files f WHERE f.candidate_id = a.candidate_id) AS cv_at, cl.name AS client
            FROM applications a JOIN roles r ON r.id = a.role_id JOIN candidates c ON c.id = a.candidate_id LEFT JOIN clients cl ON cl.id = r.client_id WHERE a.id = ?', [$applicationId]);
        if (!$a) {
            return [];
        }
        $w = [];
        $lpa = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . ' LPA';
        // 1. Permission.
        if ($a['permission'] === 'not_yet') {
            $w[] = ['They asked us to check with them before putting them forward.', false];
        } elseif ($a['permission'] !== 'yes') {
            $w[] = ['No permission recorded to put them forward (details form, or "They agreed" on their page).', false];
        }
        // 2. Already sent to this client recently, by anyone, for any position.
        if ($a['client_id']) {
            $prev = $db->one("SELECT s.sent_at, r2.title FROM shortlist_items si JOIN shortlists s ON s.id = si.shortlist_id JOIN applications a2 ON a2.id = si.application_id
                    JOIN roles r2 ON r2.id = s.role_id
                WHERE a2.candidate_id = ? AND r2.client_id = ? AND s.status <> 'draft' AND s.sent_at >= ?" . ($exceptShortlist ? ' AND s.id <> ' . (int) $exceptShortlist : '') . '
                ORDER BY s.sent_at DESC LIMIT 1', [$a['candidate_id'], $a['client_id'], date('Y-m-d H:i:s', strtotime('-' . self::SAME_CLIENT_MONTHS . ' months', $this->now()))]);
            if ($prev) {
                $w[] = ['Already sent to ' . $a['client'] . ' on ' . nice_date($prev['sent_at']) . ' (for ' . $prev['title'] . ').', true];
            }
        }
        // 3. Salary.
        $want = $a['c_min'] ?? $a['c_exp'];
        if ($want === null) {
            $w[] = ['Salary expectation not known.', false];
        } elseif ($a['ctc_max_lpa'] !== null && (float) $want > (float) $a['ctc_max_lpa']) {
            $w[] = [($a['c_min'] !== null ? 'Lowest acceptable ' : 'Expects ') . $lpa($want) . ', above the budget of ' . $lpa($a['ctc_max_lpa']) . '.', false];
        }
        // 4. Notice.
        if ($a['notice_days'] === null) {
            $w[] = ['Notice period not known.', false];
        } elseif ($a['notice_max_days'] !== null && (int) $a['notice_days'] > (int) $a['notice_max_days']) {
            $w[] = ['Notice of ' . (int) $a['notice_days'] . ' days, longer than the ' . (int) $a['notice_max_days'] . ' days the position allows.', false];
        }
        // 5. An old CV.
        $dated = $a['cv_dated'] ?: ($a['cv_at'] ? substr((string) $a['cv_at'], 0, 10) : null);
        if ($dated && $dated < date('Y-m-d', strtotime('-' . self::CV_OLD_MONTHS . ' months', $this->now()))) {
            $w[] = ['Their CV is from ' . date('M Y', strtotime($dated)) . ', over a year old: ask for an updated one.', false];
        }
        // 6. Claude's view.
        $band = $db->col("SELECT band FROM fit_assessments WHERE application_id = ? AND status = 'done'", [$applicationId]);
        if ($band === 'Weak') {
            $w[] = ['Claude rated them Weak for this position.', false];
        }
        return $w;
    }

    /** Keeps your reason with each item that had warnings, when you send anyway. */
    public function recordReasons(int $shortlistId, array $warnings, string $reason): void
    {
        foreach ($warnings as $appId => $list) {
            $note = mb_substr('Sent despite: ' . implode(' ', array_column($list, 0)) . ' Reason: ' . $reason, 0, 600);
            $this->app->db->run('UPDATE shortlist_items SET check_note = ? WHERE shortlist_id = ? AND application_id = ?', [$note, $shortlistId, $appId]);
        }
        Audit::log('shortlist_checks_overridden', 'shortlist', $shortlistId, ['reason' => $reason, 'items' => array_keys($warnings)]);
    }

    /** Working days (Monday to Friday) from one date to another. */
    public static function workingDaysBetween(string $from, string $to): int
    {
        $n = 0;
        for ($t = strtotime(date('Y-m-d', strtotime($from)) . ' +1 day'); $t <= strtotime($to); $t += 86400) {
            if ((int) date('N', $t) <= 5) {
                $n++;
            }
        }
        return $n;
    }

    /** Sent shortlists still waiting on the client after 5 working days (since sending, or since you last chased). */
    public function toChase(): array
    {
        $out = [];
        $today = date('Y-m-d', $this->now());
        foreach ($this->app->db->all("SELECT s.id, s.role_id, s.sent_at, s.chased_at, s.to_name, s.to_email, r.title, cl.name AS client,
                (SELECT COUNT(*) FROM shortlist_items si WHERE si.shortlist_id = s.id AND si.decision IS NULL) AS waiting
            FROM shortlists s JOIN roles r ON r.id = s.role_id LEFT JOIN clients cl ON cl.id = r.client_id
            WHERE s.status = 'sent' AND r.status IN ('open','on_hold')") as $s) {
            $since = $s['chased_at'] ?: $s['sent_at'];
            if ((int) $s['waiting'] > 0 && $since && self::workingDaysBetween((string) $since, $today) >= self::CHASE_WORKING_DAYS) {
                $s['days'] = self::workingDaysBetween((string) $s['sent_at'], $today);
                $out[] = $s;
            }
        }
        return $out;
    }

    public function chased(int $shortlistId): void
    {
        $this->app->db->run('UPDATE shortlists SET chased_at = ? WHERE id = ?', [date('Y-m-d H:i:s', $this->now()), $shortlistId]);
    }

    /** The client feedback strip on a position: each sent shortlist, decided / waiting, days since sent. */
    public function feedback(int $roleId): array
    {
        $today = date('Y-m-d', $this->now());
        $rows = $this->app->db->all("SELECT s.id, s.sent_at, s.chased_at, s.first_viewed_at,
                SUM(si.decision = 'shortlist') AS yes, SUM(si.decision = 'hold') AS hold, SUM(si.decision = 'reject') AS no, SUM(si.decision IS NULL) AS waiting
            FROM shortlists s JOIN shortlist_items si ON si.shortlist_id = s.id WHERE s.role_id = ? AND s.status <> 'draft' GROUP BY s.id ORDER BY s.sent_at DESC", [$roleId]);
        foreach ($rows as &$r) {
            $r['days'] = $r['sent_at'] ? self::workingDaysBetween((string) $r['sent_at'], $today) : 0;
        }
        return $rows;
    }
}
