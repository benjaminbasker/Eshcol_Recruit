<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Is a candidate's name believable? (14 Nov 2026, after the 900 old Indeed CVs: about 55 "names" were CV headings
 * such as "Good Communication", "Employment History" or an address, because the free reader took a heading for
 * the name and Claude Haiku is not allowed to replace a name.)
 *
 * Job-board downloads are named after the person (PUSHPAK_DAHE_2026-11-12.pdf), so the file name is a good
 * witness: a name sharing nothing with an informative file name is doubtful. Where the file name says nothing
 * ("Resume.pdf"), a name made of CV-heading words is doubtful.
 */
final class NameCheck
{
    /** Words in file names that say nothing about the person. */
    private const FILE_NOISE = ['resume', 'cv', 'curriculum', 'curriculam', 'criculam', 'vitae', 'vitea', 'vite', 'biodata', 'bio', 'data', 'updated', 'update', 'new', 'latest', 'final', 'copy',
        'naukri', 'indeed', 'linkedin', 'foundit', 'monster', 'shine', 'apna', 'profile', 'my', 'doc', 'docx', 'pdf', 'file', 'document', 'scan', 'img',
        'image', 'microsoft', 'word', 'untitled', 'scanned', 'camscanner', 'whatsapp', 'adobe', 'scan', 'mr', 'mrs', 'ms', 'dr', 'ca', 'accountant', 'accounts', 'account', 'finance', 'executive', 'manager', 'fresher', 'experienced', 'job', 'application', 'for'];

    /** Words that make up CV headings, job titles and addresses, never a person's name. */
    private const HEADING_WORDS = ['communication', 'history', 'employment', 'personal', 'details', 'detail', 'information', 'info', 'management', 'skills',
        'skill', 'soft', 'about', 'me', 'profile', 'summary', 'objective', 'career', 'curriculum', 'vitae', 'resume', 'education', 'educational',
        'qualification', 'qualifications', 'passport', 'number', 'address', 'contact', 'declaration', 'experience', 'professional', 'analysis', 'statement',
        'statements', 'financial', 'taxation', 'tax', 'filing', 'payment', 'executive', 'manager', 'coordinator', 'accountant', 'assistant', 'officer',
        'analyst', 'operations', 'business', 'deputy', 'centre', 'center', 'pvt', 'ltd', 'limited', 'private', 'proteins', 'university', 'college',
        'institute', 'institution', 'course', 'year', 'percentage', 'school', 'house', 'nagar', 'road', 'street', 'floor', 'pune', 'mumbai', 'delhi',
        'new', 'chennai', 'bangalore', 'bengaluru', 'hyderabad', 'pimpri', 'problem', 'solving', 'time', 'talents', 'include', 'writing', 'achievements',
        'languages', 'hobbies', 'strengths', 'projects', 'training', 'internship', 'technical', 'computer', 'proficiency', 'key', 'responsibilities', 'or'];

    /** Lower-case words of at least two letters. */
    public static function words(string $s): array
    {
        $s = mb_strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $s)); // "PushpakDahe" → "pushpak dahe"
        $s = preg_replace('/[^a-z]+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        return array_values(array_filter(explode(' ', $s), fn ($w) => strlen($w) >= 2));
    }

    /** The words of a file name that could be the person's name (no dates, numbers, "resume", "naukri"...). */
    public static function fileWords(string $fileName): array
    {
        $base = preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($fileName));
        // Run-together noise ("ResumeCURRICULAMVITAE", "NaukriResume") is stripped from inside words too.
        $long = array_filter(self::FILE_NOISE, fn ($n) => strlen($n) >= 5);
        $words = array_map(fn ($w) => str_replace($long, '', $w), self::words($base));
        return array_values(array_filter($words, fn ($w) => strlen($w) >= 3 && !in_array($w, self::FILE_NOISE, true)));
    }

    /** Does the name share a real word with the file name? Joined spellings count ("Sathiya Balan" ~ "Sathiyabalan"). */
    public static function fileAgrees(string $name, string $fileName): bool
    {
        $file = self::fileWords($fileName);
        $mine = self::words($name);
        if (!$file || !$mine) {
            return false;
        }
        if (array_intersect(array_filter($mine, fn ($w) => strlen($w) >= 3), $file)) {
            return true;
        }
        $joinedName = implode('', $mine);
        $joinedFile = implode('', $file);
        foreach ($file as $f) {
            if (strlen($f) >= 4 && str_contains($joinedName, $f)) {
                return true;
            }
        }
        foreach ($mine as $m) {
            if (strlen($m) >= 4 && str_contains($joinedFile, $m)) {
                return true;
            }
            // Spelling variants of long names: "Sundaramurthy" ~ "Sundaramoorthi" (same first five letters).
            foreach ($file as $f) {
                if (strlen($m) >= 6 && strlen($f) >= 6 && substr($m, 0, 5) === substr($f, 0, 5)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Made only of CV-heading, job-title or address words (or mostly so)? */
    public static function headingLike(string $name): bool
    {
        $w = self::words($name);
        if (!$w) {
            return true;
        }
        $hits = count(array_filter($w, fn ($x) => in_array($x, self::HEADING_WORDS, true)));
        return $hits > 0 && $hits >= count($w) / 2;
    }

    /** Is this name doubtful for a CV with this file name? */
    public static function doubtful(?string $name, ?string $fileName): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }
        if (self::headingLike($name)) {
            return true;
        }
        $informative = $fileName !== null && self::fileWords($fileName) !== [];
        return $informative && !self::fileAgrees($name, (string) $fileName);
    }
}
