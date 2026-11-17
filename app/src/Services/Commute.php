<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * How far a candidate lives from a client's office, roughly, without a maps service.
 *
 * Pune localities carry a zone (West, North, Central, South-West, South, East). Two places
 * in the same zone are Near; neighbouring zones are Moderate; opposite sides of the city
 * are Far. Elsewhere: same locality is Near, same city is "Same city", another city is
 * "Different city". It is a guide for the recruiter, not a promise of travel time.
 */
final class Commute
{
    /** Zones next to each other in Pune. Anything not listed (and not the same zone) is Far. */
    private const PUNE_NEIGHBOURS = [
        'West' => ['North', 'Central', 'South-West'],
        'North' => ['West', 'Central', 'East'],
        'Central' => ['West', 'North', 'South-West', 'South', 'East'],
        'South-West' => ['West', 'Central', 'South'],
        'South' => ['Central', 'South-West', 'East'],
        'East' => ['North', 'Central', 'South'],
    ];

    public function __construct(private readonly Skills $skills)
    {
    }

    /**
     * The locality named in an address ("Office 301, Archway, Sopan Baug, Balewadi High Street…"), if known.
     * $mapsUrl: the client's Google Maps link; when it carries the office's own coordinates, they are the office's
     * map point (more exact than the locality's), for the distance in km.
     */
    public function localityIn(?string $address, ?string $mapsUrl = null): ?array
    {
        if (!$address) {
            return null;
        }
        // The locality named last (16 Nov 2026): in "…Sopan Baug, Balewadi High Street, Balewadi, Pune" that is the
        // wider area people know (Balewadi), just before the city.
        foreach (array_reverse(array_keys($this->skills->find($address, 'locality'))) as $loc) {
            if ($info = $this->skills->localityOf($loc)) {
                return $info + ['point' => self::pointFromMapsUrl($mapsUrl) ?? self::pointOf($info['locality'])];
            }
        }
        return null;
    }

    /**
     * @return array{level: string, label: string, tone: string, km?: int}|null  level: near|moderate|far|same_city|other_city
     * The area guide as before; when both map points are known (16 Nov 2026), "· about N km" in a straight line.
     */
    public function between(?string $candLocality, ?string $candCity, ?array $office): ?array
    {
        if (!$office) {
            return null;
        }
        $cand = $candLocality ? $this->skills->localityOf($candLocality) : null;
        $city = $cand['city'] ?? ($candCity ? $this->skills->canonical($candCity, 'location') : null);
        if (!$city) {
            return null;
        }
        if ($city !== $office['city']) {
            // Another town (17 Nov 2026, Satara for a Pune role): the straight-line km when both places have a map point
            // (their locality, else their town: district headquarters have points since update 069).
            $officePoint = $office['point'] ?? self::pointOf((string) $office['locality']);
            $candPoint = ($cand ? self::pointOf($cand['locality']) : null) ?? self::pointOf($city);
            $km = $officePoint && $candPoint ? (int) round(self::km($candPoint, $officePoint)) : null;
            return ['level' => 'other_city', 'label' => "Lives in $city" . ($km !== null ? ' · ' . self::kmLabel($km) : ''), 'tone' => 'bad'] + ($km !== null ? ['km' => $km] : []);
        }
        if (!$cand) {
            return ['level' => 'same_city', 'label' => "Same city; locality not known", 'tone' => ''];
        }
        $officePoint = $office['point'] ?? self::pointOf((string) $office['locality']);
        $candPoint = self::pointOf($cand['locality']);
        $km = $officePoint && $candPoint ? (int) round(self::km($candPoint, $officePoint)) : null;
        $with = fn (array $r): array => $km === null ? $r : ['label' => $r['label'] . ' · ' . self::kmLabel($km), 'km' => $km] + $r;
        if ($cand['locality'] === $office['locality']) {
            return $with(['level' => 'near', 'label' => 'Near: same locality', 'tone' => 'yes']);
        }
        if ($cand['zone'] && $office['zone']) {
            if ($cand['zone'] === $office['zone']) {
                return $with(['level' => 'near', 'label' => "Near: both in {$cand['zone']} " . $office['city'], 'tone' => 'yes']);
            }
            if (in_array($cand['zone'], self::PUNE_NEIGHBOURS[$office['zone']] ?? [], true)) {
                return $with(['level' => 'moderate', 'label' => "Moderate: {$cand['zone']} to {$office['zone']} " . $office['city'], 'tone' => 'sand']);
            }
            return $with(['level' => 'far', 'label' => "Far: {$cand['zone']} to {$office['zone']} " . $office['city'], 'tone' => 'bad']);
        }
        return $with(['level' => 'same_city', 'label' => 'Same city', 'tone' => '']);
    }

    /** "about 8 km", or "under 2 km". Pure. */
    public static function kmLabel(int $km): string
    {
        return $km < 2 ? 'under 2 km' : "about $km km";
    }

    /** @var array<string, array{lat: float, lng: float}>|null */
    private static ?array $points = null;

    /** A locality's map point (database update 056, from OpenStreetMap), if known. */
    public static function pointOf(string $locality): ?array
    {
        if (self::$points === null) {
            self::$points = [];
            try {
                foreach (\Eshcol\Ats\App::get()->db->all('SELECT place, lat, lng FROM place_points') as $p) {
                    self::$points[mb_strtolower((string) $p['place'])] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
                }
            } catch (\Throwable) {
                // before update 056: no distances, the area guide only
            }
        }
        return self::$points[mb_strtolower($locality)] ?? null;
    }

    /** The coordinates in a Google Maps link ("…/@18.574688,73.7725158,17z", "…!3d18.57!4d73.77", "?q=18.57,73.77"). Pure. */
    public static function pointFromMapsUrl(?string $url): ?array
    {
        $u = rawurldecode((string) $url);
        if ($u === '') {
            return null;
        }
        if (preg_match('/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $u, $m) || preg_match('/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $u, $m)
            || preg_match('/[?&](?:q|query|ll|destination)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/', $u, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }
        return null;
    }

    /** Straight-line distance in km between two map points. Pure. */
    public static function km(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;
        return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
    }

    /** "Hinjewadi, Pune" or just the city. */
    public static function place(?string $locality, ?string $city): string
    {
        $locality = trim((string) $locality);
        $city = trim((string) $city);
        return $locality !== '' && $city !== '' ? "$locality, $city" : ($locality !== '' ? $locality : $city);
    }
}
