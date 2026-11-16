<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Sends a CV to the role it fits, whatever was applied for.
 *
 * Every role has a band (say 2-5 years) and an allowance around it (by default 1 year
 * below and 5 above, so 1-10 years). Inside the band is a fit; inside the allowance is
 * a stretch fit, flagged; outside the allowance is not a fit.
 *
 * Example: Accounts Executive wants 2-5 years, Accounts Manager wants 8-12. A candidate
 * with 10 years applies for Executive. They stay on Executive (it is what they applied
 * for), marked "above this role's band", and are also added to Manager.
 *
 * A candidate is added to another open role only when ALL of these hold:
 *  - they are outside the band of the role they applied for, or in a different field, or sent with no role;
 *  - their experience is inside the other role's allowance;
 *  - they match at least half of that role's must-haves (so an AutoCAD CV never lands in accounts);
 *  - their score reaches that role's shortlist threshold;
 *  - that role accepts routed CVs (a switch on the role), and routing is on in Settings.
 *
 * Talent pools: when a CV is in a different field from the role it was sent for, or is
 * outside its allowance, or came with no role, and no open role takes it, it is filed in
 * the pool named after the candidate's own current position (e.g. "HR Executive"),
 * created the first time it is needed. Pools are not client roles and email nobody.
 */
final class RoleRouter
{
    public const POOL_CLIENT = 'Eshcol talent pool';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param int|null $appliedRoleId the role the CV was sent for, if any
     * @return array<int, array{role_id:int, title:string, pool?:bool}> roles and pools the candidate was added to
     */
    public function route(int $candidateId, ?int $appliedRoleId, int $userId): array
    {
        $db = $this->app->db;
        if ($this->app->setting('routing_enabled', '1') !== '1') {
            return [];
        }
        // Switched on in Settings › Categories (13 Nov 2026): by field, experience and location, once the
        // CV is categorised (until then nothing moves; the categoriser routes it when it's done).
        if (Categoriser::mode($this->app) === 'on') {
            return (new FunctionRouting($this->app))->route($candidateId, $appliedRoleId, $userId);
        }
        if ($appliedRoleId === null && $this->app->setting('auto_sort_unassigned', '1') !== '1') {
            return [];
        }
        $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$cand) {
            return [];
        }
        $months = exp_now($cand);
        $years = $months === null ? null : $months / 12;
        $importer = new CandidateImporter($this->app);
        $text = (string) $db->col('SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1', [$candidateId]);

        $applied = $appliedRoleId ? $db->one('SELECT * FROM roles WHERE id = ?', [$appliedRoleId]) : null;
        if ($applied && $applied['is_pool']) {
            return []; // filed in a pool by hand: nothing to route
        }
        $field = $applied ? $this->fieldMatch($applied, $cand, $text, $importer) : null; // [matched, of] or null
        $wrongField = $field !== null && !self::sameField($field[0], $field[1]);
        $inBand = $applied && $years !== null && $this->inBand($applied, $years);
        $inWindow = $applied && $years !== null && $this->inWindow($applied, $years);
        if ($applied && !$wrongField && ($inBand || $years === null)) {
            return []; // right field, and the experience fits (or isn't known): nothing to do
        }

        // Other open roles the candidate fits.
        $added = [];
        if ($years !== null) {
            $roles = $db->all("
                SELECT * FROM roles
                WHERE status = 'open' AND is_pool = 0 AND accepts_routed = 1 AND (exp_min IS NOT NULL OR exp_max IS NOT NULL)
                  AND id <> ? AND id NOT IN (SELECT role_id FROM applications WHERE candidate_id = ?)", [$appliedRoleId ?? 0, $candidateId]);
            // Best fit first: roles whose band they fit exactly; only if there are none, roles whose allowance takes them.
            $exact = array_filter($roles, fn ($r) => $this->inBand($r, $years) && $this->qualifies($r, $cand, $text, $importer));
            $roles = $exact ?: array_filter($roles, fn ($r) => $this->inWindow($r, $years) && $this->qualifies($r, $cand, $text, $importer));
            foreach ($roles as $role) {
                $fit = round($years, 1) . ' yrs ' . ($this->inBand($role, $years) ? "fits this role's band" : "is within this role's allowance");
                $note = $applied ? 'Routed from ' . $applied['title'] . ": $fit" : "Sorted in automatically: $fit and the skills match";
                $this->attach($importer, $candidateId, (int) $role['id'], $userId, $appliedRoleId, $note);
                $added[] = ['role_id' => (int) $role['id'], 'title' => $role['title']];
            }
        }

        // Nowhere suitable among the open roles: file them under their own position.
        $needsHome = $applied ? ($wrongField || ($years !== null && !$inWindow))
            : !$db->col("SELECT 1 FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') LIMIT 1", [$candidateId]);
        if (!$added && $needsHome && $this->app->setting('talent_pools_enabled', '1') === '1' && !empty($cand['current_title'])) {
            $poolId = $this->pool((string) $cand['current_title']);
            if (!$db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$candidateId, $poolId])) {
                $note = $applied ? 'Filed by position: applied for ' . $applied['title'] : 'Filed by position: CV arrived without a role';
                $this->attach($importer, $candidateId, $poolId, $userId, $appliedRoleId, $note);
                $added[] = ['role_id' => $poolId, 'title' => (string) $cand['current_title'], 'pool' => true];
            }
        }

        if ($applied) {
            $parts = [];
            if ($wrongField) {
                $parts[] = "Different field: matches {$field[0]} of {$field[1]} must-haves";
            }
            if ($years !== null && !$inBand) {
                $parts[] = round($years, 1) . ' yrs: ' . ($years < (float) ($applied['exp_min'] ?? 0) ? 'below' : 'above') . " this role's band ({$this->bandText($applied)}), "
                    . ($inWindow ? 'within' : 'outside') . ' its allowance';
            }
            $note = implode('; ', $parts) . ($added ? '; added to ' . implode(', ', array_map(fn ($a) => $a['title'] . (empty($a['pool']) ? '' : ' (talent pool)'), $added)) : '; no better-fitting open role');
            $db->run('UPDATE applications SET fit_note = ? WHERE candidate_id = ? AND role_id = ?', [mb_substr($note, 0, 255), $candidateId, $appliedRoleId]);
        }
        if ($added) {
            Audit::log('cv_routed', 'candidate', $candidateId, ['from' => $appliedRoleId, 'to' => array_column($added, 'role_id')]);
        }
        return $added;
    }

    /** "Also added to Accounts Manager, where the experience fits. Filed in the HR Executive talent pool." */
    public static function describe(array $added, string $roleLead = 'Also added to'): string
    {
        $roles = array_column(array_filter($added, fn ($a) => empty($a['pool'])), 'title');
        $pools = array_column(array_filter($added, fn ($a) => !empty($a['pool'])), 'title');
        return trim(($roles ? "$roleLead " . implode(', ', $roles) . ', where the experience and skills fit. ' : '')
            . ($pools ? 'Filed in the ' . implode(', ', $pools) . ' talent pool, by the position they hold.' : ''));
    }

    /** The talent pool for a position, created the first time it is needed. */
    public function pool(string $title): int
    {
        $db = $this->app->db;
        $id = $db->col('SELECT id FROM roles WHERE is_pool = 1 AND title = ?', [$title]);
        if ($id) {
            return (int) $id;
        }
        $client = $db->col('SELECT id FROM clients WHERE name = ?', [self::POOL_CLIENT])
            ?: $db->insert('clients', ['name' => self::POOL_CLIENT, 'notes' => 'Internal: holds the talent pools. Not a real client.']);
        $id = $db->insert('roles', ['client_id' => (int) $client, 'title' => mb_substr($title, 0, 160), 'status' => 'open', 'is_pool' => 1,
            'accepts_routed' => 0, 'score_threshold' => 0, 'opened_at' => date('Y-m-d'),
            'jd_text' => 'Talent pool: CVs filed here by the position the candidate holds. Not a client role.']);
        $db->update('roles', ['code' => sprintf('POOL-%04d', $id)], $id);
        Audit::log('pool_created', 'role', $id);
        return $id;
    }

    private function attach(CandidateImporter $importer, int $candidateId, int $roleId, int $userId, ?int $fromRoleId, string $note): void
    {
        $importer->attach($candidateId, $roleId, $userId, 'routed');
        $this->app->db->run('UPDATE applications SET routed_from_role_id = ?, fit_note = ? WHERE candidate_id = ? AND role_id = ?',
            [$fromRoleId, mb_substr($note, 0, 255), $candidateId, $roleId]);
    }

    /**
     * The "same field" rule (audit F17: one copy, used by routing, relevance and the Claude tab): the CV has at least half of
     * the position's must-haves. A position with no must-haves never rules anyone out on this.
     */
    public static function sameField(int $hit, int $of): bool
    {
        return $of <= 0 || $hit * 2 >= $of;
    }

    /** Same field (at least half the must-haves) and good enough (reaches the threshold). */
    private function qualifies(array $role, array $cand, string $text, CandidateImporter $importer): bool
    {
        $criteria = $this->app->db->all('SELECT kind, value FROM role_criteria WHERE role_id = ?', [$role['id']]);
        $musts = array_filter($criteria, fn ($c) => $c['kind'] === 'must');
        if (!$musts) {
            return false; // a role with no must-haves would match everyone
        }
        $s = $importer->scorer()->score($role, $criteria, $cand, $text);
        return self::sameField(count($s['breakdown']['parts']['must']['matched']), count($musts)) && $s['score'] >= (int) $role['score_threshold'];
    }

    /** How many of the role's must-haves the CV matches: [matched, of]. */
    private function fieldMatch(array $role, array $cand, string $text, CandidateImporter $importer): array
    {
        $criteria = $this->app->db->all('SELECT kind, value FROM role_criteria WHERE role_id = ?', [$role['id']]);
        $musts = array_filter($criteria, fn ($c) => $c['kind'] === 'must');
        if (!$musts) {
            return [0, 0];
        }
        $s = $importer->scorer()->score($role, $criteria, $cand, $text);
        return [count($s['breakdown']['parts']['must']['matched']), count($musts)];
    }

    /** Inside the role's stated band (half a year of grace at the top). */
    private function inBand(array $role, float $years): bool
    {
        $min = $role['exp_min'] !== null ? (float) $role['exp_min'] : null;
        $max = $role['exp_max'] !== null ? (float) $role['exp_max'] : null;
        return ($min === null || $years >= $min) && ($max === null || $years <= $max + 0.5);
    }

    /** Inside the band widened by the role's allowance (default 1 year below, 5 above). */
    /**
     * How well years of experience fit a role's band: the share of the experience points, for both
     * the free score and Claude's (Benjamin, 10 Nov 2026). Inside the band it rises from 70% at the
     * bottom to 100% at the top (half a year of grace above). Above the band but inside the
     * allowance 100%. Below the band but inside the allowance 50% just under it, down to 30% at the
     * allowance limit; below that 0–30%. Beyond the allowance above 30%.
     * @return array{0: float, 1: string} [share 0–1, where they fall]
     */
    public static function expFit(array $role, float $years): array
    {
        $min = $role['exp_min'] !== null && $role['exp_min'] !== '' ? (float) $role['exp_min'] : null;
        $max = $role['exp_max'] !== null && $role['exp_max'] !== '' ? (float) $role['exp_max'] : null;
        if ($min === null && $max === null) {
            return [1.0, 'no band set'];
        }
        if (($min === null || $years >= $min) && ($max === null || $years <= $max + 0.5)) {
            if ($min === null || $max === null || $max <= $min || $years >= $max) {
                return [1.0, 'in band'];
            }
            return [0.7 + 0.3 * ($years - $min) / ($max - $min), 'in band'];
        }
        if ($min !== null && $years < $min) {
            $lo = $min - (float) ($role['exp_allow_below'] ?? 1);
            if ($lo < $min && $years >= $lo) {
                return [0.3 + 0.2 * ($years - $lo) / ($min - $lo), 'below band, within allowance'];
            }
            return [$lo > 0 ? max(0.0, 0.3 * $years / $lo) : 0.0, 'below the allowance'];
        }
        return self::inWindow($role, $years) ? [1.0, 'above band, within allowance'] : [0.3, 'well above band'];
    }

    public static function inWindow(array $role, float $years): bool
    {
        $min = $role['exp_min'] !== null ? (float) $role['exp_min'] - (float) ($role['exp_allow_below'] ?? 1) : null;
        $max = $role['exp_max'] !== null ? (float) $role['exp_max'] + (float) ($role['exp_allow_above'] ?? 5) : null;
        return ($min === null || $years >= $min) && ($max === null || $years <= $max + 0.5);
    }

    private function bandText(array $r): string
    {
        $f = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        return ($r['exp_min'] !== null ? $f($r['exp_min']) : '0') . '–' . ($r['exp_max'] !== null ? $f($r['exp_max']) : '+') . ' yrs';
    }
}
