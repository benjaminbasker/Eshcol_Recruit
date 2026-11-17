<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * The free CV report on a candidate's page. Built only from what the app has read from
 * the CV and the open roles, so every statement in it can be checked against the CV.
 *
 *  - a written summary (about ten lines)
 *  - every open role ranked by match, with must-haves met and missing, and how
 *    experience, salary and notice compare
 *  - red flags: gaps between jobs, short stints, a course still running, dates that
 *    don't add up, salary or notice beyond the role, missing contact details
 *  - questions worth asking on the screening call
 */
final class CvReport
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    /** A period off work the CV explains: not experience, but not an unexplained gap either. */
    private const BREAK = '/\b(career\s*break|break\s*in\s*career|sabbatical|maternity|paternity|family\s*(?:responsibilit|reasons|commitments)|personal\s*reasons|health\s*reasons|not\s*working|unemployed|job\s*search|preparing\s*for|exam\s*preparation|gap\s*year)/i';
    private const EDUCATION = '/\b(b\.?\s?com|m\.?\s?com|b\.?\s?sc|m\.?\s?sc|b\.?\s?a\b|m\.?\s?a\b|bba|mba|pgdm|b\.?\s?tech|m\.?\s?tech|b\.?\s?e\b|degree|diploma|university|college|school|institute|hsc|ssc|10th|12th|graduat|post.?graduat|c\.?a\.?\s*(inter|final|foundation)|cma|cs\s*(inter|executive)|semester|cgpa|percentage|course|certific|bachelor|master(?:\x27s|s)?\s+(?:of|in|degree)|ph\.?\s?d|doctorate|bcom|mcom|bca|mca|mcm|mms|bcs|mcs|bbm|b\.?\s?pharm|llb|ll\.?m|icwa|acca|10\+2|matric|year\s+of\s+passing|passed\s+out|pursuing)/i';

    public function __construct(private readonly App $app)
    {
    }

    public function build(int $candidateId): ?array
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c) {
            return null;
        }
        $text = (string) $db->col('SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1', [$candidateId]);
        $skills = new Skills($db);
        $found = array_keys($skills->find($text, 'skill'));
        $quals = $c['qualifications'] ?: implode(', ', array_keys($skills->find($text, 'qualification')));
        $history = $this->history($text);
        // Claude's job list, where it has read the CV: dates read off the page, not guessed from text.
        // Courses stay from the text (a course still running is worth asking about).
        $byClaude = (new AiReading($this->app))->history($candidateId);
        if ($byClaude !== null) {
            $history = array_merge($byClaude, array_values(array_filter($history, fn ($h) => $h['education'])));
            usort($history, fn ($a, $b) => $b['start'] <=> $a['start']);
        }
        $roles = $this->rankRoles($c, $text, $skills, $candidateId);
        $fieldNote = $this->fieldNote($c, $roles);
        $flags = $this->flags($c, $text, $history, $roles[0] ?? null);
        return [
            'summary' => $this->summary($c, $found, $quals, $history, $roles, $flags, $fieldNote),
            'field_note' => $fieldNote,
            'skills' => $found,
            'history' => $history,
            'roles' => $roles,
            'flags' => $flags,
            'questions' => $this->questions($c, $roles[0] ?? null, $flags),
            'readable' => mb_strlen(trim($text)) >= 200,
        ];
    }

    /** Open roles scored for this candidate, best first. */
    private function rankRoles(array $c, string $text, Skills $skills, int $candidateId): array
    {
        $db = $this->app->db;
        $scorer = new Scorer($skills);
        $commute = new Commute($skills);
        $on = array_map('intval', array_column($db->all('SELECT role_id FROM applications WHERE candidate_id = ?', [$candidateId]), 'role_id'));
        // Claude's assessment, where there is one: it leads (Benjamin, 10 Nov 2026); the free score is the keyword match.
        $claude = [];
        foreach ($db->all("SELECT role_id, score, band FROM fit_assessments WHERE candidate_id = ? AND status = 'done' AND score IS NOT NULL", [$candidateId]) as $f) {
            $claude[(int) $f['role_id']] = ['score' => (int) $f['score'], 'band' => (string) $f['band']];
        }
        $out = [];
        // A contractor sees only their own positions here too (found by the page smoke test, 15 Nov 2026).
        $mine = \Eshcol\Ats\Access::roleFilter(\Eshcol\Ats\Auth::user(), 'r.id');
        $roles = $db->all("SELECT r.*, cl.name AS client, cl.interview_address, cl.maps_url FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.status = 'open' AND r.is_pool = 0 AND $mine ORDER BY r.id");
        $roles = $this->inTheirField($c, $roles, $on);
        // Every open role's requirements in one request (audit P1, 16 Nov 2026), not one per role.
        $critBy = [];
        if ($roles) {
            foreach ($db->all('SELECT role_id, kind, value FROM role_criteria WHERE role_id IN (' . implode(',', array_map(fn ($r) => (int) $r['id'], $roles)) . ') ORDER BY sort, id') as $cr) {
                $critBy[(int) $cr['role_id']][] = ['kind' => $cr['kind'], 'value' => $cr['value']];
            }
        }
        foreach ($roles as $r) {
            $crit = $critBy[(int) $r['id']] ?? [];
            $s = $scorer->score($r, $crit, $c, $text);
            $p = $s['breakdown']['parts'];
            $out[] = [
                'id' => (int) $r['id'], 'title' => $r['title'], 'code' => $r['code'], 'client' => $r['client'],
                'score' => $s['score'], 'on_role' => in_array((int) $r['id'], $on, true), 'claude' => $claude[(int) $r['id']] ?? null,
                'rank' => $claude[(int) $r['id']]['score'] ?? $s['score'],
                'matched' => array_column($p['must']['matched'] ?? [], 'value'), 'missing' => $p['must']['missing'] ?? [],
                'experience' => $p['experience']['note'] ?? '', 'ctc' => $p['ctc']['note'] ?? '', 'notice' => $p['notice']['note'] ?? '',
                'ctc_plain' => self::plain((string) ($p['ctc']['note'] ?? '')), 'notice_plain' => self::plain((string) ($p['notice']['note'] ?? '')),
                'location' => $p['location']['note'] ?? '',
                'ctc_over' => ($p['ctc']['points'] ?? 0) < ($p['ctc']['max'] ?? 0) && !str_contains((string) ($p['ctc']['note'] ?? ''), 'unknown'),
                'notice_over' => ($p['notice']['points'] ?? 0) < ($p['notice']['max'] ?? 0) && !str_contains((string) ($p['notice']['note'] ?? ''), 'unknown'),
                'ctc_max' => $r['ctc_max_lpa'], 'notice_max' => $r['notice_max_days'],
                'office' => ($o = $commute->localityIn($r['interview_address'] ?? null, $r['maps_url'] ?? null)) ? Commute::place($o['locality'], $o['town']) : null,
                'commute' => $commute->between($c['locality'] ?? null, $c['location'] ?? null, $o),
            ];
        }
        // Roles Claude has assessed first (its score), then the rest by keyword match: the two scales differ.
        usort($out, fn ($a, $b) => [(bool) $b['claude'], $b['rank'], $b['score']] <=> [(bool) $a['claude'], $a['rank'], $a['score']]);
        return $out;
    }

    /**
     * With categories on (17 Nov 2026, Palani K): only positions in the candidate's field (primary or secondary)
     * are compared, plus any they are already on. A document controller is no longer scored against accounts
     * positions. A position whose field isn't known is kept. Nothing changes while categories are off or trial.
     */
    public function inTheirField(array $c, array $roles, array $on = []): array
    {
        $fields = $this->candidateFields($c);
        if ($fields === null) {
            return $roles;
        }
        $cat = new Categoriser($this->app);
        return array_values(array_filter($roles, function (array $r) use ($fields, $on, $cat): bool {
            if (in_array((int) $r['id'], $on, true)) {
                return true;
            }
            $rf = $cat->roleFunctions($r);
            return !$rf || array_intersect($rf, $fields) !== [];
        }));
    }

    /** The candidate's field codes (primary and secondary), or null when categories aren't on or they aren't categorised. */
    private function candidateFields(array $c): ?array
    {
        if (Categoriser::mode($this->app) !== 'on') {
            return null;
        }
        $cat = (new Categoriser($this->app))->current((int) $c['id']);
        if (!$cat) {
            return null;
        }
        return array_values(array_filter([(string) $cat['function_code'], (string) ($cat['secondary_code'] ?? '')]));
    }

    /** "No open position in their field (IT, Software & Data / Engineering Projects…). Filed in the … pool." Null when roles were found. */
    private function fieldNote(array $c, array $roles): ?string
    {
        if ($roles) {
            return null;
        }
        $fields = $this->candidateFields($c);
        if ($fields === null) {
            return null;
        }
        $names = array_map(fn ($f) => Categoriser::functionName($this->app, $f), $fields);
        $pool = $this->app->db->col("SELECT r.title FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND r.is_pool = 1 ORDER BY a.id DESC LIMIT 1", [(int) $c['id']]);
        $applied = trim((string) ($c['applied_for'] ?? ''));
        return 'No open position in their field (' . implode(' / ', $names) . ')' . ($applied !== '' ? '; they applied for ' . $applied : '') . '.'
            . ($pool ? " Filed in the $pool talent pool, for when a position opens." : '');
    }

    /**
     * Date ranges in the CV with the line they sit on: jobs and courses.
     * @return list<array{label:string, from:string, to:string, start:int, end:int, current:bool, education:bool, exact:bool}>
     */
    public function history(string $text): array
    {
        return self::parseHistory($text);
    }

    /** As history(), without needing the app: used by the field reader to estimate experience. */
    public static function parseHistory(string $text): array
    {
        $mon = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
        $date = "(?:$mon\s*[,'’-]?\s*(?:\d{4}|\d{2})|\d{1,2}\s*[\/.-]\s*\d{4}|\d{4})";
        $end = "(?:$date|present|current|till\s*date|to\s*date|now|ongoing|continuing|today)";
        $re = "/(?<![\d\/.-])($date)\s*(?:-|–|—|to|till|until)\s*($end)(?![\d\/])/iu";
        $lines = preg_split('/\R/u', $text) ?: [];
        $now = (int) date('Y') * 12 + (int) date('n');
        $seen = [];
        $out = [];
        foreach ($lines as $i => $line) {
            if (!preg_match_all($re, $line, $mm, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($mm as $m) {
                [$s, $sExact] = self::month($m[1], false);
                $current = (bool) preg_match('/present|current|till|to\s*date|now|ongoing|continuing|today/i', $m[2]);
                [$e, $eExact] = $current ? [$now, true] : self::month($m[2], true);
                if (!$s || !$e || $e < $s || $s < 1970 * 12 || $e - $s > 50 * 12) {
                    continue;
                }
                $key = "$s-$e";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                // What the range belongs to: the rest of its line, else the nearest line above.
                $label = trim(preg_replace('/\s+/u', ' ', str_replace($m[0], ' ', $line)), " \t|,-–—:()");
                for ($j = $i - 1; mb_strlen($label) < 4 && $j >= max(0, $i - 2); $j--) {
                    $label = trim($lines[$j], " \t|,-–—:");
                }
                // Course or job: judged on the entry's own text; neighbouring lines only when it has little.
                $ctx = mb_strlen($label) >= 15 ? $label : $label . ' ' . ($lines[$i - 1] ?? '');
                $out[] = ['label' => mb_strimwidth($label ?: 'Untitled', 0, 90, '…'), 'from' => trim($m[1]), 'to' => trim($m[2]),
                    'start' => $s, 'end' => $e, 'current' => $current, 'exact' => $sExact && $eExact,
                    'education' => self::isCourse($label) || (mb_strlen($label) < 15 && !self::isJob($label) && self::isCourse($ctx)), 'future' => !$current && $e > $now + 2,
                    'break' => (bool) preg_match(self::BREAK, $label)];
            }
        }
        usort($out, fn ($a, $b) => $b['start'] <=> $a['start']);
        return $out;
    }

    /**
     * A course rather than a job. A job title wins, so "Finance Officer | Indian Institute of
     * Science" or "Lecturer, XYZ College" is a job even though the employer sounds like a school.
     */
    public static function isCourse(string $text): bool
    {
        return (bool) preg_match(self::EDUCATION, $text) && !self::isJob($text);
    }

    /** The text names a job (a title, or "worked"). */
    public static function isJob(string $text): bool
    {
        return (bool) preg_match('/\b(officer|manager|executive|associate|assistant|accountant|intern(ship)?|analyst|engineer|head|lead|consultant|clerk|trainee|designer|developer|draftsman|coordinator|controller|specialist|administrator|admin|teacher|lecturer|professor|faculty|director|supervisor|incharge|in-charge|partner|auditor|article(ship)?|recruiter|expert|representative|operator|technician|worked|working)\b/i', $text);
    }

    /** "Mar 2023" → month number since year 0; year-only dates are less exact. */
    private static function month(string $d, bool $isEnd): array
    {
        $d = mb_strtolower(trim($d));
        if (preg_match('/^([a-z]+)\.?\s*[,\'’-]?\s*(\d{2,4})$/u', $d, $m) && isset(self::MONTHS[substr($m[1], 0, 3)])) {
            $y = (int) $m[2];
            $y = $y < 100 ? ($y > 50 ? 1900 + $y : 2000 + $y) : $y;
            return [$y * 12 + self::MONTHS[substr($m[1], 0, 3)], true];
        }
        if (preg_match('/^(\d{1,2})\s*[\/.-]\s*(\d{4})$/', $d, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return [(int) $m[2] * 12 + (int) $m[1], true];
        }
        if (preg_match('/^(\d{4})$/', $d, $m)) {
            return [(int) $m[1] * 12 + ($isEnd ? 12 : 1), false];
        }
        return [0, false];
    }

    private function flags(array $c, string $text, array $history, ?array $best): array
    {
        $f = [];
        $jobs = array_values(array_filter($history, fn ($h) => !$h['education'] && !$h['break']));
        // Gaps between jobs (oldest first), only where the dates are exact enough to say so.
        // A career break the CV states covers its period: explained, so not flagged.
        $sorted = array_values(array_filter($history, fn ($h) => !$h['education']));
        usort($sorted, fn ($a, $b) => $a['start'] <=> $b['start']);
        $reach = null;
        $prev = null;
        foreach ($sorted as $j) {
            if ($reach !== null && $j['start'] - $reach > 3) {
                $gap = $j['start'] - $reach - 1;
                if ($gap >= ($j['exact'] && $prev['exact'] ? 4 : 12)) {
                    $f[] = ['kind' => 'gap', 'text' => 'A gap of about ' . $this->span($gap) . ' between jobs (' . $this->ym($reach) . ' to ' . $this->ym($j['start']) . ').'];
                }
            }
            $reach = $reach === null ? $j['end'] : max($reach, $j['end']);
            $prev = $j;
        }
        $short = array_filter($jobs, fn ($j) => !$j['current'] && $j['end'] - $j['start'] < 11);
        if (count($short) >= 2) {
            $f[] = ['kind' => 'short', 'text' => count($short) . ' jobs lasted less than a year.'];
        }
        foreach ($history as $h) {
            if ($h['education'] && $h['future']) {
                $f[] = ['kind' => 'student', 'text' => 'A course runs until ' . $this->ym($h['end']) . ' (' . $h['label'] . '): they may still be studying.'];
                break;
            }
        }
        // Stated experience against what the job dates add up to.
        $months = exp_now($c);
        $xc = ExperienceCheck::assess($c);
        if ($xc['check']) {
            $f[] = ['kind' => 'dates', 'text' => $xc['note']];
        } elseif ($months !== null && $months >= 24 && !$jobs) {
            $f[] = ['kind' => 'dates', 'text' => 'No job dates found in the CV to support ' . experience($months) . ' of experience.'];
        }
        if ($xc['old']) {
            $f[] = ['kind' => 'old_cv', 'text' => !empty($c['cv_has_current_job']) ? 'The CV looks old: it seems to have been written around ' . $xc['old_since'] . ' (its stated total is less than its job dates up to today).' : 'The CV may be out of date: its newest date is ' . $xc['old_since'] . ' and it lists no current job.', 'since' => $xc['old_since']];
        }
        if ($best && $best['ctc_over']) {
            $f[] = ['kind' => 'ctc', 'text' => 'Salary: ' . $best['ctc_plain'] . ', above ' . $best['title'] . '\'s budget.'];
        }
        if ($best && in_array($best['commute']['level'] ?? '', ['far', 'other_city'], true)) {
            $far = $best['commute']['level'] === 'far';
            $f[] = ['kind' => 'commute', 'text' => 'Lives in ' . Commute::place($c['locality'] ?? null, $c['location'] ?? null)
                . ($far ? ', far from the office at ' . $best['office'] . ' (' . preg_replace('/^Far:\s*/', '', $best['commute']['label']) . ').'
                    : ', a different city from the office at ' . $best['office'] . '.')];
        } elseif ($best && ($best['commute']['level'] ?? '') === 'same_city' && empty($c['locality'])) {
            $f[] = ['kind' => 'locality', 'text' => 'Their locality is not known, so travel to ' . $best['office'] . ' can\'t be judged.'];
        }
        if ($best && $best['notice_over']) {
            $f[] = ['kind' => 'notice', 'text' => 'Notice: ' . $best['notice_plain'] . ', longer than ' . $best['title'] . ' allows.'];
        }
        if (!$c['email'] && !$c['phone']) {
            $f[] = ['kind' => 'contact', 'text' => 'No email or phone number found.'];
        } elseif (!$c['email']) {
            $f[] = ['kind' => 'contact', 'text' => 'No email address found.'];
        } elseif (!$c['phone']) {
            $f[] = ['kind' => 'contact', 'text' => 'No phone number found.'];
        }
        return $f;
    }

    /** Months covered by jobs, overlaps counted once. */
    private function covered(array $jobs): int
    {
        $marks = [];
        foreach ($jobs as $j) {
            for ($m = $j['start']; $m <= $j['end']; $m++) {
                $marks[$m] = true;
            }
        }
        return count($marks);
    }

    private function summary(array $c, array $skills, string $quals, array $history, array $roles, array $flags, ?string $fieldNote = null): string
    {
        $name = $c['full_name'] ?: 'This candidate';
        $months = exp_now($c);
        $p = [];
        $p[] = $name . ' is ' . ($c['current_title'] ? $this->article($c['current_title']) . ' ' . $c['current_title'] : 'a candidate')
            . ($months !== null ? ' with ' . experience($months) . ' of experience' : ' (total experience not stated)')
            . ($c['current_company'] ? ', currently at ' . $c['current_company'] : '')
            . ($c['location'] ? ', based in ' . $c['location'] : '') . '.';
        $jobs = array_values(array_filter($history, fn ($h) => !$h['education'] && !$h['break']));
        if ($jobs) {
            $bits = array_map(fn ($j) => $j['label'] . ' (' . $j['from'] . ' – ' . $j['to'] . ')', array_slice($jobs, 0, 3));
            $p[] = 'The CV lists ' . count($jobs) . ' dated role' . (count($jobs) > 1 ? 's' : '') . ': ' . implode('; ', $bits) . (count($jobs) > 3 ? '; and earlier ones' : '') . '.';
        }
        if ($quals) {
            $p[] = 'Qualifications: ' . $quals . '.';
        }
        if ($skills) {
            $p[] = 'Skills found in the CV: ' . implode(', ', array_slice($skills, 0, 12)) . (count($skills) > 12 ? ' and ' . (count($skills) - 12) . ' more' : '') . '.';
        }
        $money = [];
        $money[] = 'notice period ' . ($c['notice_days'] === null ? 'not stated' : ((int) $c['notice_days'] === 0 ? 'immediate' : (int) $c['notice_days'] . ' days'));
        $money[] = 'current CTC ' . ($c['ctc_current_lpa'] !== null ? lpa($c['ctc_current_lpa']) : 'not stated');
        $money[] = 'expected ' . ($c['ctc_expected_lpa'] !== null ? lpa($c['ctc_expected_lpa']) : 'not stated');
        if ($c['ctc_min_lpa'] !== null) {
            $money[] = 'lowest they would accept ' . lpa($c['ctc_min_lpa']);
        }
        $p[] = ucfirst(implode('; ', $money)) . '.';
        if ($roles) {
            $b = $roles[0];
            $at = fn (array $r) => $r['claude'] ? "Claude's assessment " . $r['claude']['score'] . ' (' . $r['claude']['band'] . '), keyword match ' . $r['score'] . '%' : 'keyword match ' . $r['score'] . '%';
            $p[] = 'Best fit among open roles: ' . $b['title'] . ' (' . $b['code'] . ', ' . $b['client'] . '): ' . $at($b) . '. Experience: ' . $b['experience'] . '.'
                . ($b['matched'] || $b['missing'] ? ' Meets ' . count($b['matched']) . ' of ' . (count($b['matched']) + count($b['missing'])) . ' must-haves' . ($b['missing'] ? ' (missing: ' . implode(', ', $b['missing']) . ')' : '') . '.' : '');
            if ($b['ctc_over'] || $b['notice_over']) {
                $p[] = trim(($b['ctc_over'] ? 'Salary is above this role\'s budget: ' . $b['ctc_plain'] . '. ' : '') . ($b['notice_over'] ? 'Notice is longer than it allows: ' . $b['notice_plain'] . '.' : ''));
            }
            if (isset($roles[1])) {
                $p[] = 'Next: ' . $roles[1]['title'] . ' (' . $roles[1]['code'] . '): ' . $at($roles[1]) . ', ' . $roles[1]['experience'] . '.';
            }
        } else {
            $p[] = $fieldNote ?? 'There are no open roles to compare against yet.';
        }
        $points = array_values(array_filter($flags, fn ($f) => in_array($f['kind'], ['gap', 'short', 'student', 'dates', 'old_cv'], true)));
        if ($points) {
            $p[] = 'To check: ' . implode(' ', array_map(fn ($f) => $f['text'], $points));
        }
        return implode(' ', $p);
    }

    private function questions(array $c, ?array $best, array $flags): array
    {
        $q = [];
        foreach (array_slice($best['missing'] ?? [], 0, 3) as $m) {
            $q[] = "Have you worked with $m? What exactly did you do?";
        }
        foreach ($flags as $f) {
            match ($f['kind']) {
                'gap' => $q[] = 'What were you doing ' . preg_replace('/^A gap of about [^(]+\((.+)\)\.$/', 'from $1', $f['text']) . '?',
                'student' => $q[] = 'Are you still studying? When are your exams, and will you need leave for them?',
                'short' => $q[] = 'Some of your jobs were short. What made you move on each time?',
                'dates' => $q[] = 'Can you walk me through your experience year by year, with dates?',
                'old_cv' => $q[] = 'Your CV\'s latest entry is ' . ($f['since'] ?? 'some time ago') . '. What have you been doing since then?',
                'ctc' => $q[] = 'Your expected salary is above our budget. Is there any flexibility?',
                'notice' => $q[] = 'Can your notice period be shortened or bought out?',
                'commute' => $q[] = 'The office is at ' . ($best['office'] ?? 'the client\'s location') . '. How would you travel there each day, and how long would it take?',
                'locality' => $q[] = 'Which area do you live in? The office is at ' . ($best['office'] ?? 'the client\'s location') . '.',
                default => null,
            };
        }
        if ($c['notice_days'] === null) {
            $q[] = 'What is your notice period?';
        }
        if ($c['ctc_current_lpa'] === null || $c['ctc_expected_lpa'] === null) {
            $q[] = 'What are your current and expected CTC?';
        }
        return array_values(array_unique($q));
    }

    /** "4.5 LPA expected (budget 4 LPA)" → "4.5 LPA expected, budget 4 LPA" */
    private static function plain(string $note): string
    {
        return preg_replace('/ \((budget|max) ([^()]*)\)$/', ', $1 $2', $note);
    }

    private function article(string $w): string
    {
        return preg_match('/^[aeiou]/i', $w) ? 'an' : 'a';
    }

    private function ym(int $m): string
    {
        $y = intdiv($m - 1, 12);
        return date('M', mktime(0, 0, 0, $m - $y * 12, 1)) . ' ' . $y;
    }

    private function span(int $months): string
    {
        return $months >= 12 ? experience($months) : $months . ' months';
    }
}
