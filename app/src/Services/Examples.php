<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Analyser improvement 6, "Candidates like this one" (approved by Benjamin, 15 Nov 2026).
 *
 * Up to 3 people per position are marked as examples of the person wanted. The app builds a short, blind profile of
 * each (free; no name, contacts, age, gender, college or employer names: the employer type is kept), plus Benjamin's
 * own one line on why they are right. The profiles go into the position's cached part of Claude's instructions, and
 * Claude says how close each new candidate is to them: close / partly / not like them, with a reason.
 *  - Shown, not scored: the fixed score is unchanged.
 *  - Setting examples doesn't mark anyone "role changed" and re-assesses nobody. The card offers to re-assess the
 *    ones assessed before the current examples (half-price batch only).
 */
final class Examples
{
    public const MAX = 3;
    public const LIKE = ['close' => ['Close to your examples', 'yes'], 'partly' => ['Partly like your examples', 'sand'], 'not_like' => ['Not like your examples', 'flag']];

    public function __construct(private readonly App $app)
    {
    }

    /** The examples on a position, with the profile Claude sees. @return list<array{id:int, candidate_id:int, name:string, note:string, profile:string}> */
    public function forRole(int $roleId): array
    {
        $db = $this->app->db;
        $reqs = (new FitAssessor($this->app))->requirements($roleId);
        $out = [];
        foreach ($db->all('SELECT e.id, e.candidate_id, e.note, c.full_name, c.exp_months, c.exp_as_of FROM role_examples e JOIN candidates c ON c.id = e.candidate_id
                WHERE e.role_id = ? ORDER BY e.id', [$roleId]) as $i => $e) {
            $raw = $db->col("SELECT raw FROM cv_ai_reads WHERE candidate_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$e['candidate_id']]);
            $res = $db->col("SELECT f.result FROM fit_assessments f JOIN applications a ON a.id = f.application_id
                WHERE a.candidate_id = ? AND f.role_id = ? AND f.status = 'done'", [$e['candidate_id'], $roleId]);
            $out[] = ['id' => (int) $e['id'], 'candidate_id' => (int) $e['candidate_id'], 'name' => (string) $e['full_name'], 'note' => (string) ($e['note'] ?? ''),
                'profile' => self::profile($i + 1, $raw ? (json_decode((string) $raw, true) ?: []) : [], $res ? (json_decode((string) $res, true) ?: []) : [], $reqs,
                    exp_now($e), (string) ($e['note'] ?? ''))];
        }
        return $out;
    }

    /**
     * One example's blind profile, as Claude sees it. Pure: tested directly.
     * $read: Claude's reading of their CV (jobs, education, software, employer type); $assessed: their assessment on
     * this position (relevant experience, proven requirements), if any; $months: total experience when not assessed.
     */
    public static function profile(int $n, array $read, array $assessed, array $reqs, ?int $months, string $note): string
    {
        $clean = fn (string $s): string => PromptGuard::suspect($s) ? '' : trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
        $parts = [];
        $rel = isset($assessed['relevant_experience_months']) ? (int) $assessed['relevant_experience_months'] : null;
        if ($rel !== null) {
            $parts[] = self::years($rel) . ' of relevant work';
        } elseif ($months !== null) {
            $parts[] = self::years($months) . ' of work in all';
        }
        $edu = [];
        foreach ((array) ($read['education'] ?? []) as $ed) {
            $d = $clean((string) ($ed['degree'] ?? ''));
            $st = $clean((string) ($ed['status'] ?? ''));
            if ($d !== '') {
                $edu[] = $d . ($st !== '' && mb_strtolower($st) !== 'completed' ? " ($st)" : '');
            }
        }
        if (!$edu) {
            $edu = array_values(array_filter(array_map(fn ($q) => $clean((string) $q), (array) ($read['qualifications'] ?? []))));
        }
        if ($edu) {
            $parts[] = implode(', ', array_slice($edu, 0, 3));
        }
        $text = "Example $n. " . ($parts ? implode('. ', $parts) . '.' : '');
        $jobs = [];
        foreach ((array) ($read['jobs'] ?? []) as $j) {
            if (!is_array($j) || !empty($j['internship'])) {
                continue;
            }
            $t = $clean((string) ($j['title'] ?? ''));
            if ($t === '') {
                continue;
            }
            $m = CvReader::monthsFromJobs([$j]);
            $jobs[] = $t . ($m ? ' (' . self::years($m) . ')' : '');
            if (count($jobs) === 4) {
                break;
            }
        }
        if ($jobs) {
            $text .= ' Jobs, newest first: ' . implode('; ', $jobs) . '.';
        }
        $type = $clean((string) ($read['employer_type'] ?? ''));
        if ($type !== '') {
            $text .= " Employer type now: $type.";
        }
        $sw = array_values(array_filter(array_map(fn ($s) => $clean((string) $s), (array) ($read['software'] ?? []))));
        if ($sw) {
            $text .= ' Software: ' . implode(', ', array_slice($sw, 0, 6)) . '.';
        }
        $proven = [];
        foreach ((array) ($assessed['requirements'] ?? []) as $r) {
            $i = (int) ($r['number'] ?? 0) - 1;
            if (FitAssessor::verdict($r['verdict'] ?? null) === 'proven' && isset($reqs[$i])) {
                $proven[] = $reqs[$i]['text'];
            }
        }
        if ($proven) {
            $text .= ' Proven on this position\'s requirements: ' . implode('; ', $proven) . '.';
        }
        $note = $clean($note);
        if ($note !== '') {
            $text .= " Why the recruiter chose them: $note.";
        }
        return rtrim(str_replace('..', '.', $text));
    }

    private static function years(int $m): string
    {
        $y = intdiv($m, 12);
        $r = $m % 12;
        return trim(($y ? "$y yr" . ($y > 1 ? 's' : '') : '') . ($r ? " $r mth" . ($r > 1 ? 's' : '') : '')) ?: 'under a month';
    }

    /** The block that goes into the position's cached instructions ('' when there are no examples). */
    public function block(int $roleId): string
    {
        if (!Agreement::extrasOn($this->app)) {
            return ''; // hidden (16 Nov 2026): assessments work exactly as before
        }
        $ex = $this->forRole($roleId);
        if (!$ex) {
            return '';
        }
        return "Examples of the person the recruiter wants for this role (blind profiles of real candidates):\n<examples>\n"
            . implode("\n", array_map(fn ($e) => str_replace(['<examples>', '</examples>'], '', $e['profile']), $ex)) . "\n</examples>\n"
            . 'Say how close this candidate is to these examples overall (like_examples: "close", "partly" or "not_like") with one sentence in like_note on what makes them alike or different. '
            . 'This is a separate judgement: it must not change your verdicts on the requirements or anything else.';
    }

    /** Which set of examples (and notes) an assessment saw: changes when one is added, removed or its note edited. Null with none. */
    public function version(int $roleId): ?string
    {
        $b = $this->block($roleId);
        return $b === '' ? null : md5($b);
    }

    /** Marks someone as an example (at most MAX). Returns '' or why not. */
    public function add(int $roleId, int $candidateId, int $userId, ?string $note = null): string
    {
        $db = $this->app->db;
        if ($db->col('SELECT id FROM role_examples WHERE role_id = ? AND candidate_id = ?', [$roleId, $candidateId])) {
            return '';
        }
        if ((int) $db->col('SELECT COUNT(*) FROM role_examples WHERE role_id = ?', [$roleId]) >= self::MAX) {
            return 'This position already has ' . self::MAX . ' examples. Remove one first.';
        }
        if (!$db->col("SELECT id FROM cv_ai_reads WHERE candidate_id = ? AND status = 'done' LIMIT 1", [$candidateId])) {
            return 'Their CV has not been read yet, so there is nothing to build a profile from.';
        }
        $db->insert('role_examples', ['role_id' => $roleId, 'candidate_id' => $candidateId, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : null, 'created_by' => $userId]);
        Audit::log('example_added', 'role', $roleId, ['candidate' => $candidateId]);
        return '';
    }

    public function remove(int $roleId, int $candidateId): void
    {
        $this->app->db->run('DELETE FROM role_examples WHERE role_id = ? AND candidate_id = ?', [$roleId, $candidateId]);
        Audit::log('example_removed', 'role', $roleId, ['candidate' => $candidateId]);
    }

    public function setNote(int $roleId, int $exampleId, string $note): void
    {
        $note = trim(mb_substr($note, 0, 250));
        $this->app->db->run('UPDATE role_examples SET note = ? WHERE id = ? AND role_id = ?', [$note !== '' ? $note : null, $exampleId, $roleId]);
    }

    /**
     * "Use the client's yes answers": up to MAX people the client said yes to on the shortlist, highest Claude score
     * first, added to the free places. Returns how many were added.
     */
    public function fromClient(int $roleId, int $userId): int
    {
        $n = 0;
        foreach ($this->app->db->all("SELECT DISTINCT a.candidate_id, COALESCE(f.score, 0) AS score FROM shortlist_items i JOIN applications a ON a.id = i.application_id
                LEFT JOIN fit_assessments f ON f.application_id = a.id
                WHERE a.role_id = ? AND i.decision = 'shortlist' ORDER BY score DESC, a.candidate_id", [$roleId]) as $r) {
            if ((int) $this->app->db->col('SELECT COUNT(*) FROM role_examples WHERE role_id = ?', [$roleId]) >= self::MAX) {
                break;
            }
            $had = (bool) $this->app->db->col('SELECT id FROM role_examples WHERE role_id = ? AND candidate_id = ?', [$roleId, $r['candidate_id']]);
            if (!$had && $this->add($roleId, (int) $r['candidate_id'], $userId) === '') {
                $n++;
            }
        }
        return $n;
    }

    /** How many the client said yes to on this position. */
    public function clientYes(int $roleId): int
    {
        return (int) $this->app->db->col("SELECT COUNT(DISTINCT a.candidate_id) FROM shortlist_items i JOIN applications a ON a.id = i.application_id WHERE a.role_id = ? AND i.decision = 'shortlist'", [$roleId]);
    }

    /** Finished assessments on the position that did not see today's examples. @return list<int> application ids */
    public function before(int $roleId): array
    {
        $v = $this->version($roleId);
        if ($v === null) {
            return [];
        }
        return array_map('intval', array_column($this->app->db->all("SELECT f.application_id FROM fit_assessments f JOIN applications a ON a.id = f.application_id
            JOIN stages s ON s.id = a.stage_id WHERE f.role_id = ? AND f.status = 'done' AND (f.examples_ver IS NULL OR f.examples_ver <> ?) AND s.kind NOT IN ('fail','hold')", [$roleId, $v]), 'application_id'));
    }

    /** "Re-assess them against your examples": queued for the half-price batch. Returns how many. */
    public function reassessBefore(int $roleId): int
    {
        $fit = new FitAssessor($this->app);
        $n = 0;
        foreach ($this->before($roleId) as $appId) {
            if ($fit->queue($appId, true) === '') {
                $n++;
            }
        }
        Audit::log('examples_reassess', 'role', $roleId, ['n' => $n]);
        return $n;
    }
}
