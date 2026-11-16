<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * The free scorecard. 100 points split by the role's weights (default: must-haves 50,
 * experience 15, qualification 10, location 10, notice 10, CTC 5).
 *
 * Rules worth knowing:
 *  - A criterion the role doesn't use (e.g. no CTC budget set) scores full for everyone,
 *    so it neither helps nor hurts the ranking.
 *  - A detail missing from the candidate scores half and is listed as unknown, so a
 *    sparse CV is neither rewarded nor thrown out. Confirming it fixes the score.
 *  - Nice-to-haves earn no points; they are shown and break ties.
 */
final class Scorer
{
    public const DEFAULT_WEIGHTS = ['must' => 50, 'experience' => 15, 'qualification' => 10, 'location' => 10, 'notice' => 10, 'ctc' => 5];

    public function __construct(private readonly Skills $skills)
    {
    }

    /**
     * @param array $role      roles row
     * @param array $criteria  role_criteria rows
     * @param array $cand      candidates row
     * @return array{score:int, breakdown:array}
     */
    public function score(array $role, array $criteria, array $cand, string $cvText): array
    {
        $w = self::DEFAULT_WEIGHTS;
        if (!empty($role['weights'])) {
            $w = array_merge($w, array_map('intval', (array) json_decode($role['weights'], true)));
        }
        $by = ['must' => [], 'nice' => [], 'qualification' => [], 'location' => [], 'knockout' => []];
        foreach ($criteria as $c) {
            $by[$c['kind']][] = $c['value'];
        }

        $b = [];
        $unknown = [];

        // Must-haves, with the sentence each one was found in.
        $matched = [];
        $missing = [];
        foreach ($by['must'] as $v) {
            $hit = $this->skills->locate($cvText, $v);
            if ($hit) {
                $matched[] = ['value' => $v, 'snippet' => Skills::snippet($cvText, $hit['offset'], strlen($hit['term']))];
            } else {
                $missing[] = $v;
            }
        }
        $b['must'] = [
            'label' => 'Must-haves',
            'max' => $w['must'],
            'points' => $by['must'] ? $w['must'] * count($matched) / count($by['must']) : $w['must'],
            'note' => $by['must'] ? count($matched) . ' of ' . count($by['must']) : 'none set',
            'matched' => $matched,
            'missing' => $missing,
        ];

        // Experience against the role's band.
        $min = $role['exp_min'] !== null ? (float) $role['exp_min'] : null;
        $max = $role['exp_max'] !== null ? (float) $role['exp_max'] : null;
        // Today's experience: the last known figure grown by the time since (see exp_now()).
        $months = exp_now($cand);
        $grown = exp_is_estimate($cand) ? ' (grown to today)' : '';
        if ($min === null && $max === null) {
            $b['experience'] = $this->part('Experience', $w['experience'], 1.0, 'not set');
        } elseif ($months === null) {
            $b['experience'] = $this->part('Experience', $w['experience'], 0.5, 'unknown');
            $unknown[] = 'experience';
        } else {
            $yrs = $months / 12;
            $band = trim(($min !== null ? rtrim(rtrim(number_format($min, 1), '0'), '.') : '0') . '–' . ($max !== null ? rtrim(rtrim(number_format($max, 1), '0'), '.') : '+') . ' yrs');
            // One rule with Claude's score: rises through the band, low below it (RoleRouter::expFit).
            [$frac, $where] = RoleRouter::expFit($role, $yrs);
            $b['experience'] = $this->part('Experience', $w['experience'], $frac, round($yrs, 1) . " yrs$grown, $where ($band)");
        }

        // Qualification: the role's ladder, as in Claude's score (Benjamin, 10 Nov 2026): the requirement
        // 50%, higher 70%, professional 100%, below 0. A role with one level only: meeting it is 100%.
        $ladder = FitAssessor::ladderFor($role, $by['qualification']);
        [$frac, $note] = $this->qualificationFit($ladder, (string) ($cand['qualifications'] ?? ''), $cvText);
        $b['qualification'] = $this->part('Qualification', $w['qualification'], $frac, $note);

        // Location: the role's own location plus any extra accepted locations.
        $places = $by['location'];
        if (!empty($role['location'])) {
            $places[] = $role['location'];
        }
        if (!$places) {
            $b['location'] = $this->part('Location', $w['location'], 1.0, 'not set');
        } else {
            $canonPlaces = array_map(fn ($p) => mb_strtolower($this->skills->canonical($p)), $places);
            $candLoc = $cand['location'] ?? null;
            if ($candLoc && in_array(mb_strtolower($this->skills->canonical($candLoc)), $canonPlaces, true)) {
                $b['location'] = $this->part('Location', $w['location'], 1.0, $candLoc);
            } elseif (preg_match('/relocat/i', $cvText)) {
                $b['location'] = $this->part('Location', $w['location'], 0.6, ($candLoc ?: 'elsewhere') . ', open to relocating');
            } elseif (!$candLoc) {
                $b['location'] = $this->part('Location', $w['location'], 0.5, 'unknown');
                $unknown[] = 'location';
            } else {
                $b['location'] = $this->part('Location', $w['location'], 0.0, $candLoc);
            }
        }

        // Notice period.
        $nMax = $role['notice_max_days'] !== null ? (int) $role['notice_max_days'] : null;
        $notice = $cand['notice_days'] ?? null;
        if ($nMax === null) {
            $b['notice'] = $this->part('Notice', $w['notice'], 1.0, 'not set');
        } elseif ($notice === null) {
            $b['notice'] = $this->part('Notice', $w['notice'], 0.5, 'unknown');
            $unknown[] = 'notice period';
        } else {
            $n = (int) $notice;
            $frac = $n <= $nMax ? 1.0 : max(0, 1 - ($n - $nMax) / max(30, $nMax));
            $b['notice'] = $this->part('Notice', $w['notice'], $frac, ($n === 0 ? 'immediate' : "$n days") . " (max $nMax)");
        }

        // Salary against the budget: the lowest they would accept when they've told us, else what they expect.
        $cMax = $role['ctc_max_lpa'] !== null ? (float) $role['ctc_max_lpa'] : null;
        $useMin = ($cand['ctc_min_lpa'] ?? null) !== null && $cand['ctc_min_lpa'] !== '';
        $exp = $useMin ? $cand['ctc_min_lpa'] : ($cand['ctc_expected_lpa'] ?? null);
        if ($cMax === null) {
            $b['ctc'] = $this->part('CTC', $w['ctc'], 1.0, 'not set');
        } elseif ($exp === null) {
            $b['ctc'] = $this->part('CTC', $w['ctc'], 0.5, 'unknown');
            $unknown[] = 'expected CTC';
        } else {
            $e = (float) $exp;
            $frac = $e <= $cMax ? 1.0 : max(0, 1 - ($e - $cMax) / $cMax * 2);
            $b['ctc'] = $this->part('CTC', $w['ctc'], $frac, lpa($e) . ($useMin ? ' lowest they would accept' : ' expected') . ' (budget ' . lpa($cMax) . ')');
        }

        // Nice-to-haves: shown and used to break ties.
        $nice = [];
        foreach ($by['nice'] as $v) {
            if ($this->skills->locate($cvText, $v)) {
                $nice[] = $v;
            }
        }

        $total = 0.0;
        $maxTotal = 0;
        foreach ($b as $part) {
            $total += $part['points'];
            $maxTotal += $part['max'];
        }
        $score = $maxTotal > 0 ? (int) round($total * 100 / $maxTotal) : 0;

        return ['score' => max(0, min(100, $score)), 'breakdown' => [
            'parts' => array_map(fn ($p) => ['points' => round($p['points'], 1)] + $p, $b),
            'nice' => $nice,
            'unknown' => $unknown,
        ]];
    }

    /**
     * Where the candidate's qualification sits on the role's ladder, from the qualifications Claude
     * read (else the CV text). A level only pursued, appeared for or planned counts one step lower.
     * @param array{base:string, higher:string, top:string} $ladder
     * @return array{0: float, 1: string}
     */
    public function qualificationFit(array $ladder, string $quals, string $cvText): array
    {
        $tiers = ['top' => 1.0, 'higher' => 0.7, 'base' => 0.5];
        $hasTiers = trim($ladder['higher'] ?? '') !== '' || trim($ladder['top'] ?? '') !== '';
        if (trim($ladder['base'] ?? '') === '' && !$hasTiers) {
            return [1.0, 'not set'];
        }
        $text = trim($quals) !== '' ? $quals : $cvText;
        // Each tier: [the item found, completed?]. Not completed ("CA Inter (pursuing)") counts one step lower.
        $found = [];
        foreach (array_keys($tiers) as $tier) {
            foreach (self::ladderItems((string) ($ladder[$tier] ?? '')) as $item) {
                if ($hit = $this->skills->locate($text, $item)) {
                    $cur = [$this->skills->canonical($item), !$this->notCompleted($text, $hit)];
                    if (!isset($found[$tier]) || $cur[1]) {
                        $found[$tier] = $cur; // the first one named, unless a later one is completed
                    }
                    if ($cur[1]) {
                        break;
                    }
                }
            }
        }
        $hasBase = trim($ladder['base'] ?? '') === '' || !empty($found['base'][1]);
        if (!empty($found['top'][1])) {
            return [1.0, $found['top'][0] . ' (professional)'];
        }
        // A higher degree counts only on top of the requirement (an MBA after a BBM is not a B.Com).
        $higher = !empty($found['higher'][1]) ? $found['higher'][0] : (isset($found['top']) ? $found['top'][0] . ' (not completed)' : null);
        if ($higher !== null && $hasBase) {
            return [$tiers['higher'], $higher . ' (higher)'];
        }
        if (!empty($found['base'][1])) {
            return [$hasTiers ? $tiers['base'] : 1.0, $found['base'][0] . ($hasTiers ? ' (meets the requirement)' : '')];
        }
        return [0.0, 'below the requirement' . (isset($found['base']) ? ' (' . $found['base'][0] . ' not completed)' : '')];
    }

    /** "CA Inter (pursuing)", "appeared for CMA Inter", "aspiring CA": the qualification is not completed. */
    private function notCompleted(string $text, array $hit): bool
    {
        $around = substr($text, max(0, $hit['offset'] - 40), strlen($hit['term']) + 80);
        $lines = array_filter(preg_split('/[\n;,]/', $around), fn ($l) => stripos($l, $hit['term']) !== false);
        $near = implode(' ', $lines) ?: $around;
        return (bool) preg_match('/pursu|appear|aspir|ongoing|persu|attempt|preparing|registered|expected|awaited|in progress|\bfinal year\b|\bdropped\b/i', $near);
    }

    /** "CA Inter, CMA Inter or CS Executive (cleared), or a qualified CA, CMA, CS or ACCA" → its items. */
    public static function ladderItems(string $tier): array
    {
        $tier = preg_replace('/\([^)]*\)|\ba qualified\b|\bqualified\b/i', ' ', $tier);
        return array_values(array_filter(array_map('trim', preg_split('/,|\/|\bor\b|\band\b/i', (string) $tier)), fn ($x) => mb_strlen($x) >= 2));
    }

    private function part(string $label, int $max, float $fraction, string $note): array
    {
        return ['label' => $label, 'max' => $max, 'points' => $max * max(0, min(1, $fraction)), 'note' => $note];
    }
}
