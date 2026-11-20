<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/** Candidates already on file who fit a role, best first (design doc §2.11). Used by the matches page and the weekly summary. */
final class Matcher
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<int, array{cand: array, score: int, breakdown: array}> */
    public function forRole(array $role, int $limit = 30, ?string $addedSince = null): array
    {
        $db = $this->app->db;
        $id = (int) $role['id'];
        $criteria = $db->all('SELECT kind, value FROM role_criteria WHERE role_id = ?', [$id]);
        $importer = new CandidateImporter($this->app);
        $skills = $importer->skills();

        // Narrow the field cheaply first: anyone with a must-have skill, or whose CV mentions one.
        $musts = array_column(array_filter($criteria, fn ($c) => $c['kind'] === 'must'), 'value');
        $pool = [];
        $base = 'SELECT DISTINCT c.id FROM candidates c
                 WHERE ' . Contact::putForwardSql('c') . ' AND (c.purge_after IS NULL OR c.purge_after >= CURDATE())
                   AND c.id NOT IN (SELECT candidate_id FROM applications WHERE role_id = ?)
                   AND ' . ClientRejections::notRejectedSql($this->app, 'c', '(SELECT client_id FROM roles WHERE id = ' . $id . ')')
                 . ($addedSince ? ' AND c.created_at >= ?' : '');
        $args = $addedSince ? [$id, $addedSince] : [$id];
        if ($musts) {
            $canon = array_unique(array_map(fn ($m) => $skills->canonical($m), $musts));
            $in = implode(',', array_fill(0, count($canon), '?'));
            foreach ($db->all("$base AND c.id IN (SELECT candidate_id FROM candidate_skills WHERE skill IN ($in)) LIMIT 400", [...$args, ...$canon]) as $r) {
                $pool[$r['id']] = true;
            }
            $terms = [];
            foreach ($musts as $m) {
                foreach ($skills->termsFor($m) as $t) {
                    if (mb_strlen($t) >= 3) {
                        $terms[] = '"' . str_replace('"', '', $t) . '"';
                    }
                }
            }
            if ($terms) {
                foreach ($db->all("$base AND c.id IN (SELECT candidate_id FROM cv_files WHERE MATCH(text) AGAINST (? IN BOOLEAN MODE)) LIMIT 400", [...$args, implode(' ', array_unique($terms))]) as $r) {
                    $pool[$r['id']] = true;
                }
            }
        } else {
            foreach ($db->all("$base ORDER BY c.created_at DESC LIMIT 400", $args) as $r) {
                $pool[$r['id']] = true;
            }
        }

        $results = [];
        foreach (array_keys($pool) as $cid) {
            $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
            $text = (string) $db->col('SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1', [$cid]);
            $s = $importer->scorer()->score($role, $criteria, $cand, $text);
            $results[] = ['cand' => $cand, 'score' => $s['score'], 'breakdown' => $s['breakdown']];
        }
        usort($results, fn ($a, $b) => [$b['score'], count($b['breakdown']['nice'])] <=> [$a['score'], count($a['breakdown']['nice'])]);
        return array_slice($results, 0, $limit);
    }
}
