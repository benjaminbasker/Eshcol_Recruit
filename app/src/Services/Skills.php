<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\Db;

/**
 * The dictionary: finds skills, qualifications, places and job titles in text,
 * treating every synonym ("TallyPrime", "Tally ERP 9") as its one canonical name.
 * A word can belong to more than one category ("recruiter" is a skill and a job title),
 * so everything is looked up within its category.
 */
final class Skills
{
    public const CATEGORIES = ['skill', 'qualification', 'location', 'designation', 'locality'];

    /** @var array<string, array<string, string>> category => lowercase term => canonical */
    private array $terms = [];
    /** @var array<string, array<string, string[]>> category => lowercase canonical => its terms */
    private array $synonyms = [];
    /** @var array<string, list<string>> category => compiled regexes (in chunks: PCRE limits a pattern to about 30 KB) */
    private array $patterns = [];
    /** @var array<string, string> locality canonical => "City|Zone" */
    private array $localityArea = [];

    /** @var array{0:string, 1:array, 2:array, 3:array}|null the loaded dictionary, kept for the rest of the request */
    private static ?array $loaded = null;

    public function __construct(Db $db)
    {
        // With O*NET's titles the dictionary has about 13,000 entries: loaded once per request, and
        // again only if it changed (a cheap count and newest id tell).
        $stamp = implode(':', $db->one('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS m FROM skills_dictionary') ?: []);
        $this->stamp = $stamp;
        if (self::$loaded !== null && self::$loaded[0] === $stamp) {
            [, $this->terms, $this->synonyms, $this->localityArea] = self::$loaded;
            return;
        }
        self::$compiled = [];
        foreach ($db->all('SELECT term, canonical, category, area FROM skills_dictionary') as $r) {
            $t = mb_strtolower($r['term']);
            $this->terms[$r['category']][$t] = $r['canonical'];
            $this->synonyms[$r['category']][mb_strtolower($r['canonical'])][] = $t;
            if ($r['category'] === 'locality' && $r['area']) {
                $this->localityArea[$r['canonical']] = $r['area'];
            }
        }
        self::$loaded = [$stamp, $this->terms, $this->synonyms, $this->localityArea];
    }

    private string $stamp = '';
    /** @var array<string, list<string>> category => compiled patterns, shared for the request */
    private static array $compiled = [];

    /** Is this word or phrase in the dictionary, in any category (or the one given)? */
    public function knows(string $value, ?string $category = null): bool
    {
        $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
        foreach ($category ? [$category] : self::CATEGORIES as $cat) {
            if (isset($this->terms[$cat][$v]) || isset($this->synonyms[$cat][$v])) {
                return true;
            }
        }
        return false;
    }

    /** A locality's city (as the location dictionary names it) and, in Pune, its zone. */
    public function localityOf(string $locality): ?array
    {
        $canon = $this->canonical($locality, 'locality');
        $area = $this->localityArea[$canon] ?? null;
        if ($area === null) {
            return null;
        }
        [$city, $zone] = array_pad(explode('|', $area, 2), 2, '');
        return ['locality' => $canon, 'city' => $this->canonical($city, 'location'), 'town' => $city, 'zone' => $zone !== '' ? $zone : null];
    }

    /** Canonical name for a free-text value ("tallyprime" -> "Tally"); unknown values pass through. */
    public function canonical(string $value, ?string $category = null): string
    {
        $v = mb_strtolower(trim($value));
        foreach ($category ? [$category] : self::CATEGORIES as $cat) {
            if (isset($this->terms[$cat][$v])) {
                return $this->terms[$cat][$v];
            }
        }
        return trim($value);
    }

    /** Every term that counts as this value, in whichever categories know it, plus the value itself. */
    public function termsFor(string $value): array
    {
        $v = mb_strtolower(trim($value));
        $list = [$v];
        foreach (self::CATEGORIES as $cat) {
            $canon = isset($this->terms[$cat][$v]) ? mb_strtolower($this->terms[$cat][$v]) : (isset($this->synonyms[$cat][$v]) ? $v : null);
            if ($canon !== null) {
                $list[] = $canon;
                array_push($list, ...($this->synonyms[$cat][$canon] ?? []));
            }
        }
        return array_values(array_unique(array_filter($list)));
    }

    /**
     * Finds dictionary entries of one category in text.
     * @return array<string, array{term:string, offset:int}> canonical => first occurrence
     */
    public function find(string $text, string $category = 'skill'): array
    {
        $found = [];
        foreach ($this->matches($text, $category) as [$match, $offset]) {
            $canon = $this->terms[$category][mb_strtolower(preg_replace('/\s+/u', ' ', $match))] ?? null;
            if ($canon && !isset($found[$canon])) {
                $found[$canon] = ['term' => $match, 'offset' => $offset];
            }
        }
        return $found;
    }

    /**
     * Every match of one category's terms, in text order, longest first where two start together and
     * none overlapping ("sap fico" beats "sap"): the same result as one big pattern, from the chunks.
     * @return list<array{0:string, 1:int}>
     */
    private function matches(string $text, string $category): array
    {
        $this->patterns[$category] ??= (self::$compiled[$category] ??= $this->compileAll(array_keys($this->terms[$category] ?? [])));
        $all = [];
        foreach ($this->patterns[$category] as $re) {
            if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$match, $offset]) {
                    $all[] = [$match, $offset];
                }
            }
        }
        usort($all, fn ($a, $b) => [$a[1], -strlen($a[0])] <=> [$b[1], -strlen($b[0])]);
        $out = [];
        $end = -1;
        foreach ($all as $x) {
            if ($x[1] >= $end) {
                $out[] = $x;
                $end = $x[1] + strlen($x[0]);
            }
        }
        return $out;
    }

    /** Every occurrence of one category's entries, in text order: [canonical, offset]. */
    public function findAll(string $text, string $category): array
    {
        $out = [];
        foreach ($this->matches($text, $category) as [$match, $offset]) {
            $canon = $this->terms[$category][mb_strtolower(preg_replace('/\s+/u', ' ', $match))] ?? null;
            if ($canon) {
                $out[] = [$canon, $offset];
            }
        }
        return $out;
    }

    /** Where any of a value's terms appear in text, or null. */
    public function locate(string $text, string $value): ?array
    {
        $best = null;
        foreach ($this->compileAll($this->termsFor($value)) as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) && ($best === null || $m[1][1] < $best['offset'])) {
                $best = ['term' => $m[1][0], 'offset' => $m[1][1]];
            }
        }
        return $best;
    }

    /** The terms as whole-word patterns of at most about 20 KB each, longest terms first. */
    private function compileAll(array $terms): array
    {
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $out = [];
        $chunk = [];
        $size = 0;
        foreach ($terms as $t) {
            $len = strlen($t) + 8;
            if ($chunk && $size + $len > 20000) {
                $out[] = $this->compile($chunk);
                $chunk = [];
                $size = 0;
            }
            $chunk[] = $t;
            $size += $len;
        }
        if ($chunk) {
            $out[] = $this->compile($chunk);
        }
        return $out;
    }

    /** Whole-word, case-insensitive match; longest terms first so "sap fico" beats "sap". */
    private function compile(array $terms): string
    {
        if (!$terms) {
            return '';
        }
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $alts = implode('|', array_map(fn ($t) => preg_replace('/\\\\ /', '\s+', preg_quote($t, '/')), $terms));
        return '/(?<![\p{L}\p{N}])(' . $alts . ')(?![\p{L}\p{N}])/iu';
    }

    /** A readable snippet of text around an offset, with the matched term marked. */
    public static function snippet(string $text, int $offset, int $length, int $radius = 70): string
    {
        $start = max(0, $offset - $radius);
        $before = substr($text, $start, $offset - $start);
        $hit = substr($text, $offset, $length);
        $after = substr($text, $offset + $length, $radius);
        $clean = fn (string $s) => preg_replace('/\s+/u', ' ', mb_scrub($s));
        $lead = $start > 0 ? '…' : '';
        $tail = $offset + $length + $radius < strlen($text) ? '…' : '';
        return $lead . e($clean($before)) . '<mark>' . e($clean($hit)) . '</mark>' . e($clean($after)) . $tail;
    }
}
