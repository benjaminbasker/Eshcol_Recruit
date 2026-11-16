<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Settings › Re-read all CVs: applies an improved reader (new job titles, places, localities,
 * name and experience rules) to candidates already in the database.
 *
 * - Reads each candidate's latest CV text again, as an upload would.
 * - Fills only fields that are empty. Nothing confirmed by you, given by the candidate or
 *   read before is ever changed.
 * - Adds newly recognised skills and job titles, then re-scores every role they are on.
 * - A locality also fills an empty city (Wakad gives Pune).
 * - Refreshes the experience cross-check (stated total, job dates, when the CV was written),
 *   and corrects experience the app itself read from the CV (never yours or the candidate's).
 */
final class Rereader
{
    private const FIELDS = ['full_name', 'first_name', 'email', 'phone', 'current_title', 'exp_months', 'ctc_current_lpa', 'ctc_expected_lpa',
        'notice_days', 'location', 'locality', 'qualifications'];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{candidates:int, changed:int, filled:array<string,int>, skills:int} */
    public function run(int $limit = 2000): array
    {
        $db = $this->app->db;
        $skills = new Skills($db);
        $x = new FieldExtractor($skills);
        $imp = new CandidateImporter($this->app);
        $out = ['candidates' => 0, 'changed' => 0, 'filled' => [], 'skills' => 0];
        $rows = $db->all("SELECT c.*, f.text AS cv_text, f.original_name FROM candidates c
            JOIN cv_files f ON f.id = (SELECT MAX(id) FROM cv_files WHERE candidate_id = c.id AND text IS NOT NULL AND text <> '')
            ORDER BY c.id LIMIT " . (int) $limit);
        foreach ($rows as $c) {
            $out['candidates']++;
            $r = $x->extract((string) $c['cv_text'], (string) $c['original_name']);
            $fields = $r['fields'];
            if (!empty($fields['locality']) && empty($fields['location']) && ($info = $skills->localityOf($fields['locality']))) {
                $fields['location'] = $info['city'];
            }
            $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
            $set = [];
            foreach (self::FIELDS as $k) {
                if (!array_key_exists($k, $c) || ($c[$k] !== null && $c[$k] !== '') || !isset($fields[$k]) || $fields[$k] === '' || $fields[$k] === null) {
                    continue;
                }
                // An email or phone already used by someone else is left alone (it would merge two people).
                if ($k === 'email' && $db->col('SELECT id FROM candidates WHERE email_norm = ? AND id <> ?', [mb_strtolower($fields[$k]), $c['id']])) {
                    continue;
                }
                if ($k === 'phone' && ($n = FieldExtractor::normalisePhone($fields[$k])) && $db->col('SELECT id FROM candidates WHERE phone_norm = ? AND id <> ?', [$n, $c['id']])) {
                    continue;
                }
                $set[$k] = $fields[$k];
                $src[$k] = $r['sources'][$k] ?? 'cv';
                $out['filled'][$k] = ($out['filled'][$k] ?? 0) + 1;
            }
            if (isset($set['exp_months'])) {
                $set['exp_as_of'] = $fields['exp_as_of'] ?? date('Y-m-d');
            }
            // The cross-check always describes the latest CV, unless Claude has read it (its reading is better).
            $byClaude = (bool) $db->col('SELECT 1 FROM cv_ai_reads WHERE candidate_id = ? AND applied_at IS NOT NULL LIMIT 1', [$c['id']]);
            foreach ($byClaude ? (($fields['cv_dated'] ?? null) ? ['cv_dated'] : []) : ['exp_stated_months', 'exp_dates_months', 'cv_dated', 'cv_has_current_job'] as $k) {
                if (array_key_exists($k, $c) && ($fields[$k] ?? null) !== $c[$k]) {
                    $set[$k] = $fields[$k] ?? null;
                }
            }
            // Experience the app read itself is re-read the better way (e.g. grown from the CV's date).
            $expSrc = $src['exp_months'] ?? '';
            if (!isset($set['exp_months']) && in_array($expSrc, ['cv', 'estimated', 'filename', ''], true) && isset($fields['exp_months'])
                && ((int) $fields['exp_months'] !== (int) $c['exp_months'] || ($fields['exp_as_of'] ?? null) !== $c['exp_as_of'])) {
                $set['exp_months'] = $fields['exp_months'];
                $set['exp_as_of'] = $fields['exp_as_of'] ?? date('Y-m-d');
                $src['exp_months'] = $r['sources']['exp_months'] ?? 'cv';
                $out['filled']['exp_months'] = ($out['filled']['exp_months'] ?? 0) + 1;
            }
            if (isset($set['email'])) {
                $set['email_norm'] = mb_strtolower($set['email']);
            }
            if (isset($set['phone'])) {
                $set['phone_norm'] = FieldExtractor::normalisePhone($set['phone']);
            }
            foreach ($r['skills'] as $s) {
                $out['skills'] += $db->run('INSERT IGNORE INTO candidate_skills (candidate_id, skill) VALUES (?, ?)', [$c['id'], $s])->rowCount();
            }
            foreach ($r['titles'] ?? [] as $t) {
                $db->run('INSERT IGNORE INTO candidate_titles (candidate_id, title) VALUES (?, ?)', [$c['id'], $t]);
            }
            if ($set) {
                $set['field_sources'] = json_encode($src);
                $db->update('candidates', $set, (int) $c['id']);
                $out['changed']++;
            }
            foreach ($db->all('SELECT id FROM applications WHERE candidate_id = ?', [$c['id']]) as $a) {
                $imp->scoreApplication((int) $a['id']);
            }
        }
        Audit::log('cvs_reread', null, null, ['candidates' => $out['candidates'], 'changed' => $out['changed']]);
        return $out;
    }
}
