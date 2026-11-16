<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Position history (B13) and the new-position checklist (B14), 14 November 2026.
 * A snapshot before and after each save; the differences are kept with who and when. The checklist lists what
 * is still to clarify with the client.
 */
final class RoleHistory
{
    /** The details worth tracking, with plain labels. */
    public const FIELDS = [
        'title' => 'Title', 'status' => 'Status', 'location' => 'Location', 'work_mode' => 'Work mode', 'location_scope' => 'Location rule',
        'function_codes' => 'Fields of work', 'exp_min' => 'Experience from', 'exp_max' => 'Experience to', 'exp_allow_below' => 'Allow below',
        'exp_allow_above' => 'Allow above', 'ctc_min_lpa' => 'CTC from', 'ctc_max_lpa' => 'Budget', 'notice_max_days' => 'Max notice',
        'positions' => 'Positions', 'owner_user_id' => 'Recruiter', 'target_date' => 'Target date', 'score_threshold' => 'Threshold',
        'interview_rounds' => 'Interview rounds', 'decision_maker' => 'Who decides', 'qual_ladder' => 'Qualification ladder',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** Everything tracked about a position right now, including its must-haves and criteria. */
    public function snapshot(int $roleId): array
    {
        $db = $this->app->db;
        $r = $db->one('SELECT * FROM roles WHERE id = ?', [$roleId]) ?: [];
        $s = [];
        foreach (array_keys(self::FIELDS) as $k) {
            if (array_key_exists($k, $r)) {
                $s[$k] = $r[$k] === null ? null : (string) $r[$k];
            }
        }
        $s['requirements'] = array_map(fn ($q) => $q['text'] . ($q['critical'] ? ' (critical)' : ''),
            $db->all('SELECT text, critical FROM role_requirements WHERE role_id = ? ORDER BY sort, id', [$roleId]));
        $s['criteria'] = array_map(fn ($c) => trim(($c['kind'] ?? '') . ': ' . ($c['value'] ?? $c['text'] ?? '')),
            $db->all('SELECT * FROM role_criteria WHERE role_id = ? ORDER BY id', [$roleId]));
        return $s;
    }

    /** Keeps what changed between two snapshots. Returns the number of changes recorded. */
    public function record(int $roleId, array $before, array $after, ?int $userId): int
    {
        $changes = [];
        foreach ($after as $k => $new) {
            $old = $before[$k] ?? null;
            if (is_array($new) || is_array($old)) {
                $old = (array) $old;
                $new = (array) $new;
                $added = array_values(array_diff($new, $old));
                $removed = array_values(array_diff($old, $new));
                if ($added || $removed) {
                    $changes[$k] = ['added' => $added, 'removed' => $removed];
                }
            } elseif ($this->norm($old) !== $this->norm($new)) {
                $changes[$k] = ['from' => $old, 'to' => $new];
            }
        }
        if (!$changes) {
            return 0;
        }
        $this->app->db->insert('role_revisions', ['role_id' => $roleId, 'user_id' => $userId, 'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE)]);
        return count($changes);
    }

    private function norm($v): string
    {
        $v = trim((string) $v);
        return is_numeric($v) ? (string) (0 + $v) : $v; // "4.20" and "4.2" are the same budget
    }

    /** The history, newest first, as plain lines. */
    public function history(int $roleId, int $limit = 50): array
    {
        $out = [];
        foreach ($this->app->db->all('SELECT rr.*, u.name AS who FROM role_revisions rr LEFT JOIN users u ON u.id = rr.user_id WHERE rr.role_id = ? ORDER BY rr.id DESC LIMIT ' . (int) $limit, [$roleId]) as $r) {
            $lines = [];
            foreach (json_decode((string) $r['changes'], true) ?: [] as $k => $c) {
                $label = self::FIELDS[$k] ?? ($k === 'requirements' ? 'Must-haves' : ($k === 'criteria' ? 'Keywords' : $k));
                if (isset($c['added']) || isset($c['removed'])) {
                    foreach ($c['added'] ?? [] as $a) {
                        $lines[] = "$label: added \"$a\"";
                    }
                    foreach ($c['removed'] ?? [] as $a) {
                        $lines[] = "$label: removed \"$a\"";
                    }
                } else {
                    $show = fn ($v) => $v === null || $v === '' ? '(none)' : (is_numeric($v) ? (string) (0 + $v) : (string) $v);
                    $lines[] = "$label: " . $show($c['from']) . ' → ' . $show($c['to']);
                }
            }
            $out[] = ['at' => $r['created_at'], 'who' => $r['who'] ?: 'System', 'lines' => $lines];
        }
        return $out;
    }

    /** What is still to clarify with the client for this position (the new-position checklist). */
    public function toClarify(array $role): array
    {
        if (!empty($role['is_pool'])) {
            return [];
        }
        $db = $this->app->db;
        $out = [];
        if ($role['ctc_max_lpa'] === null || $role['ctc_max_lpa'] === '') {
            $out[] = 'Budget (the most the client will pay)';
        }
        if ($role['notice_max_days'] === null || $role['notice_max_days'] === '') {
            $out[] = 'The longest notice period the client will wait for';
        }
        if (trim((string) ($role['location'] ?? '')) === '' && ($role['work_mode'] ?? '') !== 'remote') {
            $out[] = 'Where the job is based';
        }
        if ($role['exp_min'] === null || $role['exp_max'] === null) {
            $out[] = 'The experience range';
        }
        $reqs = (int) $db->col('SELECT COUNT(*) FROM role_requirements WHERE role_id = ?', [$role['id']]);
        if ($reqs < 3) {
            $out[] = 'At least three must-haves (' . $reqs . ' so far)';
        }
        if (array_key_exists('interview_rounds', $role) && !$role['interview_rounds']) {
            $out[] = 'How many interview rounds';
        }
        if (array_key_exists('decision_maker', $role) && trim((string) $role['decision_maker']) === '') {
            $out[] = 'Who at the client decides';
        }
        return $out;
    }
}
