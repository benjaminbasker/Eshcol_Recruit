<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Pulls candidate details out of CV text with plain rules and the skills
 * dictionary. No AI. Anything it can't find stays blank; anything it had to
 * estimate is marked so the recruiter can confirm it.
 */
final class FieldExtractor
{
    /** Addresses that are never the candidate's own. */
    public const NOT_CANDIDATE = '/@(eshcolglobal\.com|indeed\.com|indeedemail\.com|linkedin\.com|naukri\.com|naukri\.co|example\.com)$|^(no-?reply|noreply|donotreply|mailer-daemon|jobs|careers|hr)@/i';

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(private readonly Skills $skills)
    {
    }

    /**
     * @return array{fields: array<string, mixed>, sources: array<string, string>, skills: string[]}
     */
    public function extract(string $text, string $filename): array
    {
        $f = [];
        $src = [];
        $set = function (string $k, mixed $v, string $s) use (&$f, &$src): void {
            if ($v !== null && $v !== '') {
                $f[$k] = $v;
                $src[$k] = $s;
            }
        };

        [$name, $nameSrc] = $this->name($text, $filename);
        $set('full_name', $name, $nameSrc);
        if ($name) {
            $set('first_name', explode(' ', $name)[0], $nameSrc);
        }
        $set('email', $this->email($text), 'cv');
        $set('phone', $this->phone($text), 'cv');
        $set('linkedin_url', self::linkedin($text), 'cv');

        // Experience, cross-checked: the stated total and the job dates are both kept, with
        // the date the CV was written, so an old CV's "3 years" grows from when it was written.
        $d = $this->experienceDetail($text);
        $today = date('Y-m-d');
        if ($d['stated'] !== null) {
            $set('exp_months', $d['stated'], 'cv');
            $set('exp_as_of', $d['cv_dated'] ?? $today, 'cv');
        } elseif ($d['dates'] !== null) {
            $set('exp_months', $d['dates'], 'estimated');
            $set('exp_as_of', $d['current'] ? $today : ($d['cv_dated'] ?? $today), 'estimated');
        }
        $set('exp_stated_months', $d['stated'], 'cv');
        $set('exp_dates_months', $d['dates'], 'cv');
        $set('cv_dated', $d['cv_dated'], 'cv');
        $set('cv_has_current_job', $d['current'] ? 1 : 0, 'cv');
        // "CTC" on its own counts as current, but never when it is part of "Expected CTC".
        $set('ctc_current_lpa', $this->ctc($text, '(?:(?:current|present|existing|annual)\s*)?(?<!expected\s)(?<!expected)(?<!desired\s)(?:ctc|salary|package)'), 'cv');
        $set('ctc_expected_lpa', $this->ctc($text, '(?:expected|desired)\s*(?:ctc|salary|package)'), 'cv');
        $set('notice_days', $this->notice($text), 'cv');
        [$city, $locality] = $this->place($text);
        $set('location', $city, 'cv');
        $set('locality', $locality, 'cv');

        $quals = array_keys($this->skills->find($text, 'qualification'));
        $set('qualifications', $quals ? implode(', ', $quals) : null, 'cv');

        [$titles, $current] = $this->titles($text);
        $set('current_title', $current, 'cv');

        return ['fields' => $f, 'sources' => $src, 'skills' => array_keys($this->skills->find($text, 'skill')), 'titles' => $titles];
    }

    /**
     * Job titles in the CV. The current one is a title on (or next to) a line saying
     * "Present", "current", "till date" or "now"; failing that, the first title in the CV,
     * which is usually the one under the candidate's name.
     * @return array{0: string[], 1: ?string} all titles, current title
     */
    public function titles(string $text): array
    {
        $hits = $this->skills->findAll($text, 'designation');
        if (!$hits) {
            return [[], null];
        }
        $current = null;
        foreach ($hits as [$title, $offset]) {
            $lineStart = strrpos(substr($text, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $window = substr($text, $lineStart, 220);
            if (preg_match('/\b(present|current(ly)?|till\s*date|to\s*date|now)\b/i', $window)) {
                $current = $title;
                break;
            }
        }
        $all = array_values(array_unique(array_column($hits, 0)));
        return [$all, $current ?? $hits[0][0]];
    }

    /**
     * A person's LinkedIn profile, tidied to "https://www.linkedin.com/in/name", or null.
     * Company, job and post pages are not profiles. Also used to check what a candidate types.
     */
    public static function linkedin(?string $text): ?string
    {
        if ($text === null || !preg_match('#(?:https?://)?(?:[a-z]{2,3}\.)?linkedin\.com/(in|pub)/([A-Za-z0-9\-_%.\x{00C0}-\x{024F}]{2,100})((?:/[A-Za-z0-9]{1,6}){0,3})#iu', $text, $m)) {
            return null;
        }
        $slug = rtrim($m[2], '.-_');
        if ($slug === '' || strlen($slug) < 2) {
            return null;
        }
        return 'https://www.linkedin.com/' . strtolower($m[1]) . '/' . $slug . (strtolower($m[1]) === 'pub' ? $m[3] : '');
    }

    public function email(string $text): ?string
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m);
        foreach ($m[0] as $e) {
            $e = mb_strtolower(trim($e, '.'));
            if (!preg_match(self::NOT_CANDIDATE, $e) && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return $e;
            }
        }
        return null;
    }

    public function phone(string $text): ?string
    {
        if (preg_match('/(?<!\d)(?:\+?91[\s\-.]?|0)?([6-9]\d{4})[\s\-.]?(\d{5})(?!\d)/', $text, $m)) {
            return $m[1] . $m[2];
        }
        return null;
    }

    public static function normalisePhone(?string $p): ?string
    {
        $d = preg_replace('/\D/', '', (string) $p);
        $d = strlen($d) > 10 ? substr($d, -10) : $d;
        return strlen($d) === 10 ? $d : null;
    }

    /** @return array{0: ?string, 1: string} */
    private function name(string $text, string $filename): array
    {
        $skip = '/resume|r[eé]sum[eé]|curriculum|vitae|\bcv\b|@|profile|objective|summary|address|mobile|phone|e-?mail|career|contact|declaration|experience|education|skills/i';
        $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $text)))), 0, 12);
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^(name)\s*[:\-]\s*/i', '', $line));
            if (preg_match($skip, $line) || preg_match('/\d{3,}/', $line)) {
                continue;
            }
            if (preg_match('/^[\p{L}][\p{L}.\']*(?:\s+[\p{L}][\p{L}.\']*){1,3}$/u', $line) && mb_strlen($line) <= 40 && self::looksLikeName($line)
                && !$this->skills->find($line, 'designation') && !$this->skills->find($line, 'skill')) { // "Accounts Executive" is a title, not a name
                return [$this->titleCase($line), 'cv'];
            }
        }
        $fromText = $this->nameMatchingFile($text, $filename);
        if ($fromText !== null) {
            return [$fromText, 'cv'];
        }
        $base = pathinfo($filename, PATHINFO_FILENAME);
        // Naukri-style "ResumeBaliramShinde": split joined-up words at each capital.
        $base = preg_replace('/\[[^\]]*\]|\([^)]*\)/u', ' ', $base);          // tags like [5y_0m] or (1)
        $base = preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $base);
        $base = preg_replace('/[\s_\-.]+/u', ' ', $base);                       // separators first, so \b works
        $base = preg_replace('/(naukri|indeed|linkedin|resume|r[eé]sum[eé]|curriculum|vitae|\bcv\b|updated|latest|final|\bnew\b|copy|profile|scanned|scan|photo|image|\bimg\b|\bpic\b|\d+)/iu', ' ', $base);
        $base = trim(preg_replace('/\s+/u', ' ', $base));
        if (preg_match('/^[\p{L}]{2,}(?:\s+[\p{L}.]{1,}){0,3}$/u', $base) && str_contains($base, ' ')) {
            return [$this->titleCase($base), 'filename'];
        }
        return [null, ''];
    }

    /**
     * "ResumeSANYUKTAKADAM.pdf" has no word breaks, but the CV has the same letters as words:
     * "Sanyukta Kadam". Finds 2–4 consecutive words in the CV whose letters equal the file name's.
     */
    private function nameMatchingFile(string $text, string $filename): ?string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/\[[^\]]*\]|\([^)]*\)/u', ' ', $base);
        $base = preg_replace('/(naukri|indeed|linkedin|resume|r[eé]sum[eé]|curriculum|vitae|\bcv\b|updated|latest|final|copy|profile|\d+)/iu', ' ', $base);
        $core = mb_strtolower(preg_replace('/[^\p{L}]+/u', '', $base));
        if (mb_strlen($core) < 6) {
            return null;
        }
        foreach (array_slice(preg_split('/\R/u', $text) ?: [], 0, 80) as $line) {
            $words = preg_split('/[^\p{L}.]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = count($words);
            for ($i = 0; $i < $n; $i++) {
                $joined = '';
                for ($k = $i; $k < min($n, $i + 4); $k++) {
                    $joined .= mb_strtolower(str_replace('.', '', $words[$k]));
                    if ($joined === $core && $k > $i) {
                        return $this->titleCase(implode(' ', array_slice($words, $i, $k - $i + 1)));
                    }
                    if (mb_strlen($joined) >= mb_strlen($core)) {
                        break;
                    }
                }
            }
        }
        return null;
    }

    /**
     * A person's name, not a scrap of a sentence ("and knowledge appropriately.",
     * "effectively to organizational success."): no small linking words, no full stop
     * after a whole word. Initials ("S. Abbas", "Santhosh K.") are fine.
     */
    public static function looksLikeName(string $s): bool
    {
        $s = trim($s);
        if (preg_match('/(?<![\p{L}])(and|to|the|of|for|with|in|on|by|our|my|your|is|are|was|from|an|as|at|into|through|via|per|using|team|work|ensure|successfully|effectively)(?![\p{L}])/iu', $s)) {
            return false;
        }
        return !preg_match('/[\p{L}]{3,}\.$/u', $s);
    }

    private function titleCase(string $s): string
    {
        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array{stated: ?int, dates: ?int, cv_dated: ?string, current: bool}
     *   stated: total the CV states; dates: what its job dates add up to (overlaps once);
     *   cv_dated: when the CV was written (its "Date:" line, else its newest date; null when it
     *   lists a current job and has no "Date:" line); current: it lists a current job.
     */
    public function experienceDetail(string $text): array
    {
        $history = CvReport::parseHistory($text);
        $current = (bool) array_filter($history, fn ($h) => $h['current'] && !$h['education'] && !$h['break']);
        $now = (int) date('Y') * 12 + (int) date('n');
        $latest = 0;
        foreach ($history as $h) {
            if (!$h['current'] && !$h['future'] && !$h['education'] && $h['end'] <= $now) {
                $latest = max($latest, $h['end']);
            }
        }
        $declared = null;
        if (preg_match_all('/\bdate[d]?\s*[:\-]?\s*(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})\b/i', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $y = (int) $m[3];
                $y = $y < 100 ? 2000 + $y : $y;
                $mo = (int) $m[2];
                if ($mo >= 1 && $mo <= 12 && $y >= 1990 && $y * 12 + $mo <= $now) {
                    $declared = max($declared ?? 0, $y * 12 + $mo);
                }
            }
        }
        $cvMonth = $declared ?? ($current ? null : ($latest ?: null));
        $cvDated = $cvMonth ? sprintf('%04d-%02d-01', intdiv($cvMonth - 1, 12), ($cvMonth - 1) % 12 + 1) : null;
        return ['stated' => $this->statedExperience($text), 'dates' => $this->experienceFromJobs($text) ?? $this->experienceFromDates($text),
            'cv_dated' => $cvDated, 'current' => $current];
    }

    /** @return array{0: ?int, 1: string} months, and whether stated or estimated */
    private function experience(string $text): array
    {
        $stated = $this->statedExperience($text);
        if ($stated !== null) {
            return [$stated, 'cv'];
        }
        $est = $this->experienceFromJobs($text) ?? $this->experienceFromDates($text);
        return [$est, $est !== null ? 'estimated' : ''];
    }

    /** "5 years of experience", "Total Exp: 3.5 yrs": the largest stated total, in months. */
    private function statedExperience(string $text): ?int
    {
        $best = null;
        if (preg_match_all('/(\d{1,2}(?:\.\d{1,2})?)\s*\+?\s*(?:years?|yrs?)(?:\s*(?:and|&)?\s*(\d{1,2})\s*(?:months?|mos?))?/i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $ctx = substr($text, max(0, $hit[0][1] - 60), 140);
                if (!preg_match('/experience|exp\b|exp\.|career|working/i', $ctx)) {
                    continue;
                }
                // "Age: 22 years", "22 years old": an age, not experience.
                $before = substr($text, max(0, $hit[0][1] - 40), 40 + strlen($hit[0][0]) + 6);
                if (preg_match('/\bage\b|\bold\b|\bborn\b|birth|d\.?\s?o\.?\s?b/i', $before)) {
                    continue;
                }
                $months = (int) round((float) $hit[1][0] * 12) + (int) ($hit[2][0] ?? 0);
                if ($months > 0 && $months <= 45 * 12) {
                    $best = max($best ?? 0, $months);
                }
            }
        }
        return $best;
    }

    /**
     * Experience from every dated job the CV report's date reader finds (more date formats,
     * year-only ranges, courses left out), overlaps counted once.
     */
    private function experienceFromJobs(string $text): ?int
    {
        $months = [];
        foreach (CvReport::parseHistory($text) as $h) {
            if ($h['education'] || $h['future'] || $h['break']) {
                continue;
            }
            for ($m = $h['start']; $m <= $h['end']; $m++) {
                $months[$m] = true;
            }
        }
        $n = count($months);
        return $n >= 3 && $n <= 45 * 12 ? $n : null;
    }

    /** Sums month-year ranges ("Mar 2019 - Present"), merging overlaps. Year-only ranges are ignored: they are usually education. */
    private function experienceFromDates(string $text): ?int
    {
        $mon = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sept?(?:ember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
        $pt = "(?:(?:$mon)\.?,?\s*'?(\d{2,4})|(\d{1,2})[\/\-.](\d{4}))";
        $re = "/$pt\s*(?:-|–|—|to|till)\s*(?:$pt|(?<present>present|current|till\s*date|to\s*date|now|date))/iu";
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        $now = (int) date('Y') * 12 + (int) date('n');
        $ranges = [];
        foreach ($m as $hit) {
            $start = $this->ym($hit[0], true);
            // A finished job counts through the end of its last month ("to Dec 2019" includes December).
            $endMonth = $this->ym($hit[0], false);
            $end = !empty($hit['present']) ? $now : ($endMonth ? $endMonth + 1 : null);
            if ($start && $end && $end >= $start && $start >= 1985 * 12 && $end <= $now + 1) {
                $ranges[] = [$start, $end];
            }
        }
        if (!$ranges) {
            return null;
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$cs, $ce] = $ranges[0];
        foreach (array_slice($ranges, 1) as [$s, $e]) {
            if ($s <= $ce) {
                $ce = max($ce, $e);
            } else {
                $total += $ce - $cs;
                [$cs, $ce] = [$s, $e];
            }
        }
        $total += $ce - $cs;
        return $total > 0 && $total <= 45 * 12 ? $total : null;
    }

    /** Year*12+month for the first (or second) date in a range string. */
    private function ym(string $range, bool $first): ?int
    {
        $parts = preg_split('/\s*(?:-|–|—|\bto\b|\btill\b)\s*/iu', $range, 2);
        $s = mb_strtolower($first ? $parts[0] : ($parts[1] ?? ''));
        if (preg_match('/(\d{1,2})[\/\-.](\d{4})/', $s, $d)) {
            return (int) $d[2] * 12 + max(1, min(12, (int) $d[1]));
        }
        if (preg_match('/([a-z]{3,4})[a-z]*\.?,?\s*\'?(\d{2,4})/', $s, $d)) {
            $month = self::MONTHS[substr($d[1], 0, 4)] ?? self::MONTHS[substr($d[1], 0, 3)] ?? null;
            $year = (int) $d[2];
            $year = $year < 100 ? 2000 + $year : $year;
            return $month ? $year * 12 + $month : null;
        }
        return null;
    }

    /** CTC in lakhs per annum. Understands "4.5 LPA", "4.5 lakhs", "₹4,50,000 p.a." and monthly figures. */
    private function ctc(string $text, string $label): ?float
    {
        if (!preg_match('/' . $label . '[^\n\d]{0,25}((?:rs\.?|inr|₹)?\s*[\d,]+(?:\.\d+)?)\s*(lpa|lakhs?|lacs?|l\b|per\s*annum|p\.?\s?a\.?|per\s*month|p\.?\s?m\.?|pm\b|\/\s*month|k\b)?/iu', $text, $m)) {
            return null;
        }
        $n = (float) preg_replace('/[^\d.]/', '', $m[1]);
        $unit = mb_strtolower($m[2] ?? '');
        if ($n <= 0) {
            return null;
        }
        if (preg_match('/lpa|lakh|lac|^l$/', $unit)) {
            $lpa = $n;
        } elseif (preg_match('/month|p\.?\s?m|pm/', $unit)) {
            $lpa = $n * 12 / 100000;
        } elseif ($unit === 'k') {
            $lpa = $n * 1000 * 12 / 100000;
        } elseif ($n >= 10000) {
            $lpa = $n / 100000;
        } elseif ($n <= 200) {
            $lpa = $n;
        } else {
            return null;
        }
        return $lpa > 0 && $lpa < 500 ? round($lpa, 2) : null;
    }

    private function notice(string $text): ?int
    {
        if (preg_match('/immediate(?:ly)?\s*(?:joiner|joining|available)|available\s*immediately/i', $text)) {
            return 0;
        }
        if (preg_match('/notice\s*period[^\n\d]{0,20}(\d{1,3})\s*(days?|months?|weeks?)/i', $text, $m)) {
            $n = (int) $m[1];
            return match (true) {
                str_starts_with(strtolower($m[2]), 'month') => $n * 30,
                str_starts_with(strtolower($m[2]), 'week') => $n * 7,
                default => $n,
            };
        }
        return null;
    }

    /** First three digits of a PIN code => city, used only when no city or locality is named. */
    private const PIN_CITY = ['411' => 'Pune', '412' => 'Pune', '400' => 'Mumbai', '560' => 'Bengaluru', '500' => 'Hyderabad', '600' => 'Chennai',
        '110' => 'Delhi NCR', '122' => 'Delhi NCR', '201' => 'Delhi NCR', '121' => 'Delhi NCR', '700' => 'Kolkata', '380' => 'Ahmedabad', '395' => 'Surat',
        '390' => 'Vadodara', '302' => 'Jaipur', '452' => 'Indore', '462' => 'Bhopal', '440' => 'Nagpur', '422' => 'Nashik', '431' => 'Chhatrapati Sambhajinagar',
        '416' => 'Kolhapur', '413' => 'Solapur', '226' => 'Lucknow', '208' => 'Kanpur', '160' => 'Chandigarh', '641' => 'Coimbatore', '682' => 'Kochi',
        '695' => 'Thiruvananthapuram', '530' => 'Visakhapatnam', '520' => 'Vijayawada', '751' => 'Bhubaneswar', '800' => 'Patna', '834' => 'Ranchi', '781' => 'Guwahati'];

    /**
     * Where the candidate lives: [city, locality]. In order: a labelled line ("Current location:",
     * "Address:"); a known locality near the top (which also gives the city); a city near the top;
     * the city of a PIN code. Education and employer lines are not used for the locality.
     * @return array{0: ?string, 1: ?string}
     */
    public function place(string $text): array
    {
        $head = mb_substr($text, 0, 1500);
        $segments = [];
        if (preg_match_all('/(?:current\s*location|present\s*location|location|address|residence|residing\s*at|lives\s*in|city)\s*[:\-–]\s*([^\n]{2,160})/iu', $text, $mm)) {
            $segments = $mm[1];
        }
        $segments[] = $head;
        foreach ($segments as $seg) {
            foreach (array_keys($this->skills->find($seg, 'locality')) as $loc) {
                $info = $this->skills->localityOf($loc);
                if ($info) {
                    return [$info['city'], $info['locality']];
                }
            }
            $found = $this->skills->find($seg, 'location');
            if ($found) {
                return [array_key_first($found), null];
            }
        }
        if (preg_match('/(?<!\d)([1-8]\d{2})\s?\d{3}(?!\d)/', $head, $m) && isset(self::PIN_CITY[$m[1]])) {
            return [self::PIN_CITY[$m[1]], null];
        }
        return [null, null];
    }
}
