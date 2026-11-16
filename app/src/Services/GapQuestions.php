<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Asking candidates about gaps in their CV (audit B7, wording approved by Benjamin, 15 Nov 2026).
 * When the assessment finds a requirement "not mentioned" in the CV, the "Complete your application" form asks
 * "Have you done this in a job?" with the requirement quoted: at most 3 per position, critical ones first, worked
 * out when the form opens (so a later assessment adds its questions; nothing new is emailed). Free: no AI call.
 * Their answer, until someone checks it on the call:
 *   - "Yes" (current or earlier job): "related, check on the call", half credit;
 *   - "Some exposure": a quarter;
 *   - "No": stays "not mentioned" (shown in red on a critical requirement).
 * The answers are for Eshcol only: never on the client's shortlist.
 */
final class GapQuestions
{
    public const MAX = 3;
    public const PROMPT = 'Have you done this in a job?';
    public const HEADING = 'A few questions about your experience';
    public const INTRO = 'Your CV may not mention everything you have done. Please tell us about these.';
    public const ANSWERS = [
        'recent' => 'Yes, in my current or most recent job',
        'earlier' => 'Yes, in an earlier job',
        'some' => 'Some exposure (I assisted, or in training or an internship)',
        'no' => 'No, not yet',
    ];
    /** How the answer shows to you, next to the requirement. */
    public const SAYS = ['recent' => 'Candidate says: yes, current or recent job', 'earlier' => 'Candidate says: yes, an earlier job',
        'some' => 'Candidate says: some exposure', 'no' => 'Candidate says: no, not yet'];
    public const DETAIL_KEY = 'gap_detail';
    public const DETAIL_PROMPT = 'Where did you do this, and for how long?';
    public const DETAIL_HINT = 'Optional. For example: "GST returns for 30 clients at Shah & Co, 2022 to now."';

    public function __construct(private readonly App $app)
    {
    }

    public static function hash(string $requirement): string
    {
        return md5(mb_strtolower(preg_replace('/\s+/u', ' ', trim($requirement))));
    }

    /**
     * Claude's answer with the candidate's gap answers applied. Idempotent: the CV's own verdict is kept in
     * "verdict_cv" and restored first, so applying again (or with no answers) never stacks. Pure: tested directly.
     * @param array<string, string> $answers req_hash => recent|earlier|some|no
     */
    public static function apply(array $res, array $reqs, array $answers): array
    {
        foreach ((array) ($res['requirements'] ?? []) as $k => $row) {
            $i = (int) ($row['number'] ?? 0) - 1;
            if (isset($row['verdict_cv'])) {
                $row['verdict'] = $row['verdict_cv'];
            }
            unset($row['verdict_cv'], $row['candidate_says'], $row['gap_answer'], $row['gap_credit']);
            $a = isset($reqs[$i]) ? ($answers[self::hash((string) $reqs[$i]['text'])] ?? null) : null;
            if ($a !== null && isset(self::ANSWERS[$a]) && FitAssessor::verdict($row['verdict'] ?? null) === 'not_mentioned') {
                $row['verdict_cv'] = 'not_mentioned';
                $row['gap_answer'] = $a;
                $row['candidate_says'] = self::SAYS[$a];
                if ($a === 'recent' || $a === 'earlier') {
                    $row['verdict'] = 'related';
                } elseif ($a === 'some') {
                    $row['gap_credit'] = 0.25;
                }
            }
            $res['requirements'][$k] = $row;
        }
        return $res;
    }

    /** @return array<string, string> req_hash => answer, for one application */
    public function answers(int $applicationId): array
    {
        return array_column($this->app->db->all('SELECT req_hash, answer FROM gap_answers WHERE application_id = ?', [$applicationId]), 'answer', 'req_hash');
    }

    /**
     * The gaps to ask one candidate about on one position: requirements the assessment found "not mentioned", not
     * yet answered, critical first, at most 3. None while the "Share your updated CV" campaign is waiting on them,
     * or when the assessment is out of date (the position's requirements changed since).
     * @return list<array{text:string, critical:bool, hash:string, application_id:int}>
     */
    public function forRole(int $candidateId, int $roleId): array
    {
        $db = $this->app->db;
        if ($db->col("SELECT 1 FROM cv_requests WHERE candidate_id = ? AND status IN ('queued','sent')", [$candidateId])) {
            return [];
        }
        $appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$candidateId, $roleId]);
        if (!$appId) {
            return [];
        }
        $fit = (new FitAssessor($this->app))->forApplication($appId);
        if (!$fit || $fit['status'] !== 'done' || $fit['outdated']) {
            return [];
        }
        $done = $this->answers($appId);
        $gaps = [];
        foreach ($fit['requirements'] as $i => $r) {
            $row = null;
            foreach ((array) ($fit['result']['requirements'] ?? []) as $x) {
                if ((int) ($x['number'] ?? 0) === $i + 1) {
                    $row = $x;
                }
            }
            $base = $row ? FitAssessor::verdict($row['verdict_cv'] ?? $row['verdict'] ?? null) : null;
            $h = self::hash((string) $r['text']);
            if ($base === 'not_mentioned' && !isset($done[$h]) && trim((string) $r['text']) !== '') {
                $gaps[] = ['text' => (string) $r['text'], 'critical' => (bool) $r['critical'], 'hash' => $h, 'application_id' => $appId, 'order' => $i];
            }
        }
        usort($gaps, fn ($a, $b) => [$b['critical'], $a['order']] <=> [$a['critical'], $b['order']]);
        return array_map(fn ($g) => array_diff_key($g, ['order' => 1]), array_slice($gaps, 0, self::MAX));
    }

    /**
     * Saves the gap answers from a submitted form and re-scores each position they were asked about (free).
     * @param array<string, array> $answers the form's answers by key
     * @param array<string, array> $asked gap key => the gap (from forRole) it asked about
     */
    public function save(int $candidateId, ?int $questionnaireId, array $answers, array $asked): int
    {
        $db = $this->app->db;
        $detail = isset($answers[self::DETAIL_KEY]) ? mb_substr((string) $answers[self::DETAIL_KEY]['answer'], 0, 500) : null;
        $apps = [];
        foreach ($asked as $key => $g) {
            $a = array_search((string) ($answers[$key]['answer'] ?? ''), self::ANSWERS, true);
            if ($a === false) {
                continue;
            }
            $roleId = (int) $db->col('SELECT role_id FROM applications WHERE id = ?', [$g['application_id']]);
            $db->run('INSERT INTO gap_answers (application_id, candidate_id, role_id, req_hash, requirement, answer, detail, questionnaire_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE answer = VALUES(answer), detail = VALUES(detail), questionnaire_id = VALUES(questionnaire_id)',
                [$g['application_id'], $candidateId, $roleId, $g['hash'], mb_substr($g['text'], 0, 255), $a, $detail, $questionnaireId]);
            $apps[(int) $g['application_id']] = true;
        }
        foreach (array_keys($apps) as $appId) {
            $this->rescore($appId);
        }
        if ($apps) {
            Audit::log('gap_answers', 'candidate', $candidateId, ['positions' => count($apps)]);
        }
        return count($apps);
    }

    /** Applies the answers to the stored assessment and re-scores it (free). */
    public function rescore(int $applicationId): ?array
    {
        $db = $this->app->db;
        $f = $db->one("SELECT * FROM fit_assessments WHERE application_id = ? AND status = 'done' AND result IS NOT NULL", [$applicationId]);
        $role = $f ? $db->one('SELECT * FROM roles WHERE id = ?', [$f['role_id']]) : null;
        $res = $f ? json_decode((string) $f['result'], true) : null;
        if (!$f || !$role || !is_array($res)) {
            return null;
        }
        $fa = new FitAssessor($this->app);
        $reqs = $fa->requirements((int) $role['id']);
        $res = self::apply($res, $reqs, $this->answers($applicationId));
        $s = FitAssessor::score($res, $reqs, $role, $fa->ladder($role));
        $db->update('fit_assessments', ['result' => json_encode($res, JSON_UNESCAPED_UNICODE), 'score' => $s['score'], 'band' => $s['band'],
            'breakdown' => json_encode($s, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')], (int) $f['id']);
        return $s;
    }

    /** For Reports › Responses: how candidates answered. @return array{yes:int, some:int, no:int, people:int} */
    public function summary(): array
    {
        $r = $this->app->db->one("SELECT SUM(answer IN ('recent','earlier')) AS n_yes, SUM(answer = 'some') AS n_some, SUM(answer = 'no') AS n_no, COUNT(DISTINCT candidate_id) AS n_people FROM gap_answers") ?: [];
        return ['yes' => (int) ($r['n_yes'] ?? 0), 'some' => (int) ($r['n_some'] ?? 0), 'no' => (int) ($r['n_no'] ?? 0), 'people' => (int) ($r['n_people'] ?? 0)];
    }
}
