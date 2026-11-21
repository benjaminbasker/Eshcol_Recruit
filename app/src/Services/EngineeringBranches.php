<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Every engineering branch (specialisation) in India, under one name each, with the ways CVs write it
 * (Benjamin, 21 Nov 2026: "a complete inventory of all the Engineering specializations; CSE and Computer
 * Science Engineering are the same"). Used by the branch rule on a position: a B.E. / B.Tech / M.E. / M.Tech
 * or diploma in a branch the position takes passes; one in any other branch (Civil on a hydraulics shop
 * floor) is Not suitable; one whose branch the CV doesn't show is asked on the details form, never rejected.
 *
 * The patterns run on lower-case text with dots taken out ("B.E." = "be"), most specific first, and what one
 * branch matches is blanked before the next looks, so "Electrical and Electronics" is EEE (not Electrical and
 * Electronics both) and "Electromechanical" is not also Mechanical. Pure: tested directly.
 */
final class EngineeringBranches
{
    /** code => [name, group, patterns]. The order matters (see above). */
    public const BRANCHES = [
        // Electrical and electronics family: the combined names first.
        'eee' => ['Electrical & Electronics (EEE)', 'Electrical & Electronics', ['electrical\s*(?:and|&|\/)?\s*electronics?(?:\s*engg|\s*engineering)?', '\beee\b', '\bdeee\b', '\be\s*&\s*e\b', '\bee\s*&\s*e\b']],
        'electrical_computer' => ['Electrical & Computer', 'Electrical & Electronics', ['electrical\s*(?:and|&|\/)\s*computer']],
        'ece' => ['Electronics & Communication (ECE)', 'Electrical & Electronics', ['electronics?\s*(?:and|&|\/)?\s*communications?', '\bece\b', '\bdece\b', '\be\s*&\s*c\b', '\becm\b']],
        'etc' => ['Electronics & Telecommunication (E&TC)', 'Electrical & Electronics', ['electronics?\s*(?:and|&|\/)?\s*tele\s*-?\s*communications?', '\be\s*&\s*tc\b', '\bentc\b', '\bextc\b', '\betc\b', '\betce\b']],
        'eie' => ['Electronics & Instrumentation (EIE)', 'Electrical & Electronics', ['electronics?\s*(?:and|&|\/)?\s*instrumentation', '\beie\b', '\be\s*&\s*i\b', '\bei\b']],
        'ice' => ['Instrumentation & Control', 'Electrical & Electronics', ['instrumentation\s*(?:and|&|\/)?\s*control', '\bice\b', '\bi\s*&\s*c\b']],
        'instrumentation' => ['Instrumentation', 'Electrical & Electronics', ['instrumentation']],
        'medical_electronics' => ['Medical Electronics', 'Electrical & Electronics', ['medical\s*electronics']],
        'electromechanical' => ['Electromechanical', 'Mechanical & Production', ['electro\s*-?\s*mechanical']],
        'electrical' => ['Electrical', 'Electrical & Electronics', ['electrical']],
        'electronics' => ['Electronics', 'Electrical & Electronics', ['electronics?\b']],
        // Mechanical and production family.
        'mechatronics' => ['Mechatronics', 'Mechanical & Production', ['mechatronics?', '\bmechtronics\b']],
        'automobile' => ['Automobile / Automotive', 'Mechanical & Production', ['automobile', 'automotive', '\bdae\b']],
        'aeronautical' => ['Aeronautical / Aerospace', 'Mechanical & Production', ['aeronautic(?:al|s)?', 'aerospace', 'aircraft\s*maintenance']],
        'marine' => ['Marine', 'Mechanical & Production', ['marine\s*(?:engineering|engg|tech)', 'naval\s*architecture']],
        'industrial_production' => ['Industrial & Production', 'Mechanical & Production', ['industrial\s*(?:and|&|\/)\s*production', 'production\s*(?:and|&|\/)\s*industrial', '\bipe\b']],
        'production' => ['Production', 'Mechanical & Production', ['production']],
        'manufacturing' => ['Manufacturing', 'Mechanical & Production', ['manufacturing']],
        'industrial' => ['Industrial', 'Mechanical & Production', ['industrial\s*(?:engineering|engg|management engineering)', '\bindustrial\b(?!\s*(?:bio|chem|safety|design))']],
        'tool_die' => ['Tool & Die', 'Mechanical & Production', ['tool\s*(?:and|&)\s*die', 'tool\s*(?:engineering|design)', '\bdtdm\b']],
        'robotics' => ['Robotics & Automation', 'Mechanical & Production', ['robotics?', 'automation\s*(?:engineering|and robotics)']],
        'mechanical' => ['Mechanical', 'Mechanical & Production', ['mechanical', '\bmech\b', '\bdme\b', '\bdmech\b']],
        'metallurgy' => ['Metallurgy & Materials', 'Mechanical & Production', ['metallurg(?:y|ical)', 'materials?\s*(?:science|engineering)']],
        'mining' => ['Mining', 'Mechanical & Production', ['mining']],
        'plastics' => ['Plastics / Polymer', 'Chemical & Process', ['plastics?\s*(?:technology|engineering)', 'polymer', '\bdpt\b']],
        'rubber' => ['Rubber Technology', 'Chemical & Process', ['rubber']],
        // Computing family.
        'ai_ds' => ['AI & Data Science', 'Computing', ['artificial\s*intelligence', 'data\s*science', '\bai\s*(?:and|&)\s*(?:ds|ml|data)', '\baids\b', '\baiml\b', 'machine\s*learning']],
        'cyber' => ['Cyber Security', 'Computing', ['cyber\s*security']],
        'iot' => ['Internet of Things', 'Computing', ['internet\s*of\s*things', '\biot\b']],
        'cse' => ['Computer Science (CSE)', 'Computing', ['computer\s*science', 'computer\s*(?:engineering|engg)', '\bcse\b', '\bcs\s*&?\s*e\b', '\bcs\b', '\bdcme\b', '\bdcse\b']],
        'it' => ['Information Technology (IT)', 'Computing', ['information\s*technology', '\bit\b']],
        // Civil and others.
        'civil' => ['Civil', 'Civil & Infrastructure', ['civil', 'structural\s*engineering', 'construction\s*(?:technology|engineering)', '\bdce\b']],
        'environmental' => ['Environmental', 'Civil & Infrastructure', ['environment(?:al)?\s*(?:engineering|engg|science)']],
        'geoinformatics' => ['Geoinformatics', 'Civil & Infrastructure', ['geo\s*-?\s*informatics']],
        'chemical' => ['Chemical', 'Chemical & Process', ['chemical', '\bchem\b']],
        'petroleum' => ['Petroleum / Petrochemical', 'Chemical & Process', ['petro(?:leum|chemical)']],
        'textile' => ['Textile / Fashion Technology', 'Chemical & Process', ['textile', 'fashion\s*technology', 'handloom']],
        'food' => ['Food Technology', 'Chemical & Process', ['food\s*(?:technology|processing|engineering)']],
        'leather' => ['Leather Technology', 'Chemical & Process', ['leather']],
        'ceramic' => ['Ceramic', 'Chemical & Process', ['ceramics?']],
        'printing' => ['Printing Technology', 'Chemical & Process', ['printing']],
        'pharma' => ['Pharmaceutical Technology', 'Chemical & Process', ['pharmaceutical\s*technology']],
        'biomedical' => ['Biomedical', 'Life Sciences', ['bio\s*-?\s*medical']],
        'biotech' => ['Biotechnology', 'Life Sciences', ['bio\s*-?\s*tech(?:nology)?', 'genetic\s*engineering']],
        'agricultural' => ['Agricultural', 'Life Sciences', ['agricultur(?:e|al)\s*(?:engineering|engg)', 'agri\s*engineering']],
        'nanotech' => ['Nanotechnology', 'Life Sciences', ['nano\s*-?\s*tech(?:nology)?']],
    ];

    /** The engineering qualifications, highest first. */
    public const LEVELS = ['master' => 'M.E. / M.Tech', 'degree' => 'B.E. / B.Tech', 'diploma' => 'Diploma'];

    /** The markers that start a qualification in the text, dots taken out. */
    private const MARKERS = [
        'master' => ['\bm\s?tech\b', '\bm\s?e\b(?=\s*[\(\-–:,\/]|\s+(?:in\s+)?[a-z&]|$)', 'master\'?s?\s*(?:degree\s*)?(?:of|in)\s*(?:engineering|technology)', '\bmsc\s*\(?\s*engg'],
        'degree' => ['\bb\s?tech\b', '\bb\s?e\b(?=\s*[\(\-–:,\/]|\s+(?:in\s+)?[a-z&]|$)', 'bachelor\'?s?\s*(?:degree\s*)?(?:of|in)\s*(?:engineering|technology)', '\bamie\b', '\bbsc\s*\(?\s*engg'],
        'diploma' => ['\bdiploma\b', '\bpolytechnic\b', '\bdme\b', '\bdeee\b', '\bdece\b', '\bdae\b', '\bdcme\b', '\bdcse\b', '\bdce\b', '\bdtdm\b', '\bdpt\b', '\bdmech\b'],
        'iti' => ['\biti\b', '\bnctvt\b', '\bncvt\b'],
        'other' => ['\bb\s?sc\b', '\bbca\b', '\bb\s?com\b', '\bbba\b', '\bbms\b', '\bba\b', '\bb\s?arch\b', '\bb\s?pharm\b', '\bm\s?sc\b', '\bmca\b', '\bm\s?com\b', '\bma\b', '\bmba\b', '\bpgdm\b', '\bllb\b'],
    ];

    /** The default list for shop-floor technician positions (Trainee Technicians, 21 Nov 2026). */
    public const TECHNICIAN_DEFAULT = ['mechanical', 'eee', 'electrical', 'mechatronics', 'electromechanical', 'ece', 'eie', 'electrical_computer',
        'production', 'manufacturing', 'industrial', 'industrial_production'];

    public static function name(string $code): string
    {
        return self::BRANCHES[$code][0] ?? $code;
    }

    /** Branches by group, for the position form. @return array<string, array<string, string>> group => code => name */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::BRANCHES as $code => [$name, $group]) {
            $out[$group][$code] = $name;
        }
        return $out;
    }

    /** The branch codes in a piece of text, most specific first, none counted twice. @return list<string> */
    public static function branchesIn(string $text): array
    {
        $t = ' ' . self::flat($text) . ' ';
        $found = [];
        foreach (self::BRANCHES as $code => [, , $patterns]) {
            foreach ($patterns as $p) {
                if (preg_match('/' . $p . '/u', $t)) {
                    $found[] = $code;
                    $t = (string) preg_replace('/' . $p . '/u', ' ', $t);
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * The qualifications in a CV's qualification line, each with its level and branches.
     * "B.E. Mechanical Engineering (2023); Diploma in EEE (2019); HSC" gives
     * [['level' => 'degree', 'branches' => ['mechanical'], 'text' => …], ['level' => 'diploma', 'branches' => ['eee'], …]].
     * @return list<array{level: string, branches: list<string>, text: string}>
     */
    public static function parse(?string $qualifications): array
    {
        $t = self::flat((string) $qualifications);
        if ($t === '') {
            return [];
        }
        $hits = [];
        foreach (self::MARKERS as $level => $patterns) {
            foreach ($patterns as $p) {
                if (preg_match_all('/' . $p . '/u', $t, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [$s, $at]) {
                        // A diploma abbreviation ("DME") is its own branch too: keep it in the segment.
                        $hits[$at] ??= ['level' => $level, 'at' => $at, 'len' => strlen($s)];
                    }
                }
            }
        }
        ksort($hits);
        $hits = array_values($hits);
        $out = [];
        foreach ($hits as $i => $h) {
            $end = $hits[$i + 1]['at'] ?? strlen($t);
            $seg = substr($t, $h['at'], $end - $h['at']);
            // A segment that is only the marker ("b e,") takes the words up to the next comma or semicolon only.
            $out[] = ['level' => $h['level'], 'branches' => in_array($h['level'], ['master', 'degree', 'diploma'], true) ? self::branchesIn($seg) : [], 'text' => trim($seg, " ,;|/-")];
        }
        return $out;
    }

    /**
     * The branch rule (21 Nov 2026) on a position that takes these branches.
     *  - pass: an engineering degree, master's or diploma in one of them; 'level' says which (degree or diploma) for the
     *    experience window; a B.E. counts above a diploma when both pass.
     *  - fail: engineering only in other branches (B.E. Civil), or no engineering at all (B.Sc, BCA, ITI only).
     *  - confirm: no qualification shown, or an engineering qualification whose branch isn't shown: the form asks.
     * @param list<string> $accepted branch codes
     * @return array{0: 'pass'|'fail'|'confirm', 1: string, 2: ?string} verdict, reason, level ('degree'|'diploma'|null)
     */
    public static function verdict(?string $qualifications, array $accepted): array
    {
        $quals = self::parse($qualifications);
        $eng = array_values(array_filter($quals, fn ($q) => isset(self::LEVELS[$q['level']])));
        $short = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string) $qualifications)), 0, 60, '…');
        if (!$eng) {
            if (trim((string) $qualifications) === '') {
                return ['confirm', 'qualification not known: confirm the branch', null];
            }
            $known = array_filter($quals, fn ($q) => in_array($q['level'], ['iti', 'other'], true));
            return $known ? ['fail', "not an engineering degree or diploma ($short)", null]
                : ['confirm', "no engineering degree or diploma shown ($short): confirm", null];
        }
        $rank = ['master' => 3, 'degree' => 2, 'diploma' => 1];
        $best = null;
        foreach ($eng as $q) {
            if (array_intersect($q['branches'], $accepted) && ($best === null || $rank[$q['level']] > $rank[$best['level']])) {
                $best = $q;
            }
        }
        if ($best) {
            $code = array_values(array_intersect($best['branches'], $accepted))[0];
            return ['pass', self::LEVELS[$best['level']] . ' ' . self::name($code), $best['level'] === 'diploma' ? 'diploma' : 'degree'];
        }
        $unknown = array_values(array_filter($eng, fn ($q) => !$q['branches']));
        if ($unknown) {
            $lvl = $unknown[0]['level'];
            return ['confirm', self::LEVELS[$lvl] . ', branch not shown: confirm', $lvl === 'diploma' ? 'diploma' : 'degree'];
        }
        $names = [];
        foreach ($eng as $q) {
            $names[] = self::LEVELS[$q['level']] . ' ' . implode(', ', array_map([self::class, 'name'], $q['branches']));
        }
        return ['fail', implode('; ', array_unique($names)) . ': branch not accepted', null];
    }

    /** The accepted codes stored on a position ("mechanical,eee,…"), only known ones. @return list<string> */
    public static function fromRole(array $role): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($role['eng_branches'] ?? ''))), fn ($c) => isset(self::BRANCHES[$c])));
    }

    /** Lower case, dots taken out, "&amp;" and odd spaces tidied. */
    private static function flat(string $s): string
    {
        $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
        $s = str_replace(['.', "\u{2019}", "\u{00A0}"], ['', "'", ' '], $s);
        return trim((string) preg_replace('/[ \t]+/u', ' ', $s));
    }
}
