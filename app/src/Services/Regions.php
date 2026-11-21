<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Where a candidate lives, as a district and a state, and the tier a position gives that place
 * (Benjamin, 21 Nov 2026, Trainee Technicians at a plant in Thiruvallur): Chennai, Kancheepuram,
 * Tiruvallur and Chengalpattu first; the rest of Tamil Nadu (and Puducherry) second; Andhra Pradesh,
 * Telangana, Karnataka and Kerala third; anywhere else Not suitable.
 *
 * The district comes from, in order: the district they chose on the details form; their locality; their
 * town (database update 076 gives every town and locality the app knows a district and state, from
 * OpenStreetMap); their PIN code (the state, and the district round Chennai). A place nobody can place
 * keeps no one out: the details form asks for the district.
 */
final class Regions
{
    /** Tamil Nadu's 38 districts, as the app names them. */
    public const TN_DISTRICTS = ['Ariyalur', 'Chengalpattu', 'Chennai', 'Coimbatore', 'Cuddalore', 'Dharmapuri', 'Dindigul', 'Erode', 'Kallakurichi',
        'Kancheepuram', 'Kanniyakumari', 'Karur', 'Krishnagiri', 'Madurai', 'Mayiladuthurai', 'Nagapattinam', 'Namakkal', 'Nilgiris', 'Perambalur',
        'Pudukkottai', 'Ramanathapuram', 'Ranipet', 'Salem', 'Sivaganga', 'Tenkasi', 'Thanjavur', 'Theni', 'Thoothukudi', 'Tiruchirappalli',
        'Tirunelveli', 'Tirupathur', 'Tiruppur', 'Tiruvallur', 'Tiruvannamalai', 'Tiruvarur', 'Vellore', 'Viluppuram', 'Virudhunagar'];

    /** The other ways district names are written (OpenStreetMap's spellings included) => the app's name. */
    public const DISTRICT_ALIASES = [
        'thiruvallur' => 'Tiruvallur', 'tiruvalluvar' => 'Tiruvallur', 'tiruvalur' => 'Tiruvallur', 'thiruvalluvar' => 'Tiruvallur',
        'kanchipuram' => 'Kancheepuram', 'kanchi' => 'Kancheepuram', 'kanchepuram' => 'Kancheepuram',
        'chengalpet' => 'Chengalpattu', 'chengleput' => 'Chengalpattu', 'chengalpat' => 'Chengalpattu',
        'madras' => 'Chennai', 'chennai district' => 'Chennai',
        'kanyakumari' => 'Kanniyakumari', 'nagercoil' => 'Kanniyakumari',
        'the nilgiris' => 'Nilgiris', 'nilgiri' => 'Nilgiris', 'ooty' => 'Nilgiris', 'udhagamandalam' => 'Nilgiris',
        'thoothukkudi' => 'Thoothukudi', 'tuticorin' => 'Thoothukudi',
        'tiruchchirappalli' => 'Tiruchirappalli', 'trichy' => 'Tiruchirappalli', 'tiruchirapalli' => 'Tiruchirappalli', 'tiruchi' => 'Tiruchirappalli',
        'villupuram' => 'Viluppuram', 'vilupuram' => 'Viluppuram', 'villuppuram' => 'Viluppuram',
        'tirupattur' => 'Tirupathur', 'thirupathur' => 'Tirupathur', 'tirupatur' => 'Tirupathur',
        'tirupur' => 'Tiruppur', 'thiruppur' => 'Tiruppur', 'tiruvannamalai' => 'Tiruvannamalai', 'thiruvannamalai' => 'Tiruvannamalai',
        'thiruvarur' => 'Tiruvarur', 'tanjore' => 'Thanjavur', 'thanjavore' => 'Thanjavur', 'sivagangai' => 'Sivaganga', 'pudukottai' => 'Pudukkottai',
        'ramnad' => 'Ramanathapuram', 'thirunelveli' => 'Tirunelveli', 'tinnevelly' => 'Tirunelveli', 'kovai' => 'Coimbatore',
        'mayavaram' => 'Mayiladuthurai', 'dindugal' => 'Dindigul', 'virudunagar' => 'Virudhunagar',
    ];

    /** States as the app names them, and other ways they are written. */
    public const STATE_ALIASES = [
        'tamil nadu' => 'Tamil Nadu', 'tamilnadu' => 'Tamil Nadu', 'tn' => 'Tamil Nadu',
        'puducherry' => 'Puducherry', 'pondicherry' => 'Puducherry', 'pondy' => 'Puducherry',
        'andhra pradesh' => 'Andhra Pradesh', 'andhra' => 'Andhra Pradesh', 'ap' => 'Andhra Pradesh',
        'telangana' => 'Telangana', 'telengana' => 'Telangana', 'ts' => 'Telangana',
        'karnataka' => 'Karnataka', 'kerala' => 'Kerala', 'keralam' => 'Kerala',
        'maharashtra' => 'Maharashtra', 'goa' => 'Goa', 'gujarat' => 'Gujarat', 'odisha' => 'Odisha', 'orissa' => 'Odisha',
        'west bengal' => 'West Bengal', 'bihar' => 'Bihar', 'jharkhand' => 'Jharkhand', 'uttar pradesh' => 'Uttar Pradesh',
        'madhya pradesh' => 'Madhya Pradesh', 'chhattisgarh' => 'Chhattisgarh', 'rajasthan' => 'Rajasthan', 'punjab' => 'Punjab',
        'haryana' => 'Haryana', 'delhi' => 'Delhi', 'assam' => 'Assam', 'uttarakhand' => 'Uttarakhand', 'himachal pradesh' => 'Himachal Pradesh',
    ];

    /** The details form's district list (Tamil Nadu by district, then states). */
    public const OTHER_STATES = ['Puducherry', 'Andhra Pradesh', 'Telangana', 'Karnataka', 'Kerala'];
    public const ANOTHER_STATE = 'Another state';

    /** Trainee Technicians' tiers (21 Nov 2026): a starting point on the position form. */
    public const DEFAULT_TIERS = [
        '1' => ['Chennai', 'Kancheepuram', 'Tiruvallur', 'Chengalpattu'],
        '2' => ['Tamil Nadu', 'Puducherry'],
        '3' => ['Andhra Pradesh', 'Telangana', 'Karnataka', 'Kerala'],
    ];

    /** PIN code: the first two digits' state (only the ones the tiers need, plus "another state" for the rest of India). */
    private const PIN_STATES = ['50' => 'Telangana', '51' => 'Andhra Pradesh', '52' => 'Andhra Pradesh', '53' => 'Andhra Pradesh',
        '56' => 'Karnataka', '57' => 'Karnataka', '58' => 'Karnataka', '59' => 'Karnataka', '60' => 'Tamil Nadu', '61' => 'Tamil Nadu',
        '62' => 'Tamil Nadu', '63' => 'Tamil Nadu', '64' => 'Tamil Nadu', '67' => 'Kerala', '68' => 'Kerala', '69' => 'Kerala'];
    /** Round Chennai the first three digits give the district closely enough for the tiers (all four are tier 1). */
    private const PIN_DISTRICTS = ['600' => 'Chennai', '601' => 'Tiruvallur', '602' => 'Tiruvallur', '603' => 'Chengalpattu'];

    /** @var array<string, array{district: ?string, state: ?string}>|null "category|place" lower case => region */
    private static ?array $regions = null;

    /** The tier set on a position (scope "tiers"), else null. @return array<string, list<string>>|null */
    public static function tiersOf(array $role): ?array
    {
        if (($role['location_scope'] ?? '') !== 'tiers') {
            return null;
        }
        $t = json_decode((string) ($role['location_tiers'] ?? ''), true);
        if (!is_array($t)) {
            return self::DEFAULT_TIERS;
        }
        $out = [];
        foreach (['1', '2', '3'] as $k) {
            $out[$k] = array_values(array_filter(array_map('trim', (array) ($t[$k] ?? [])), fn ($v) => $v !== ''));
        }
        return array_filter($out) ? $out : self::DEFAULT_TIERS;
    }

    /** "Tamil Nadu, Puducherry" typed on the form => the list, each name tidied to the app's spelling. Pure. */
    public static function parseList(string $typed): array
    {
        $out = [];
        foreach (preg_split('/\s*[,;\n\/]\s*/u', $typed) ?: [] as $p) {
            if (($p = trim($p)) !== '') {
                $out[] = self::district($p) ?? self::state($p) ?? mb_convert_case($p, MB_CASE_TITLE);
            }
        }
        return array_values(array_unique($out));
    }

    /** The app's name for a Tamil Nadu district, or null. Pure. */
    public static function district(?string $name): ?string
    {
        $k = self::key($name);
        if ($k === '') {
            return null;
        }
        $k = (string) preg_replace('/\s+district$/', '', $k);
        foreach (self::TN_DISTRICTS as $d) {
            if (mb_strtolower($d) === $k) {
                return $d;
            }
        }
        return self::DISTRICT_ALIASES[$k] ?? null;
    }

    /** The app's name for a state, or null. Pure. */
    public static function state(?string $name): ?string
    {
        $k = self::key($name);
        return $k === '' ? null : (self::STATE_ALIASES[$k] ?? null);
    }

    /**
     * The tier for a region: 1, 2 or 3 (the first tier that lists its district or its state), 0 when it is placed
     * and no tier takes it (Not suitable), null when the region isn't known. Pure.
     * @param array{district: ?string, state: ?string}|null $region
     */
    public static function tierFor(array $tiers, ?array $region): ?int
    {
        if (!$region || (($region['district'] ?? null) === null && ($region['state'] ?? null) === null)) {
            return null;
        }
        $d = mb_strtolower((string) ($region['district'] ?? ''));
        $s = mb_strtolower((string) ($region['state'] ?? ''));
        foreach (['1', '2', '3'] as $k) {
            foreach ($tiers[$k] ?? [] as $name) {
                $n = mb_strtolower($name);
                if (($d !== '' && $n === $d) || ($s !== '' && $n === $s)) {
                    return (int) $k;
                }
            }
        }
        // A district in Tamil Nadu (or Puducherry) whose state wasn't recorded: placed by the district.
        return $d !== '' && $s === '' ? null : 0;
    }

    /**
     * Where the candidate lives: ['district' => …, 'state' => …, 'from' => 'form'|'locality'|'town'|'pin'] or null.
     * $lookup(category, place): the region of a place the app knows (database), replaceable in tests.
     */
    public static function of(array $cand, ?callable $lookup = null, ?Skills $skills = null): ?array
    {
        $lookup ??= [self::class, 'lookup'];
        // 1. The district they chose on the details form.
        $hd = trim((string) ($cand['home_district'] ?? ''));
        if ($hd !== '' && $hd !== self::ANOTHER_STATE) {
            if ($d = self::district($hd)) {
                return ['district' => $d, 'state' => 'Tamil Nadu', 'from' => 'form'];
            }
            if ($s = self::state($hd)) {
                return ['district' => null, 'state' => $s, 'from' => 'form'];
            }
        }
        if ($hd === self::ANOTHER_STATE) {
            return ['district' => null, 'state' => self::ANOTHER_STATE, 'from' => 'form'];
        }
        // 2. Their locality, then 3. their town ("Ambattur, Chennai": the last known part wins, as the city).
        $loc = trim((string) ($cand['locality'] ?? ''));
        if ($loc !== '' && ($r = $lookup('locality', $skills ? $skills->canonical($loc, 'locality') : $loc))) {
            return $r + ['from' => 'locality'];
        }
        foreach (array_reverse(array_map('trim', explode(',', (string) ($cand['location'] ?? '')))) as $part) {
            if ($part === '') {
                continue;
            }
            if ($r = $lookup('location', $skills ? $skills->canonical($part, 'location') : $part) ?? $lookup('locality', $skills ? $skills->canonical($part, 'locality') : $part)) {
                return $r + ['from' => 'town'];
            }
            if ($d = self::district($part)) {
                return ['district' => $d, 'state' => 'Tamil Nadu', 'from' => 'town'];
            }
            if ($s = self::state($part)) {
                return ['district' => null, 'state' => $s, 'from' => 'town'];
            }
        }
        // 4. Their PIN code.
        $pin = preg_replace('/\D/', '', (string) ($cand['pin_code'] ?? ''));
        if (strlen($pin) === 6 && $pin[0] !== '0') {
            if (str_starts_with($pin, '605') && (int) substr($pin, 3) <= 14) {
                return ['district' => 'Puducherry', 'state' => 'Puducherry', 'from' => 'pin'];
            }
            $state = self::PIN_STATES[substr($pin, 0, 2)] ?? self::ANOTHER_STATE;
            return ['district' => self::PIN_DISTRICTS[substr($pin, 0, 3)] ?? null, 'state' => $state, 'from' => 'pin'];
        }
        return null;
    }

    /** A place's district and state from the database (update 076), or null. */
    public static function lookup(string $category, string $place): ?array
    {
        if (self::$regions === null) {
            self::$regions = [];
            try {
                foreach (App::get()->db->all('SELECT category, place, district, state FROM place_regions') as $r) {
                    self::$regions[$r['category'] . '|' . mb_strtolower((string) $r['place'])] = ['district' => $r['district'], 'state' => $r['state']];
                }
            } catch (\Throwable) {
                // before update 076: the form's district and the PIN code only
            }
        }
        return self::$regions[$category . '|' . mb_strtolower(trim($place))] ?? null;
    }

    /** "Tier 1 · Tiruvallur" / "Tier 3 · Karnataka" / "Outside the area · Maharashtra". Pure. */
    public static function label(?int $tier, ?array $region): string
    {
        $where = $region ? (string) ($region['district'] ?? '') : '';
        if ($region && ($region['state'] ?? '') !== '' && ($where === '' || !in_array($region['state'], ['Tamil Nadu'], true))) {
            $where = $where !== '' && $where !== $region['state'] ? "$where, {$region['state']}" : (string) $region['state'];
        }
        return match (true) {
            $tier === null => 'District not known',
            $tier === 0 => 'Outside the area' . ($where !== '' ? " · $where" : ''),
            default => "Tier $tier" . ($where !== '' ? " · $where" : ''),
        };
    }

    /** The tiers as a sentence for a reason: "Tamil Nadu, Puducherry, Andhra Pradesh…". Pure. */
    public static function covered(array $tiers): string
    {
        $all = array_merge(...array_values(array_map(fn ($v) => (array) $v, $tiers)));
        return implode(', ', array_unique($all));
    }

    private static ?bool $ready = null;

    /** Has database update 076 run (the tiers, branches and windows on positions, the district on candidates)? */
    public static function ready(App $app): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (bool) $app->db->col("SHOW COLUMNS FROM roles LIKE 'eng_branches'");
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** For tests: forget the places read from the database. */
    public static function forget(): void
    {
        self::$regions = null;
    }

    private static function key(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = str_replace(['.', '-'], ['', ' '], $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
