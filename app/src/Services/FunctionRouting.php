<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Routing by field of work, experience and location (Benjamin, 13 Nov 2026), replacing "half the
 * must-have words appear in the CV".
 *
 * A candidate goes on a position's pipeline when:
 *  - their field (or second field) is one the position takes;
 *  - their experience is inside the position's band or allowance;
 *  - the position takes them from where they live:
 *      "Local candidates only" (junior posts: clients won't pay to relocate, and the salary doesn't
 *      justify a move) takes the position's city, its localities and the other places listed on it,
 *      plus anyone whose CV names that city as a preferred location;
 *      "Open to other cities" (senior posts) takes anyone, marked "from another city";
 *      "Remote" takes anyone.
 * A city that isn't known never keeps anyone out: the details form asks.
 *
 * Someone who applied for a local-only position from another city is not put on its pipeline: they are
 * moved to "Future requirement" on it, with the reason, and filed in the talent pool for their field
 * and city ("Accounts, Tax & Audit · Chennai"), so they are found when a position opens there.
 * Nothing here emails anyone or asks Claude for anything; the assessment follows as before.
 */
final class FunctionRouting
{
    public const RESULTS = [
        'pipeline' => 'On the pipeline', 'other_city' => 'Parked: lives elsewhere', 'other_field' => 'Different field',
        'outside_band' => 'Outside the experience range', 'experience_unknown' => 'Experience not known', 'not_categorised' => 'Not categorised yet',
        'blocked' => 'Do not contact, or marked unreliable', 'no_cv_text' => 'No readable CV yet',
        'band_elsewhere' => "Fits another position's band instead", 'no_routing' => "Position doesn't take routed CVs",
    ];

    /**
     * The checks the free check makes before anything else (FitAssessor::relevance): not to be contacted,
     * marked unreliable, or no readable CV. Null when none applies.
     * @return array{0: string, 1: string}|null
     */
    public function gate(array $cand): ?array
    {
        if (Contact::silenced($cand)) {
            return ['blocked', 'do not contact'];
        }
        if (!empty($cand['unreliable_at'])) {
            return ['blocked', 'marked unreliable'];
        }
        $text = (string) $this->app->db->col('SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1', [(int) $cand['id']]);
        return preg_match_all('/\p{L}/u', $text) < 200 ? ['no_cv_text', 'no readable CV yet'] : null;
    }
    public const SCOPES = ['local' => 'Local candidates only', 'open' => 'Open to other cities', 'remote' => 'Remote: anywhere'];

    private Categoriser $cat;
    private ?Skills $skills = null;
    /** @var array<int, list<string>> role id => the places that count as local, lower case */
    private array $places = [];

    public function __construct(private readonly App $app, ?Categoriser $cat = null)
    {
        $this->cat = $cat ?? new Categoriser($app);
    }

    /** The suggested choice for a new position: junior posts local only, senior ones open, remote anywhere. */
    public static function defaultScope(?float $expMin, ?float $expMax, ?string $workMode): string
    {
        return match (true) {
            $workMode === 'remote' => 'remote',
            $expMin !== null && $expMin >= 8 => 'open',
            default => 'local',
        };
    }

    public static function scopeOf(array $role): string
    {
        $s = (string) ($role['location_scope'] ?? '');
        return isset(self::SCOPES[$s]) ? $s : self::defaultScope(
            $role['exp_min'] !== null && $role['exp_min'] !== '' ? (float) $role['exp_min'] : null,
            ($role['exp_max'] ?? null) !== null && $role['exp_max'] !== '' ? (float) $role['exp_max'] : null,
            $role['work_mode'] ?? null);
    }

    /**
     * Would the new rules put this candidate on this position's pipeline? Pure apart from reading the
     * position's places: tested directly.
     * @param array|null $cat the current categorisation (candidate_categories row), or null
     * @return array{0: string, 1: string} [one of RESULTS, the reason in plain words]
     */
    public function decide(array $cand, ?array $cat, array $role): array
    {
        if (!$cat) {
            return ['not_categorised', 'not categorised yet'];
        }
        $fns = $this->cat->roleFunctions($role);
        $mine = array_filter([(string) $cat['function_code'], (string) ($cat['secondary_code'] ?? '')]);
        if ($fns && !array_intersect($mine, $fns)) {
            return ['other_field', 'works in ' . Categoriser::functionName($this->app, $cat['function_code'])
                . '; this position takes ' . implode(', ', array_map(fn ($c) => Categoriser::functionName($this->app, $c), $fns))];
        }
        if ($role['exp_min'] !== null || $role['exp_max'] !== null) {
            $m = exp_now($cand);
            if ($m === null) {
                if ((float) ($role['exp_min'] ?? 0) > 1) {
                    return ['experience_unknown', 'experience not known (no dates or total in the CV)'];
                }
            } elseif (!RoleRouter::inWindow($role, $m / 12)) {
                return ['outside_band', round($m / 12, 1) . " yrs: outside this position's experience range"];
            }
        }
        [$where, $city] = $this->whereFrom($role, $cand, $cat);
        $scope = self::scopeOf($role);
        $place = trim((string) $role['location']);
        if ($scope === 'local' && $where === 'no') {
            return ['other_city', "lives in $city; this position takes local candidates only"];
        }
        $note = match (true) {
            $scope === 'remote' => '',
            $where === 'unknown' => 'city not known',
            $where === 'prefers' => "lives in $city, prefers $place",
            $where === 'no' => "from another city ($city)",
            default => '',
        };
        return ['pipeline', $note];
    }

    /**
     * Does the candidate live where the position is? 'yes' (the city, one of its localities, or a place
     * listed on the position), 'prefers' (lives elsewhere but their CV names the position's city),
     * 'no', or 'unknown' (no city on file).
     * @return array{0: string, 1: string} [status, their city as shown]
     */
    public function whereFrom(array $role, array $cand, ?array $cat): array
    {
        $places = $this->placesOf($role);
        if (!$places) {
            return ['yes', ''];
        }
        $skills = $this->skills();
        $city = trim((string) ($cand['location'] ?? ''));
        if ($city === '' && !empty($cand['locality']) && ($info = $skills->localityOf((string) $cand['locality']))) {
            $city = (string) $info['city'];
        }
        $prefs = array_map(fn ($p) => mb_strtolower(trim($p)), array_filter(explode(',', (string) ($cat['preferred_cities'] ?? ''))));
        if ($city === '') {
            return [array_intersect($prefs, $places) ? 'prefers' : 'unknown', ''];
        }
        $lc = mb_strtolower($skills->canonical($city, 'location'));
        if (in_array($lc, $places, true) || in_array(mb_strtolower($city), $places, true)) {
            return ['yes', $city];
        }
        // A suburb given as the city ("Chinchwad", "Wanowrie, Pune").
        if (($info = $skills->localityOf($city)) && in_array(mb_strtolower((string) $info['city']), $places, true)) {
            return ['yes', $city];
        }
        foreach ($places as $p) {
            if (mb_strlen($p) >= 3 && preg_match('/(?<![\p{L}])' . preg_quote($p, '/') . '(?![\p{L}])/iu', $city)) {
                return ['yes', $city];
            }
        }
        return [array_intersect($prefs, $places) ? 'prefers' : 'no', $city];
    }

    /** The position's city and the other places listed on it, as the dictionary names them (lower case). */
    private function placesOf(array $role): array
    {
        $id = (int) ($role['id'] ?? 0);
        if ($id && isset($this->places[$id])) {
            return $this->places[$id];
        }
        $list = trim((string) ($role['location'] ?? '')) !== '' ? [(string) $role['location']] : [];
        if ($id) {
            foreach ($this->app->db->all("SELECT value FROM role_criteria WHERE role_id = ? AND kind = 'location'", [$id]) as $r) {
                $list[] = (string) $r['value'];
            }
        }
        $out = [];
        foreach ($list as $p) {
            foreach (preg_split('/\s*[,\/;]\s*/', $p) as $part) {
                if (($part = trim($part)) !== '') {
                    $out[] = mb_strtolower($this->skills()->canonical($part, 'location'));
                    $out[] = mb_strtolower($part);
                }
            }
        }
        $out = array_values(array_unique($out));
        if ($id) {
            $this->places[$id] = $out;
        }
        return $out;
    }

    // ---------------------------------------------------------------- routing (switched on)

    /**
     * Routes a categorised candidate (Settings › Categories: On). Returns the positions and pools they
     * were added to, as RoleRouter::route() does. Nothing happens before they are categorised.
     */
    public function route(int $candidateId, ?int $appliedRoleId, int $userId): array
    {
        $db = $this->app->db;
        if ($this->app->setting('routing_enabled', '1') !== '1') {
            return [];
        }
        $cat = $this->cat->current($candidateId);
        $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$cat || !$cand) {
            return [];
        }
        $first = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
        $hold = (int) $db->col("SELECT id FROM stages WHERE kind = 'hold' AND is_active = 1 ORDER BY sort LIMIT 1");

        // 1. Positions they applied for themselves: someone from another city on a local-only position is parked.
        $applied = $db->all("SELECT a.*, r.title, r.location, r.location_scope, r.exp_min, r.exp_max, r.exp_allow_below, r.exp_allow_above, r.work_mode, r.function_codes, r.id AS rid
            FROM applications a JOIN roles r ON r.id = a.role_id
            WHERE a.candidate_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND (a.source IS NULL OR a.source NOT IN ('routed','database'))", [$candidateId]);
        if (!$applied && $appliedRoleId === null && $this->app->setting('auto_sort_unassigned', '1') !== '1') {
            return [];
        }
        // Settled: on a position that takes them, or already being taken forward on one (not filed in a pool).
        $settled = (bool) $db->col("SELECT 1 FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
            WHERE a.candidate_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND s.kind = 'active'
              AND (a.stage_id <> ? OR a.source IN ('routed','database')) LIMIT 1", [$candidateId, $first]);
        foreach ($applied as $a) {
            $role = ['id' => (int) $a['rid']] + $a;
            [$res, $why] = $this->decide($cand, $cat, $role);
            $settled = $settled || $res === 'pipeline';
            if ($res === 'other_city' && $hold && (int) $a['stage_id'] === $first) {
                $reason = ucfirst($why) . '.';
                $db->update('applications', ['stage_id' => $hold, 'stage_reason' => mb_substr($reason, 0, 255), 'stage_reason_at' => date('Y-m-d H:i:s'), 'fit_note' => mb_substr("Parked: $why", 0, 255)], (int) $a['id']);
                $db->insert('stage_history', ['application_id' => $a['id'], 'from_stage_id' => $first, 'to_stage_id' => $hold, 'user_id' => null, 'note' => mb_substr("Parked for a future requirement: $why", 0, 255)]);
                Audit::log('candidate_parked', 'candidate', $candidateId, ['role' => (int) $a['rid'], 'why' => $why]);
            } elseif (in_array($res, ['other_field', 'outside_band', 'experience_unknown'], true)) {
                $db->run('UPDATE applications SET fit_note = ? WHERE id = ?', [mb_substr(ucfirst($why), 0, 255), $a['id']]);
            }
        }

        // 2. Other open positions that take routed CVs, as plan() decides: those whose band they fit, else whose allowance takes them.
        $added = [];
        $m = exp_now($cand);
        $importer = new CandidateImporter($this->app);
        foreach ($this->plan($cand, $cat) as $p) {
            if ($p['on_role'] || $p['result'] !== 'pipeline') {
                continue;
            }
            [$r, $note] = [$p['role'], $p['why']];
            $yrs = $m === null ? 'experience not known' : round($m / 12, 1) . ' yrs ' . ($p['in_band'] ? "fits this position's band" : "is within this position's allowance");
            $text = 'Sorted in by field (' . Categoriser::functionName($this->app, $cat['function_code']) . "): $yrs" . ($note !== '' ? "; $note" : '');
            $importer->attach($candidateId, (int) $r['id'], $userId, 'routed');
            $db->run('UPDATE applications SET fit_note = ? WHERE candidate_id = ? AND role_id = ?', [mb_substr($text, 0, 255), $candidateId, $r['id']]);
            $added[] = ['role_id' => (int) $r['id'], 'title' => $r['title']];
        }

        // 3. No open position takes them (a different field, another city, outside the range): filed in the pool
        //    for their field and city, so they are found when one does.
        if (!$added && !$settled && $this->app->setting('talent_pools_enabled', '1') === '1') {
            [$code, $city, $note] = $this->poolFor($cand, $cat);
            $poolId = $this->pool($code, $city);
            if (!$db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$candidateId, $poolId])) {
                $importer->attach($candidateId, $poolId, $userId, 'routed');
                $db->run('UPDATE applications SET fit_note = ? WHERE candidate_id = ? AND role_id = ?', [mb_substr($note, 0, 255), $candidateId, $poolId]);
                $added[] = ['role_id' => $poolId, 'title' => $this->poolTitle($code, $city), 'pool' => true];
            }
        }
        if ($added) {
            Audit::log('cv_routed', 'candidate', $candidateId, ['to' => array_column($added, 'role_id'), 'by' => 'field']);
        }
        return $added;
    }

    /**
     * What the new rules do with a candidate on every open client position: the result of decide(), with
     * the band preference for positions they would be added to. Someone whose experience fits one
     * position's band (one they're on, or one they'd be added to) is not also added where only the
     * allowance takes them: a 5-year accountant on Accounting Manager is not added to Accounting
     * Associate as well. Positions that don't take routed CVs add nobody. Used by route() and the trial,
     * so both always agree.
     * @return array<int, array{role: array, result: string, why: string, on_role: bool, in_band: bool}>
     */
    public function plan(array $cand, ?array $cat): array
    {
        $db = $this->app->db;
        $m = exp_now($cand);
        $on = array_map('intval', array_column($db->all('SELECT role_id FROM applications WHERE candidate_id = ?', [(int) $cand['id']]), 'role_id'));
        $out = [];
        foreach ($db->all("SELECT * FROM roles WHERE status = 'open' AND is_pool = 0 ORDER BY id") as $r) {
            [$res, $why] = $this->decide($cand, $cat, $r);
            $out[(int) $r['id']] = ['role' => $r, 'result' => $res, 'why' => $why, 'on_role' => in_array((int) $r['id'], $on, true),
                'in_band' => $m === null || RoleRouter::expFit($r, $m / 12)[1] === 'in band'];
        }
        $bandFit = null;
        foreach ($out as $p) {
            if ($p['result'] === 'pipeline' && $p['in_band'] && ($p['on_role'] || $p['role']['accepts_routed'])) {
                $bandFit = $p['role']['title'];
                break;
            }
        }
        foreach ($out as &$p) {
            if ($p['on_role'] || $p['result'] !== 'pipeline') {
                continue;
            }
            if (!$p['role']['accepts_routed']) {
                [$p['result'], $p['why']] = ['no_routing', "this position doesn't take CVs sent for others"];
            } elseif (!$p['in_band'] && $bandFit !== null) {
                [$p['result'], $p['why']] = ['band_elsewhere', "fits the band of $bandFit instead"];
            }
        }
        unset($p);
        return $out;
    }

    /** After categorisation (switched on): routing, then Claude's assessment as before (Sonnet re-reads a Haiku-read CV first). */
    public function afterCategorised(int $candidateId): void
    {
        $db = $this->app->db;
        $uid = (int) ($db->col('SELECT uploaded_by FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$candidateId])
            ?: $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1"));
        if ($uid) {
            $this->route($candidateId, null, $uid);
        }
        $fit = new FitAssessor($this->app);
        $model = (string) $db->col("SELECT model FROM cv_ai_reads WHERE candidate_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$candidateId]);
        if (str_contains($model, 'haiku') && $fit->relevantToOpenRole($candidateId)) {
            (new AiReading($this->app))->fullReadFirst($candidateId);
            return;
        }
        if (FitAssessor::enabled($this->app)) {
            $fit->queueForCandidate($candidateId);
        }
    }

    /**
     * Which pool a candidate nobody takes is filed in (17 Nov 2026, Benjamin): the job they applied for decides
     * when the email named one that isn't a position here ("Document Controller, EPC Projects (Chennai)" → the
     * document-control pool for Chennai); otherwise their own field, as Claude read it from their latest job, and
     * the city they live in. Returns [field code, city, the note on the application].
     * @return array{0: string, 1: string, 2: string}
     */
    public function poolFor(array $cand, array $cat): array
    {
        $applied = trim((string) ($cand['applied_for'] ?? ''));
        if ($applied !== '') {
            $code = $this->cat->guessFromTitle(trim((string) preg_replace('/\([^)]*\)\s*$/u', '', $applied)));
            if ($code !== null) {
                $city = MailIntake::cityInTitle($applied) ?: trim((string) $cand['location']);
                return [$code, $city, 'Filed by the job applied for: ' . $applied];
            }
        }
        return [(string) $cat['function_code'], trim((string) $cand['location']), 'Filed by field and city'];
    }

    public function poolTitle(string $code, string $city): string
    {
        return Categoriser::functionName($this->app, $code) . ' · ' . ($city !== '' ? $city : 'City not known');
    }

    /** The talent pool for a field and city, created the first time it is needed. */
    public function pool(string $code, string $city): int
    {
        $db = $this->app->db;
        $title = mb_substr($this->poolTitle($code, $city), 0, 160);
        $id = $db->col('SELECT id FROM roles WHERE is_pool = 1 AND title = ?', [$title]);
        if ($id) {
            return (int) $id;
        }
        $id = (new RoleRouter($this->app))->pool($title);
        $db->update('roles', ['function_codes' => $code, 'location' => $city !== '' ? mb_substr($city, 0, 160) : null], $id);
        return $id;
    }

    // ---------------------------------------------------------------- found in your database

    /**
     * Everyone on file who fits a position by the new rules and isn't on it: 'fits' (the pipeline rules
     * pass) and 'elsewhere' (would fit, but lives in another city and the position is local-only).
     * Free: no Claude. Nobody is added until you say so.
     * @return array{fits: list<array>, elsewhere: list<array>, fields: list<string>}
     */
    public function suggestions(array $role, int $limit = 300): array
    {
        $out = ['fits' => [], 'elsewhere' => [], 'fields' => $this->cat->roleFunctions($role)];
        if (!$out['fields'] || !empty($role['is_pool']) || !in_array($role['status'] ?? 'open', ['open', 'on_hold', 'draft'], true)) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($out['fields']), '?'));
        $rows = $this->app->db->all("SELECT c.*, cc.function_code, cc.secondary_code, cc.specialisation, cc.seniority, cc.preferred_cities, cc.relocation, cc.confidence, cc.source AS cat_source
            FROM candidate_categories cc JOIN candidates c ON c.id = cc.candidate_id
            WHERE cc.is_current = 1 AND (cc.function_code IN ($in) OR cc.secondary_code IN ($in))
              AND " . Contact::putForwardSql('c') . " AND (c.purge_after IS NULL OR c.purge_after >= CURDATE())
              AND c.id NOT IN (SELECT candidate_id FROM applications WHERE role_id = ?)
              AND c.id NOT IN (SELECT candidate_id FROM role_suggestions WHERE role_id = ? AND status = 'dismissed')
              AND c.id NOT IN (SELECT candidate_id FROM cv_requests WHERE status = 'no_answer')
            ORDER BY c.created_at DESC LIMIT 3000", [...$out['fields'], ...$out['fields'], $role['id'], $role['id']]);
        $mid = $role['exp_min'] !== null && $role['exp_max'] !== null ? ((float) $role['exp_min'] + (float) $role['exp_max']) / 2 : null;
        foreach ($rows as $r) {
            [$res, $why] = $this->decide($r, $r, $role);
            if (($res === 'pipeline' || $res === 'other_city') && $this->gate($r) !== null) {
                continue; // no readable CV: nothing to go on yet
            }
            // Someone on another position whose band they fit isn't offered where only the allowance takes them (as routing does).
            if ($res === 'pipeline' && ($this->plan($r, $r)[(int) $role['id']]['result'] ?? 'pipeline') === 'band_elsewhere') {
                continue;
            }
            if ($res === 'pipeline' || $res === 'other_city') {
                $m = exp_now($r);
                $r['why'] = $why;
                $r['in_band'] = $m !== null && RoleRouter::expFit($role, $m / 12)[1] === 'in band';
                $r['distance'] = $m === null || $mid === null ? 99 : abs($m / 12 - $mid);
                $out[$res === 'pipeline' ? 'fits' : 'elsewhere'][] = $r;
            }
        }
        foreach (['fits', 'elsewhere'] as $k) {
            usort($out[$k], fn ($a, $b) => [!$a['in_band'], $a['distance']] <=> [!$b['in_band'], $b['distance']]);
            $out[$k] = array_slice($out[$k], 0, $limit);
        }
        return $out;
    }

    /** Adds people from "Found in your database" to the position (Claude assesses them in the next half-price batch). */
    public function add(int $roleId, array $candidateIds, int $userId): int
    {
        $imp = new CandidateImporter($this->app);
        $n = 0;
        foreach ($candidateIds as $cid) {
            if ($imp->attach((int) $cid, $roleId, $userId, 'database')) {
                $n++;
            }
            $this->app->db->run("INSERT INTO role_suggestions (role_id, candidate_id, status, decided_by) VALUES (?, ?, 'added', ?)
                ON DUPLICATE KEY UPDATE status = 'added', decided_by = VALUES(decided_by), decided_at = NOW()", [$roleId, (int) $cid, $userId]);
        }
        Audit::log('added_from_database', 'role', $roleId, ['n' => $n, 'by' => 'found']);
        return $n;
    }

    /** "Not this one": kept out of the position's list for good. */
    public function dismiss(int $roleId, int $candidateId, int $userId): void
    {
        $this->app->db->run("INSERT INTO role_suggestions (role_id, candidate_id, status, decided_by) VALUES (?, ?, 'dismissed', ?)
            ON DUPLICATE KEY UPDATE status = 'dismissed', decided_by = VALUES(decided_by), decided_at = NOW()", [$roleId, $candidateId, $userId]);
    }

    // ---------------------------------------------------------------- the trial

    /**
     * Records what the old rules did and what the new ones would do for one candidate on every open
     * position. Changes nothing else.
     */
    public function recordTrial(int $candidateId): int
    {
        $db = $this->app->db;
        $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$cand) {
            return 0;
        }
        $cat = $this->cat->current($candidateId);
        $fit = new FitAssessor($this->app);
        $n = 0;
        $plan = $this->plan($cand, $cat);
        $gate = $this->gate($cand);
        foreach ($plan as $p) {
            $role = $p['role'];
            $a = $db->one('SELECT a.id, s.kind FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.candidate_id = ? AND a.role_id = ?', [$candidateId, $role['id']]);
            if (!$a) {
                [$old, $oldWhy] = ['not_on_role', 'not added'];
            } elseif ($a['kind'] !== 'active') {
                [$old, $oldWhy] = ['closed', 'moved on, held or not suitable'];
            } else {
                [$ok, $why] = $fit->relevance((int) $a['id'], 'keywords');
                [$old, $oldWhy] = $ok ? ['pipeline', ''] : ['on_role', $why];
            }
            [$new, $newWhy] = $gate ?? [$p['result'], $p['why']];
            $db->run('INSERT INTO routing_trial (candidate_id, role_id, old_result, old_reason, new_result, new_reason, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE old_result = VALUES(old_result), old_reason = VALUES(old_reason), new_result = VALUES(new_result), new_reason = VALUES(new_reason), updated_at = NOW()',
                [$candidateId, $role['id'], $old, mb_substr($oldWhy, 0, 255), $new, mb_substr($newWhy, 0, 255)]);
            $n++;
        }
        return $n;
    }

    /** The trial for everyone categorised (after a position changes). Free. */
    public function recordTrialForAll(): int
    {
        $n = 0;
        foreach ($this->app->db->all('SELECT DISTINCT candidate_id FROM candidate_categories WHERE is_current = 1') as $r) {
            $this->recordTrial((int) $r['candidate_id']);
            $n++;
        }
        return $n;
    }

    /**
     * The trial in numbers, per open position: agree (both on the pipeline, or both not), would join,
     * would leave; with the people who differ. Ones already moved on are left out.
     */
    public function trialSummary(int $limit = 150): array
    {
        $db = $this->app->db;
        $out = [];
        foreach ($db->all("SELECT id, title, location, location_scope, exp_min, exp_max, work_mode, function_codes FROM roles WHERE status = 'open' AND is_pool = 0 ORDER BY id") as $r) {
            $rows = $db->all("SELECT t.*, c.full_name, c.location FROM routing_trial t JOIN candidates c ON c.id = t.candidate_id WHERE t.role_id = ? AND t.old_result <> 'closed'", [$r['id']]);
            $s = ['role' => $r, 'both' => 0, 'neither' => 0, 'join' => [], 'leave' => [], 'by_reason' => []];
            foreach ($rows as $t) {
                $oldIn = $t['old_result'] === 'pipeline';
                $newIn = $t['new_result'] === 'pipeline';
                if ($oldIn && $newIn) {
                    $s['both']++;
                } elseif (!$oldIn && !$newIn) {
                    $s['neither']++;
                } elseif ($newIn) {
                    $s['join'][] = $t;
                } else {
                    $s['leave'][] = $t;
                    $s['by_reason'][$t['new_result']] = ($s['by_reason'][$t['new_result']] ?? 0) + 1;
                }
            }
            $s['join_n'] = count($s['join']);
            $s['leave_n'] = count($s['leave']);
            $s['join'] = array_slice($s['join'], 0, $limit);
            $s['leave'] = array_slice($s['leave'], 0, $limit);
            $out[] = $s;
        }
        return $out;
    }

    private function skills(): Skills
    {
        return $this->skills ??= new Skills($this->app->db);
    }
}
