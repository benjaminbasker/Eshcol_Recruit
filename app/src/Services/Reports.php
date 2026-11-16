<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Reports (B10, 14 Nov 2026): management numbers from the data already kept. The owner's only.
 *  1. Time to fill per position (opened → first offer → joined);
 *  2. the funnel: how far applications got;
 *  3. where hires come from: each source's CVs, submissions, interviews and joinings;
 *  4. what Claude cost, per month (reading, field of work, assessments) and per position;
 *  5. placements and money (from Placements).
 */
final class Reports
{
    public const PERIODS = ['month' => 'This month', 'quarter' => 'Last 3 months', 'fy' => 'This financial year', 'all' => 'All time'];

    public function __construct(private readonly App $app)
    {
    }

    /** [from, to] dates for a period name. */
    public static function range(string $period, ?int $now = null): array
    {
        $now ??= time();
        $fy = (int) date('n', $now) >= 4 ? (int) date('Y', $now) : (int) date('Y', $now) - 1;
        return match ($period) {
            'month' => [date('Y-m-01', $now), date('Y-m-d', $now)],
            'quarter' => [date('Y-m-d', strtotime('-3 months', $now)), date('Y-m-d', $now)],
            'fy' => ["$fy-04-01", date('Y-m-d', $now)],
            default => ['2000-01-01', date('Y-m-d', $now)],
        };
    }

    /** The stage sorts that mark each step of the funnel. */
    private function steps(): array
    {
        $db = $this->app->db;
        $stages = $db->all("SELECT id, name, sort, kind FROM stages WHERE is_active = 1 AND kind IN ('active','success') ORDER BY sort");
        $offer = (new Placements($this->app))->offerStageId();
        $sortOf = array_column($stages, 'sort', 'id');
        $find = function (string $pattern) use ($stages): ?int {
            foreach ($stages as $s) {
                if (preg_match($pattern, (string) $s['name'])) {
                    return (int) $s['sort'];
                }
            }
            return null;
        };
        $out = ['Applied' => (int) ($stages[0]['sort'] ?? 0)];
        foreach (['Screened' => '/screen/i', 'Shortlisted' => '/shortlist/i', 'Sent to client' => '/submitted|client$/i', 'Client interview' => '/interview/i'] as $label => $p) {
            $s = $find($p);
            if ($s !== null) {
                $out[$label] = $s;
            }
        }
        if ($offer && isset($sortOf[$offer])) {
            $out['Offer made'] = (int) $sortOf[$offer];
        }
        foreach ($stages as $s) {
            if ($s['kind'] === 'success') {
                $out['Joined'] = (int) $s['sort'];
                break;
            }
        }
        asort($out);
        return $out;
    }

    /** Applications started in the period, with how far each got (the highest active or success stage reached). */
    private function reached(string $from, string $to): array
    {
        return $this->app->db->all("SELECT a.id, a.role_id, COALESCE(NULLIF(a.channel, ''), NULLIF(c.channel, ''), '') AS channel,
                GREATEST(COALESCE((SELECT MAX(s2.sort) FROM stage_history h JOIN stages s2 ON s2.id = h.to_stage_id WHERE h.application_id = a.id AND s2.kind IN ('active','success')), 0),
                         IF(s.kind IN ('active','success'), s.sort, 0)) AS reached
            FROM applications a JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = a.role_id JOIN candidates c ON c.id = a.candidate_id
            WHERE r.is_pool = 0 AND DATE(a.applied_at) BETWEEN ? AND ?", [$from, $to]);
    }

    /** 2. The funnel: how many reached each step, and the share of the step before. */
    public function funnel(string $from, string $to): array
    {
        $rows = $this->reached($from, $to);
        $out = [];
        $prev = null;
        foreach ($this->steps() as $label => $sort) {
            $n = count(array_filter($rows, fn ($r) => (int) $r['reached'] >= $sort));
            $out[] = ['step' => $label, 'n' => $n, 'of_prev' => $prev ? (int) round(100 * $n / $prev) : null];
            $prev = $n ?: null;
        }
        return $out;
    }

    /** 3. Where hires come from: per source, CVs (applications), sent to client, interviewed, joined. */
    public function sources(string $from, string $to): array
    {
        $steps = $this->steps();
        $sent = $steps['Sent to client'] ?? PHP_INT_MAX;
        $iv = $steps['Client interview'] ?? PHP_INT_MAX;
        $joined = $steps['Joined'] ?? PHP_INT_MAX;
        $out = [];
        foreach ($this->reached($from, $to) as $r) {
            $k = $r['channel'] !== '' ? channel_name($r['channel']) : 'Not recorded';
            $out[$k] ??= ['source' => $k, 'cvs' => 0, 'sent' => 0, 'interviewed' => 0, 'joined' => 0];
            $out[$k]['cvs']++;
            $out[$k]['sent'] += (int) $r['reached'] >= $sent ? 1 : 0;
            $out[$k]['interviewed'] += (int) $r['reached'] >= $iv ? 1 : 0;
            $out[$k]['joined'] += (int) $r['reached'] >= $joined ? 1 : 0;
        }
        usort($out, fn ($a, $b) => [$b['joined'], $b['interviewed'], $b['cvs']] <=> [$a['joined'], $a['interviewed'], $a['cvs']]);
        return $out;
    }

    /** 1. Time to fill: per position opened in the period, days to the first offer and to the first joining. */
    public function timeToFill(string $from, string $to): array
    {
        $db = $this->app->db;
        $offerStage = (new Placements($this->app))->offerStageId();
        $rows = $db->all("SELECT r.id, r.title, r.status, cl.name AS client, COALESCE(r.opened_at, DATE(r.created_at)) AS opened,
                (SELECT MIN(p.offer_date) FROM placements p WHERE p.role_id = r.id) AS first_offer_p,
                (SELECT MIN(DATE(h.created_at)) FROM stage_history h JOIN applications a ON a.id = h.application_id WHERE a.role_id = r.id AND h.to_stage_id = ?) AS first_offer_h,
                (SELECT MIN(p.joined_on) FROM placements p WHERE p.role_id = r.id AND p.status IN ('joined','left')) AS first_join
            FROM roles r JOIN clients cl ON cl.id = r.client_id
            WHERE r.is_pool = 0 AND COALESCE(r.opened_at, DATE(r.created_at)) BETWEEN ? AND ? ORDER BY opened DESC", [$offerStage ?? 0, $from, $to]);
        $days = fn ($a, $b) => $a && $b ? max(0, (int) round((strtotime($b) - strtotime($a)) / 86400)) : null;
        foreach ($rows as &$r) {
            $offer = array_filter([$r['first_offer_p'], $r['first_offer_h']]);
            $r['first_offer'] = $offer ? min($offer) : null;
            $r['to_offer'] = $days($r['opened'], $r['first_offer']);
            $r['to_join'] = $days($r['opened'], $r['first_join']);
            $r['open_days'] = $r['first_join'] ? null : $days($r['opened'], date('Y-m-d'));
        }
        return $rows;
    }

    /** 4. Claude's cost per month (last 6 months): CV reading, field of work, assessments. In US$. */
    public function claudeByMonth(int $months = 6): array
    {
        $db = $this->app->db;
        $since = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = date('Y-m', strtotime("$since +$i months"));
            $out[$m] = ['month' => $m, 'reading' => 0.0, 'categories' => 0.0, 'assessments' => 0.0, 'n_read' => 0, 'n_assessed' => 0];
        }
        foreach ([['cv_ai_reads', 'reading', 'n_read'], ['candidate_categories', 'categories', null], ['fit_assessments', 'assessments', 'n_assessed']] as [$t, $k, $nk]) {
            foreach ($db->all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(cost_usd) AS c, COUNT(cost_usd) AS n FROM $t WHERE created_at >= ? GROUP BY m", [$since]) as $r) {
                if (isset($out[$r['m']])) {
                    $out[$r['m']][$k] = (float) $r['c'];
                    if ($nk) {
                        $out[$r['m']][$nk] = (int) $r['n'];
                    }
                }
            }
        }
        return array_values($out);
    }

    /** 4b. Assessment cost per position, in the period. */
    public function claudeByRole(string $from, string $to): array
    {
        return $this->app->db->all("SELECT r.title, cl.name AS client, COUNT(f.id) AS n, SUM(f.cost_usd) AS cost FROM fit_assessments f
            JOIN roles r ON r.id = f.role_id JOIN clients cl ON cl.id = r.client_id
            WHERE f.cost_usd IS NOT NULL AND DATE(f.created_at) BETWEEN ? AND ? GROUP BY r.id ORDER BY cost DESC", [$from, $to]);
    }
}
