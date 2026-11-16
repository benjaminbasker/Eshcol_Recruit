<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Reads a client's job description (already turned into text) and picks out what a role needs:
 * title, experience band, salary budget, notice, number of positions, locations, skills and
 * qualifications. Rules and the skills dictionary only: free, and the same answer every time.
 * Anything it can't find is left empty for the recruiter to fill in.
 */
final class JdReader
{
    public function __construct(private Skills $skills) {}

    /** @return array{title:?string, exp_min:?float, exp_max:?float, ctc_min_lpa:?float, ctc_max_lpa:?float, notice_max_days:?int, positions:?int, locations:list<string>, skills:list<string>, qualifications:list<string>} */
    public function read(string $text, string $filename = ''): array
    {
        $flat = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        [$emin, $emax] = $this->experience($flat);
        [$cmin, $cmax] = $this->ctc($flat);
        return [
            'title' => $this->title($flat, $filename),
            'exp_min' => $emin,
            'exp_max' => $emax,
            'ctc_min_lpa' => $cmin,
            'ctc_max_lpa' => $cmax,
            'notice_max_days' => $this->notice($flat),
            'positions' => preg_match('/\b(\d{1,2})\s+(?:positions|openings|vacancies|posts)\b/i', $flat, $m) ? max(1, (int) $m[1]) : null,
            'locations' => array_keys($this->skills->find($flat, 'location')),
            'skills' => array_keys($this->skills->find($flat, 'skill')),
            'qualifications' => array_keys($this->skills->find($flat, 'qualification')),
        ];
    }

    private function title(string $t, string $filename): ?string
    {
        $word = '[A-Z][A-Za-z&\/.\-]*';
        $phrase = "($word(?: (?:of|and|&|-|\/) $word| $word){0,4})";
        $tries = [
            '/^\s*(?:job\s*title|position|designation|role|post)\s*[:\-–]\s*(.{3,80})$/im',
            "/\bThe $phrase will be responsible\b/",
            "/\blooking for (?:an?|one|two) (?:experienced |hands-on |dynamic |motivated )?$phrase(?: to| who| with|,|\.)/",
            "/\bhiring (?:an?|one|two) (?:experienced )?$phrase(?: to| who| with| for|,|\.)/",
        ];
        foreach ($tries as $re) {
            if (preg_match($re, $t, $m) && ($v = $this->cleanTitle($m[1])) !== null) {
                return $v;
            }
        }
        // "Job Description_Associate.pdf" → "Associate"
        $base = preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename(str_replace('\\', '/', $filename)));
        $base = preg_replace('/[_\-]+|\(\d+\)/', ' ', $base);
        $base = trim(preg_replace('/\b(?:job\s*description|jd|final|draft|v\d+)\b/i', ' ', $base));
        return $this->cleanTitle($base);
    }

    private function cleanTitle(string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', $v), " \t.,:;-–");
        if (mb_strlen($v) < 3 || mb_strlen($v) > 80 || preg_match('/^(?:someone|candidate|person|team|individual)$/i', $v)) {
            return null;
        }
        // Keep acronyms (HR, CA, MIS) as they are; capitalise the rest.
        return implode(' ', array_map(fn ($w) => preg_match('/^[A-Z]{2,}$/', $w) ? $w : mb_convert_case($w, MB_CASE_TITLE), explode(' ', $v)));
    }

    /** @return array{0:?float,1:?float} */
    private function experience(string $t): array
    {
        if (preg_match('/(\d{1,2}(?:\.\d)?)\s*(?:-|–|to)\s*(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)/i', $t, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        if (preg_match('/(?:minimum|min\.?|at least|over)\s*(?:of\s*)?(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)/i', $t, $m)
            || preg_match('/(\d{1,2}(?:\.\d)?)\s*\+\s*(?:years?|yrs?)/i', $t, $m)) {
            return [(float) $m[1], null];
        }
        return [null, null];
    }

    /** Salary in lakh per year, if the JD states it. @return array{0:?float,1:?float} */
    private function ctc(string $t): array
    {
        $unit = '(?:lpa|l\.p\.a\.?|lakhs?(?:\s*(?:per annum|p\.?a\.?|a year))?|lacs?)';
        if (preg_match("/(?:₹|rs\.?|inr)?\s*(\d{1,2}(?:\.\d{1,2})?)\s*(?:-|–|to)\s*(?:₹|rs\.?|inr)?\s*(\d{1,2}(?:\.\d{1,2})?)\s*$unit/iu", $t, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        if (preg_match("/(?:up to|upto|max(?:imum)?|budget(?: of)?)\s*(?:₹|rs\.?|inr)?\s*(\d{1,2}(?:\.\d{1,2})?)\s*$unit/iu", $t, $m)) {
            return [null, (float) $m[1]];
        }
        return [null, null];
    }

    private function notice(string $t): ?int
    {
        if (preg_match('/notice[^.\n]{0,40}?(\d{1,3})\s*days/i', $t, $m) || preg_match('/(\d{1,3})\s*days[^.\n]{0,20}notice/i', $t, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/notice[^.\n]{0,40}?(\d)\s*months?/i', $t, $m)) {
            return (int) $m[1] * 30;
        }
        if (preg_match('/\bimmediate(?:ly)?\s+(?:joiners?|joining|available)\b/i', $t)) {
            return 15;
        }
        return null;
    }
}
