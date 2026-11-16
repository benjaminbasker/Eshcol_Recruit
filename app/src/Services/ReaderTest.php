<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Settings › Reader test: which reader gets CV details right?
 *
 * - Picks real CVs already in the app, hardest first: scans, experience flagged "Check",
 *   CVs with details missing, then some that looked complete.
 * - Runs the free reader, Claude Haiku and Claude Sonnet on each, side by side.
 * - The answer key: where readers agree it is filled in for you; where they disagree, a person
 *   checks the CV and decides. Only checked CVs are scored.
 * - Scores each reader per detail: right, missed (left blank), wrong, or invented (a value the
 *   CV does not contain). Wrong and invented matter most: they look believable.
 * - Never changes a candidate. The test can be deleted afterwards.
 */
final class ReaderTest
{
    public const READERS = ['free' => 'Free reader', 'haiku' => 'Claude Haiku', 'sonnet' => 'Claude Sonnet'];
    public const FIELDS = [
        'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'current_title' => 'Current position',
        'current_company' => 'Current employer', 'location' => 'City', 'locality' => 'Locality',
        'qualifications' => 'Qualification', 'exp_months' => 'Total experience', 'notice_days' => 'Notice (days)',
        'ctc_current_lpa' => 'Current CTC (LPA)',
    ];
    public const GROUPS = ['scan' => 'Scan or no text', 'check' => 'Experience "Check"', 'gaps' => 'Details missing', 'good' => 'Looked complete'];
    /** Spending stops here for one test (US$), whatever happens. */
    public const BUDGET_USD = 5.0;

    public function __construct(private readonly App $app)
    {
    }

    /** Creates a test with up to $max CVs, runs the free reader on them, returns the test id. */
    public function create(int $userId, int $max = 50, int $good = 10): int
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT c.*, f.text AS cv_text, f.original_name, f.text_status, f.storage_path FROM candidates c
            JOIN cv_files f ON f.id = (SELECT MAX(id) FROM cv_files WHERE candidate_id = c.id)
            WHERE " . Contact::sql('c') . ' ORDER BY c.id');
        $groups = ['scan' => [], 'check' => [], 'gaps' => [], 'good' => []];
        foreach ($rows as $c) {
            if (!is_file($this->app->storage((string) $c['storage_path']))) {
                continue;
            }
            $letters = preg_match_all('/\p{L}/u', (string) $c['cv_text']);
            if ($c['text_status'] !== 'ok' || $letters < 80) {
                $groups['scan'][] = (int) $c['id'];
            } elseif (ExperienceCheck::assess($c)['label'] === 'Check') {
                $groups['check'][] = (int) $c['id'];
            } elseif (array_filter(['full_name', 'email', 'phone', 'current_title', 'exp_months', 'location', 'qualifications'], fn ($k) => $c[$k] === null || $c[$k] === '')) {
                $groups['gaps'][] = (int) $c['id'];
            } else {
                $groups['good'][] = (int) $c['id'];
            }
        }
        shuffle($groups['good']);
        $groups['good'] = array_slice($groups['good'], 0, $good);
        $testId = $db->insert('reader_tests', ['name' => 'Reader test ' . date('j M Y, g:i a'), 'created_by' => $userId]);
        $n = 0;
        foreach ($groups as $g => $ids) {
            foreach ($ids as $id) {
                if ($n++ >= $max) {
                    break 2;
                }
                $db->insert('reader_test_items', ['test_id' => $testId, 'candidate_id' => $id, 'reader' => 'free', 'grp' => $g,
                    'fields' => json_encode($this->freeRead($id), JSON_UNESCAPED_UNICODE), 'model' => 'free', 'cost_usd' => 0, 'ms' => 0]);
            }
        }
        Audit::log('reader_test_created', null, null, ['test' => $testId, 'cvs' => min($n, $max)]);
        return $testId;
    }

    /** What the free reader finds in the stored CV text today. */
    public function freeRead(int $candidateId): array
    {
        $f = $this->app->db->one("SELECT text, original_name FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1", [$candidateId]);
        $text = (string) ($f['text'] ?? '');
        if (trim($text) === '') {
            return [];
        }
        $skills = new Skills($this->app->db);
        $fields = (new FieldExtractor($skills))->extract($text, (string) $f['original_name'])['fields'];
        if (!empty($fields['locality']) && empty($fields['location']) && ($info = $skills->localityOf($fields['locality']))) {
            $fields['location'] = $info['city'];
        }
        return array_intersect_key($fields, self::FIELDS + ['exp_stated_months' => 1, 'exp_dates_months' => 1, 'ctc_expected_lpa' => 1]);
    }

    /** Runs one AI reader on one CV of the test and saves what it found (or why it failed). */
    public function runAi(int $testId, int $candidateId, string $reader, ?CvReader $cvReader = null): array
    {
        $db = $this->app->db;
        if (!$db->col("SELECT id FROM reader_test_items WHERE test_id = ? AND candidate_id = ? AND reader = 'free'", [$testId, $candidateId])) {
            throw new \RuntimeException('That CV is not part of this test.');
        }
        if ($this->spent($testId) >= self::BUDGET_USD) {
            throw new \RuntimeException('stop:This test has reached its spending limit of US$' . number_format(self::BUDGET_USD, 2) . '.');
        }
        $row = ['test_id' => $testId, 'candidate_id' => $candidateId, 'reader' => $reader];
        try {
            $r = ($cvReader ?? new CvReader($this->app))->read($candidateId, $reader);
            $row += ['fields' => json_encode($r['fields'], JSON_UNESCAPED_UNICODE), 'raw' => json_encode($r['raw'], JSON_UNESCAPED_UNICODE),
                'model' => $r['model'], 'tokens_in' => $r['tokens_in'], 'tokens_out' => $r['tokens_out'], 'cost_usd' => $r['cost_usd'],
                'ms' => $r['ms'], 'error' => null];
        } catch (\RuntimeException $e) {
            // Busy: try again later. Stop: no CV can be read (key or credit), so nothing is saved.
            if (str_starts_with($e->getMessage(), 'busy:') || str_starts_with($e->getMessage(), 'stop:')) {
                throw $e;
            }
            $row += ['fields' => null, 'raw' => null, 'model' => CvReader::MODELS[$reader][0] ?? $reader, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $this->upsert($row);
        return $row;
    }

    public function spent(int $testId): float
    {
        return (float) $this->app->db->col('SELECT COALESCE(SUM(cost_usd), 0) FROM reader_test_items WHERE test_id = ?', [$testId]);
    }

    /** AI runs still to do: [[candidate_id, reader], ...]. */
    public function pending(int $testId): array
    {
        $db = $this->app->db;
        $out = [];
        foreach ($db->all("SELECT candidate_id FROM reader_test_items WHERE test_id = ? AND reader = 'free' ORDER BY id", [$testId]) as $r) {
            foreach (array_keys(CvReader::MODELS) as $reader) {
                $done = $db->one('SELECT error FROM reader_test_items WHERE test_id = ? AND candidate_id = ? AND reader = ?', [$testId, $r['candidate_id'], $reader]);
                if (!$done) {
                    $out[] = [(int) $r['candidate_id'], $reader];
                }
            }
        }
        return $out;
    }

    /** Everything about one test: [candidate_id => ['grp'=>, 'name'=>, 'readers'=>[reader=>row], 'truth'=>?array]]. */
    public function items(int $testId): array
    {
        $out = [];
        foreach ($this->app->db->all("SELECT i.*, c.full_name, f.id AS cv_file_id, f.ext FROM reader_test_items i JOIN candidates c ON c.id = i.candidate_id
                LEFT JOIN cv_files f ON f.id = (SELECT MAX(id) FROM cv_files WHERE candidate_id = c.id)
                WHERE i.test_id = ? ORDER BY i.id", [$testId]) as $r) {
            $cid = (int) $r['candidate_id'];
            $out[$cid] ??= ['grp' => null, 'name' => $r['full_name'], 'cv_file_id' => $r['cv_file_id'], 'ext' => $r['ext'], 'readers' => [], 'truth' => null];
            $r['fields'] = $r['fields'] !== null ? (json_decode((string) $r['fields'], true) ?: []) : null;
            if ($r['reader'] === 'truth') {
                $out[$cid]['truth'] = $r['fields'];
            } else {
                $out[$cid]['readers'][$r['reader']] = $r;
                if ($r['reader'] === 'free') {
                    $out[$cid]['grp'] = $r['grp'];
                }
            }
        }
        return $out;
    }

    /**
     * The answer key offered for review: a detail every reader agrees on (or that no reader
     * found) is filled in; otherwise it is left blank and listed in 'decide'.
     * @return array{values: array<string, string>, decide: string[]}
     */
    public function suggestTruth(array $item): array
    {
        $values = [];
        $decide = [];
        $h = $item['readers']['haiku']['fields'] ?? null;
        $s = $item['readers']['sonnet']['fields'] ?? null;
        $f = $item['readers']['free']['fields'] ?? [];
        foreach (array_keys(self::FIELDS) as $k) {
            $hv = $h[$k] ?? null;
            $sv = $s[$k] ?? null;
            $fv = $f[$k] ?? null;
            $empty = fn ($v) => $v === null || $v === '';
            if ($h !== null && $s !== null && $empty($hv) && $empty($sv) && $empty($fv)) {
                $values[$k] = '';
            } elseif (!$empty($hv) && !$empty($sv) && self::match($k, (string) $sv, $hv) && ($empty($fv) || self::match($k, (string) $sv, $fv))) {
                $values[$k] = $sv;
            } else {
                $values[$k] = '';
                $decide[] = $k;
            }
        }
        return ['values' => $values, 'decide' => $decide];
    }

    /** Saves the checked answers for one CV (in display form: experience in years). */
    public function saveTruth(int $testId, int $candidateId, array $input): void
    {
        $fields = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $v = trim(mb_substr((string) ($input[$k] ?? ''), 0, 300));
            if ($v === '') {
                continue;
            }
            $fields[$k] = $k === 'exp_months' ? (int) round((float) str_replace(',', '.', $v) * 12)
                : (in_array($k, ['notice_days'], true) ? (int) $v : ($k === 'ctc_current_lpa' ? (float) str_replace(',', '.', $v) : $v));
        }
        $this->upsert(['test_id' => $testId, 'candidate_id' => $candidateId, 'reader' => 'truth',
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'model' => 'checked', 'cost_usd' => 0]);
    }

    /**
     * The scorecard over checked CVs.
     * @return array{cvs:int, checked:int, readers: array<string, array>, fields: array<string, array<string, array>>, mistakes: array}
     */
    public function score(int $testId): array
    {
        $items = $this->items($testId);
        $tally = fn () => ['right' => 0, 'missed' => 0, 'wrong' => 0, 'invented' => 0, 'blank_ok' => 0];
        $readers = [];
        $fields = [];
        $mistakes = [];
        foreach (self::READERS as $r => $_) {
            $readers[$r] = $tally() + ['cost' => 0.0, 'ms' => 0, 'runs' => 0, 'errors' => 0, 'cvs' => 0];
        }
        $checked = 0;
        foreach ($items as $cid => $it) {
            foreach ($it['readers'] as $r => $row) {
                $readers[$r]['cost'] += (float) $row['cost_usd'];
                $readers[$r]['ms'] += (int) $row['ms'];
                $readers[$r]['runs']++;
                $readers[$r]['errors'] += $row['error'] ? 1 : 0;
            }
            if ($it['truth'] === null) {
                continue;
            }
            $checked++;
            foreach (self::READERS as $r => $_) {
                if (!isset($it['readers'][$r])) {
                    continue;
                }
                $readers[$r]['cvs']++;
                $got = $it['readers'][$r]['fields'] ?? [];
                foreach (array_keys(self::FIELDS) as $k) {
                    $t = $it['truth'][$k] ?? null;
                    $v = $got[$k] ?? null;
                    $res = self::judge($k, $t, $v);
                    $readers[$r][$res]++;
                    $fields[$k][$r] ??= $tally();
                    $fields[$k][$r][$res]++;
                    if (in_array($res, ['wrong', 'invented', 'missed'], true)) {
                        $mistakes[] = ['candidate_id' => $cid, 'name' => $it['name'], 'reader' => $r, 'field' => $k, 'kind' => $res,
                            'truth' => self::display($k, $t), 'got' => self::display($k, $v)];
                    }
                }
            }
        }
        foreach ($readers as $r => &$x) {
            $stated = $x['right'] + $x['missed'] + $x['wrong'];
            $all = $stated + $x['invented'] + $x['blank_ok'];
            $x['right_pct'] = $stated ? round(100 * $x['right'] / $stated, 1) : null;
            $x['missed_pct'] = $stated ? round(100 * $x['missed'] / $stated, 1) : null;
            $x['wrong_pct'] = $all ? round(100 * ($x['wrong'] + $x['invented']) / $all, 1) : null;
            $x['cost_per_cv'] = $x['runs'] ? $x['cost'] / $x['runs'] : 0.0;
            $x['secs_per_cv'] = $x['runs'] ? round($x['ms'] / $x['runs'] / 1000, 1) : 0.0;
        }
        unset($x);
        return ['cvs' => count($items), 'checked' => $checked, 'readers' => $readers, 'fields' => $fields, 'mistakes' => $mistakes];
    }

    /** right | missed | wrong | invented | blank_ok */
    public static function judge(string $k, mixed $truth, mixed $got): string
    {
        $empty = fn ($v) => $v === null || $v === '';
        if ($empty($truth)) {
            return $empty($got) ? 'blank_ok' : 'invented';
        }
        if ($empty($got)) {
            return 'missed';
        }
        return self::match($k, (string) $truth, $got) ? 'right' : 'wrong';
    }

    /** Does a reader's value match the answer? The answer may list alternatives as "a | b". */
    public static function match(string $k, string $truth, mixed $got): bool
    {
        if ($k === 'exp_months') {
            return abs((int) $truth - (int) $got) <= 6;
        }
        if ($k === 'ctc_current_lpa') {
            return abs((float) $truth - (float) $got) <= 0.15;
        }
        if ($k === 'notice_days') {
            return (int) $truth === (int) $got;
        }
        if ($k === 'phone') {
            $t = FieldExtractor::normalisePhone($truth) ?? preg_replace('/\D/', '', $truth);
            $g = FieldExtractor::normalisePhone((string) $got) ?? preg_replace('/\D/', '', (string) $got);
            return $t !== '' && $t === $g;
        }
        $alts = array_filter(array_map(fn ($a) => self::norm($k, $a), $k === 'qualifications' ? preg_split('/[,;|]/', $truth) : explode('|', $truth)));
        if ($k === 'qualifications') {
            $gotItems = array_filter(array_map(fn ($a) => self::norm($k, $a), preg_split('/[,;|]/', (string) $got)));
            return (bool) array_intersect($alts, $gotItems);
        }
        $g = self::norm($k, (string) $got);
        if ($k === 'full_name') {
            $sorted = function (string $s) { $w = explode(' ', $s); sort($w); return implode(' ', $w); };
            foreach ($alts as $a) {
                if ($sorted($a) === $sorted($g)) {
                    return true;
                }
            }
            return false;
        }
        return in_array($g, $alts, true);
    }

    private static function norm(string $k, string $v): string
    {
        $v = mb_strtolower(trim($v));
        if ($k === 'email') {
            return $v;
        }
        $v = str_replace('&', ' and ', $v);
        if ($k === 'qualifications') {
            return preg_replace('/[^\p{L}\p{N}]/u', '', $v);
        }
        if ($k === 'current_company') {
            $v = preg_replace('/\b(pvt|private|ltd|limited|llp|inc|co)\b\.?/u', ' ', $v);
        }
        $v = preg_replace('/[^\p{L}\p{N} ]/u', ' ', $v);
        return trim(preg_replace('/\s+/', ' ', $v));
    }

    /** A stored value as a person reads it (experience in years). */
    public static function display(string $k, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if ($k === 'exp_months') {
            return rtrim(rtrim(number_format((int) $v / 12, 1, '.', ''), '0'), '.');
        }
        return (string) $v;
    }

    public function delete(int $testId): void
    {
        $this->app->db->run('DELETE FROM reader_tests WHERE id = ?', [$testId]);
        Audit::log('reader_test_deleted', null, null, ['test' => $testId]);
    }

    private function upsert(array $row): void
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO reader_test_items (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", array_diff($cols, ['test_id', 'candidate_id', 'reader']))) . ', updated_at = NOW()';
        $this->app->db->run($sql, array_values($row));
    }
}
