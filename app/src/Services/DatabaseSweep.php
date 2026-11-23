<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * "Add all relevant" from the database (the button on a position's matches page), and the same every morning for
 * positions that ask for it (23 Nov 2026, Benjamin, before the Trainee Technicians WhatsApp campaign: applications
 * whose email subject names no position must not be missed). Relevant = the free check's same field (half the
 * must-haves) and experience inside the allowance. Those added go through the screening rules (branch, region) as
 * anyone picked from the database does, and Claude assesses the relevant ones. Nobody is emailed.
 */
final class DatabaseSweep
{
    /** The hour (server time) the morning sweep runs. */
    public const HOUR = 7;

    public function __construct(private readonly App $app)
    {
    }

    /** Is the morning sweep on for this position? */
    public static function on(App $app, int $roleId): bool
    {
        return $app->setting("role_sweep_$roleId") === '1';
    }

    public static function set(App $app, int $roleId, bool $on): void
    {
        $app->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ["role_sweep_$roleId", $on ? '1' : '0']);
    }

    /** The last morning sweep on a position: ['at' => 'Y-m-d H:i:s', 'added' => [names]], or null. */
    public static function last(App $app, int $roleId): ?array
    {
        $v = json_decode((string) $app->setting("role_sweep_last_$roleId"), true);
        return is_array($v) && isset($v['at']) ? $v + ['added' => []] : null;
    }

    /**
     * Adds every relevant match on file to the position. Returns the candidates added as [id => name].
     * $note, when given, goes on each new application ("Added by the morning sweep").
     */
    public function addRelevant(array $role, int $userId, ?string $note = null): array
    {
        $importer = new CandidateImporter($this->app);
        $added = [];
        foreach ((new Matcher($this->app))->forRole($role, 200) as $m) {
            $c = $m['cand'] ?? null;
            $must = $m['breakdown']['parts']['must'] ?? null;
            if (!$c || !Contact::canPutForward($c)) {
                continue;
            }
            $nMust = $must ? count($must['matched']) + count($must['missing']) : 0;
            if (!RoleRouter::sameField($must ? count($must['matched']) : 0, $nMust)) {
                continue;
            }
            $months = exp_now($c);
            if (($role['exp_min'] !== null || $role['exp_max'] !== null)
                && ($months === null ? (float) ($role['exp_min'] ?? 0) > 1 : !RoleRouter::inWindow($role, $months / 12))) {
                continue;
            }
            if ($importer->attach((int) $c['id'], (int) $role['id'], $userId, 'database')) {
                $added[(int) $c['id']] = (string) $c['full_name'];
                if ($note !== null) {
                    $this->app->db->run("UPDATE applications SET fit_note = COALESCE(fit_note, ?) WHERE candidate_id = ? AND role_id = ?", [$note, $c['id'], $role['id']]);
                }
            }
        }
        return $added;
    }

    /** The morning run: every open position with the sweep on. Returns a line for Scheduled jobs. */
    public function runDaily(int $userId): string
    {
        $db = $this->app->db;
        $out = [];
        foreach ($db->all("SELECT r.* FROM roles r JOIN settings s ON s.k = CONCAT('role_sweep_', r.id) AND s.v = '1'
                WHERE r.status = 'open' AND r.is_pool = 0 ORDER BY r.id") as $role) {
            $added = $this->addRelevant($role, $userId, 'Added from your database by the morning sweep');
            if ($added && FitAssessor::enabled($this->app)) {
                (new FitAssessor($this->app))->queueRole((int) $role['id']);
            }
            $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                ['role_sweep_last_' . $role['id'], json_encode(['at' => date('Y-m-d H:i:s'), 'added' => array_values($added)])]);
            Audit::log('database_sweep', 'role', (int) $role['id'], ['added' => count($added)]);
            $out[] = ($role['code'] ?: $role['title']) . ': ' . count($added) . ' added';
        }
        return $out ? implode('; ', $out) : 'no position has the morning sweep on';
    }
}
