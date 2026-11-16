<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The category test (benchmark, 13 Nov 2026): made-up CVs with known answers (resources/category-benchmark.php),
 * categorised by the free guess, Claude Haiku and Claude Sonnet, then marked: the right field, seniority,
 * where they'd work, quotes that really are in the CV, and what the new routing would do on two
 * positions shaped like AdvantEdge's. Costs about ₹2 for Haiku and ₹35 for Sonnet for the whole set.
 * Nothing about real candidates is sent or changed.
 */
final class CategoryTest
{
    public const READERS = ['free' => 'Free guess (job title only)', 'haiku' => 'Claude Haiku', 'sonnet' => 'Claude Sonnet'];

    private ?array $bench = null;

    public function __construct(private readonly App $app, private readonly ?Categoriser $cat = null)
    {
    }

    public function bench(): array
    {
        return $this->bench ??= require dirname(__DIR__, 2) . '/resources/category-benchmark.php';
    }

    /** @return array<string, array> key => case */
    public function cases(): array
    {
        return array_column($this->bench()['cases'], null, 'key');
    }

    public function create(array $readers, int $userId): int
    {
        $readers = array_values(array_intersect(array_keys(self::READERS), $readers)) ?: ['free', 'haiku'];
        $db = $this->app->db;
        $id = $db->insert('category_tests', ['name' => 'Category test ' . date('j M Y, H:i') . ' (' . implode(', ', $readers) . ')', 'created_by' => $userId]);
        foreach (array_keys($this->cases()) as $key) {
            foreach ($readers as $r) {
                $db->insert('category_test_items', ['test_id' => $id, 'case_key' => $key, 'reader' => $r]);
            }
        }
        Audit::log('category_test_created', null, null, ['test' => $id, 'readers' => $readers]);
        return $id;
    }

    /**
     * Runs the next waiting item of a test. Returns false when none is left.
     * @throws \RuntimeException "busy:" or "stop:" (passed on to the page, which waits or stops)
     */
    public function step(int $testId): bool
    {
        $db = $this->app->db;
        $item = $db->one("SELECT * FROM category_test_items WHERE test_id = ? AND status = 'pending' ORDER BY reader = 'free' DESC, id LIMIT 1", [$testId]);
        if (!$item) {
            return false;
        }
        $case = $this->cases()[$item['case_key']] ?? null;
        if (!$case) {
            $db->update('category_test_items', ['status' => 'failed', 'error' => 'This case is no longer in the test set.', 'updated_at' => date('Y-m-d H:i:s')], (int) $item['id']);
            return true;
        }
        $cat = $this->cat ?? new Categoriser($this->app);
        try {
            if ($item['reader'] === 'free') {
                $res = $cat->freeGuess($this->candidate($case) + ['current_title' => $case['title']]);
                $meta = ['tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'ms' => 0];
            } else {
                $a = $cat->ask($case['cv'], [], (string) $item['reader'], false);
                $res = $a['result'];
                $meta = ['tokens_in' => $a['tokens_in'], 'tokens_out' => $a['tokens_out'], 'cost_usd' => $a['cost_usd'], 'ms' => $a['ms']];
            }
        } catch (\RuntimeException $e) {
            if (preg_match('/^(busy|stop):/', $e->getMessage())) {
                throw $e;
            }
            $db->update('category_test_items', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => date('Y-m-d H:i:s')], (int) $item['id']);
            return true;
        }
        $db->update('category_test_items', $meta + ['status' => 'done', 'result' => json_encode($res, JSON_UNESCAPED_UNICODE), 'error' => null, 'updated_at' => date('Y-m-d H:i:s')], (int) $item['id']);
        return true;
    }

    /** What the reader would have found in the CV: the city and years the case gives. */
    private function candidate(array $case): array
    {
        return ['location' => (string) $case['city'], 'locality' => '', 'exp_months' => $case['years'] === null ? null : (int) round($case['years'] * 12), 'exp_as_of' => date('Y-m-d')];
    }

    /**
     * Marks one answer against the case. Pure apart from the dictionary: tested directly.
     * @return array<string, mixed>
     */
    public function mark(array $case, array $res): array
    {
        $e = $case['expect'];
        $fn = (string) ($res['function'] ?? '');
        $sec = (string) ($res['secondary_function'] ?? '');
        $ok = array_merge([$e['function']], $e['also'] ?? []);
        $skills = new Skills($this->app->db);
        $prefs = array_map(fn ($p) => $skills->canonical(trim((string) $p), 'location'), (array) ($res['preferred_cities'] ?? []));
        $wantPrefs = array_map(fn ($p) => $skills->canonical($p, 'location'), $e['preferred'] ?? []);
        $out = [
            'function_exact' => $fn === $e['function'],
            // Acceptable: the right field, a reasonable alternative, or the right field given as the second one.
            'function_ok' => in_array($fn, $ok, true) || $sec === $e['function'],
            'seniority_ok' => in_array((string) ($res['seniority'] ?? ''), $e['seniority'], true),
            'where_ok' => ($res['relocation'] ?? 'unknown') === $e['relocation'] && !array_diff($wantPrefs, $prefs) && !array_diff($prefs, $wantPrefs),
            'evidence_real' => $this->quotesReal((array) ($res['evidence'] ?? []), (string) $case['cv']),
        ];
        $routing = new FunctionRouting($this->app, new Categoriser($this->app));
        $cand = $this->candidate($case);
        $cat = ['function_code' => $fn ?: 'OTH', 'secondary_code' => $sec ?: null, 'preferred_cities' => implode(', ', $prefs), 'relocation' => (string) ($res['relocation'] ?? 'unknown')];
        foreach ($this->bench()['roles'] as $rk => $role) {
            [$got, $why] = $routing->decide($cand, $cat, $role);
            $want = $case['route'][$rk];
            $out["route_$rk"] = $got;
            $out["route_{$rk}_why"] = $why;
            $out["route_{$rk}_ok"] = $got === $want;
            $out["missed_$rk"] = $want === 'pipeline' && $got !== 'pipeline';
            $out["false_$rk"] = $want !== 'pipeline' && $got === 'pipeline';
        }
        return $out;
    }

    /** True when every quote is really in the CV (at least 80% of its words, in any order). */
    private function quotesReal(array $quotes, string $cv): bool
    {
        $norm = fn (string $s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s))));
        $have = array_flip($norm($cv));
        $quotes = array_filter(array_map('strval', $quotes), fn ($q) => trim($q) !== '' && !str_starts_with($q, 'Job title:'));
        if (!$quotes) {
            return false;
        }
        foreach ($quotes as $q) {
            $w = $norm($q);
            if (!$w || count(array_filter($w, fn ($x) => isset($have[$x]))) / count($w) < 0.8) {
                return false;
            }
        }
        return true;
    }

    /**
     * Results by reader: the marks in per cent (all cases, and the held-out ones), missed suitable and
     * false matches, cost and time; and the cases one by one.
     */
    public function results(int $testId): array
    {
        $cases = $this->cases();
        $rows = $this->app->db->all('SELECT * FROM category_test_items WHERE test_id = ? ORDER BY id', [$testId]);
        $by = [];
        $perCase = [];
        foreach ($rows as $r) {
            $reader = (string) $r['reader'];
            $case = $cases[$r['case_key']] ?? null;
            $by[$reader] ??= ['n' => 0, 'pending' => 0, 'failed' => 0, 'cost' => 0.0, 'ms' => 0, 'marks' => [], 'held' => []];
            if ($r['status'] === 'pending') {
                $by[$reader]['pending']++;
                continue;
            }
            if ($r['status'] === 'failed' || !$case) {
                $by[$reader]['failed']++;
                $perCase[$r['case_key']][$reader] = ['error' => $r['error']];
                continue;
            }
            $res = json_decode((string) $r['result'], true) ?: [];
            $m = $this->mark($case, $res);
            $by[$reader]['n']++;
            $by[$reader]['cost'] += (float) $r['cost_usd'];
            $by[$reader]['ms'] += (int) $r['ms'];
            $by[$reader]['marks'][] = $m;
            if (!empty($case['held_out'])) {
                $by[$reader]['held'][] = $m;
            }
            $perCase[$r['case_key']][$reader] = ['res' => $res, 'mark' => $m];
        }
        $pct = function (array $marks, string $k): ?int {
            return $marks ? (int) round(100 * count(array_filter($marks, fn ($m) => !empty($m[$k]))) / count($marks)) : null;
        };
        $sum = [];
        foreach ($by as $reader => $b) {
            $routeOk = array_map(fn ($m) => ['ok' => $m['route_assoc_ok'] && $m['route_manager_ok']], $b['marks']);
            $routeHeld = array_map(fn ($m) => ['ok' => $m['route_assoc_ok'] && $m['route_manager_ok']], $b['held']);
            $sum[$reader] = [
                'n' => $b['n'], 'pending' => $b['pending'], 'failed' => $b['failed'],
                'function_exact' => $pct($b['marks'], 'function_exact'), 'function_ok' => $pct($b['marks'], 'function_ok'),
                'seniority_ok' => $pct($b['marks'], 'seniority_ok'), 'where_ok' => $pct($b['marks'], 'where_ok'),
                'evidence_real' => $reader === 'free' ? null : $pct($b['marks'], 'evidence_real'),
                'routing_ok' => $pct($routeOk, 'ok'), 'routing_ok_held' => $pct($routeHeld, 'ok'), 'function_ok_held' => $pct($b['held'], 'function_ok'), 'held_n' => count($b['held']),
                'missed' => array_sum(array_map(fn ($m) => (int) $m['missed_assoc'] + (int) $m['missed_manager'], $b['marks'])),
                'false' => array_sum(array_map(fn ($m) => (int) $m['false_assoc'] + (int) $m['false_manager'], $b['marks'])),
                'should_join' => array_sum(array_map(fn ($c) => (int) ($c['route']['assoc'] === 'pipeline') + (int) ($c['route']['manager'] === 'pipeline'), $cases)),
                'cost' => $b['cost'], 'per_cv' => $b['n'] ? $b['cost'] / $b['n'] : 0.0, 'ms' => $b['n'] ? (int) round($b['ms'] / $b['n']) : 0,
            ];
        }
        return ['summary' => $sum, 'cases' => $perCase];
    }

    /**
     * The real-CV check: of the categorisations Claude made that you have looked at, how many you left
     * as they were. With a sample to look at next, spread across the fields.
     * @return array{checked:int, kept:int, changed:int, pct:?int, sample: list<array>}
     */
    public function realCheck(int $sample = 60): array
    {
        $db = $this->app->db;
        $checked = (int) $db->col("SELECT COUNT(*) FROM candidate_categories WHERE source = 'ai' AND reviewed_at IS NOT NULL");
        $changed = (int) $db->col("SELECT COUNT(*) FROM candidate_categories WHERE source = 'ai' AND reviewed_at IS NOT NULL AND review_changed = 1");
        // The next ones to look at: unchecked, a few from every field, unsure ones first.
        $rows = $db->all("SELECT cc.candidate_id, cc.function_code, cc.secondary_code, cc.seniority, cc.specialisation, cc.confidence, c.full_name, c.current_title, c.location
            FROM candidate_categories cc JOIN candidates c ON c.id = cc.candidate_id
            WHERE cc.is_current = 1 AND cc.source = 'ai' AND cc.reviewed_at IS NULL
            ORDER BY cc.confidence = 'low' DESC, cc.confidence = 'medium' DESC, RAND() LIMIT 600");
        $per = [];
        foreach ($rows as $r) {
            $per[$r['function_code']][] = $r;
        }
        $pick = [];
        while (count($pick) < $sample && $per) {
            foreach (array_keys($per) as $k) {
                $pick[] = array_shift($per[$k]);
                if (!$per[$k]) {
                    unset($per[$k]);
                }
                if (count($pick) >= $sample) {
                    break;
                }
            }
        }
        return ['checked' => $checked, 'kept' => $checked - $changed, 'changed' => $changed, 'pct' => $checked ? (int) round(100 * ($checked - $changed) / $checked) : null, 'sample' => $pick];
    }
}
