<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * What kind of position this is, and how we describe it to candidates (own-hiring and staffing plan v2, phase 1,
 * 15 Nov 2026; wording approved by Benjamin the same day, `Eshcol_Recruit_Position_Types_Wording_Draft_15Nov2026.md`).
 *
 *   placement  client recruitment: the client employs; "our client" (as before, unchanged)
 *   staffing   Eshcol employs and deputes to the client; the client may be named ("Not necessary to hide")
 *   inhouse    Eshcol Global's own team; "Eshcol Global" from the first email
 *
 * Every candidate-facing phrase that depends on the type comes from here, so the wording is always right for it.
 * Several positions of different types in one email read as client recruitment (the safe, existing wording).
 */
final class Engagement
{
    public const TYPES = ['placement' => 'Client recruitment', 'staffing' => 'Staffing', 'inhouse' => 'Our team'];
    public const LONG = [
        'placement' => 'A client: we recruit, they employ',
        'staffing' => 'A client, on Eshcol\'s payroll (staffing)',
        'inhouse' => 'Eshcol Global\'s own team',
    ];
    public const NAME = 'Eshcol Global';

    /** The type of one position row (missing column, before update 048: client recruitment). */
    public static function of(?array $role): string
    {
        $e = (string) ($role['engagement'] ?? 'placement');
        return isset(self::TYPES[$e]) ? $e : 'placement';
    }

    /** The type for an email about these positions: theirs when they all agree, otherwise client recruitment. */
    public static function forRoles(array $roles): string
    {
        $types = array_unique(array_map(fn ($r) => self::of($r), $roles));
        return count($types) === 1 ? reset($types) : 'placement';
    }

    /** Eshcol Global's own client record (made by update 048). */
    public static function selfClientId(App $app): ?int
    {
        try {
            return ((int) $app->db->col('SELECT id FROM clients WHERE is_self = 1 ORDER BY id LIMIT 1')) ?: null;
        } catch (\PDOException) {
            return null;
        }
    }

    /** "Eshcol Global (our own team)" for our own client record, else the client's name. */
    public static function clientLabel(array $client): string
    {
        return !empty($client['is_self']) ? self::NAME . ' (our own team)' : (string) ($client['name'] ?? '');
    }

    // ------------------------------------------------------------------ candidate wording, by type

    /** "with our client in Pune" / "at Eshcol Global, Chennai" / "on Eshcol's payroll, working at Acme's site in Tiruvallur". */
    public static function whereClause(string $type, array $roles, bool $html): string
    {
        $esc = fn ($v) => $html ? CandidateEmail::h($v) : $v;
        $locs = array_unique(array_filter(array_map(fn ($r) => trim((string) ($r['location'] ?? '')), $roles)));
        $place = count($locs) === 1 ? reset($locs) : '';
        $clients = array_unique(array_filter(array_map(fn ($r) => trim((string) ($r['client_name'] ?? '')), $roles)));
        return match ($type) {
            'inhouse' => ' at ' . self::NAME . ($place !== '' ? ', ' . $esc($place) : ''),
            'staffing' => ' on Eshcol\'s payroll, working at ' . (count($clients) === 1 ? $esc(reset($clients)) . '\'s site' : 'our client\'s site')
                . ($place !== '' ? ' in ' . $esc($place) : ''),
            default => ' with ' . (count(array_unique(array_map(fn ($r) => (int) ($r['client_id'] ?? 0), $roles))) > 1 ? 'our clients' : 'our client')
                . ($place !== '' ? ' in ' . $esc($place) : ''),
        };
    }

    /** "our client's requirements" / "the role's requirements". */
    public static function requirements(string $type): string
    {
        return $type === 'inhouse' ? 'the role\'s requirements' : 'our client\'s requirements';
    }

    /** "what our client has asked for" / "what the role needs". */
    public static function askedFor(string $type): string
    {
        return $type === 'inhouse' ? 'what the role needs' : 'what our client has asked for';
    }

    /** "with our clients" / "at Eshcol or with our clients" (other roles we may consider them for). */
    public static function otherRoles(string $type): string
    {
        return $type === 'placement' ? 'with our clients' : 'at Eshcol or with our clients';
    }

    /** Who needs the salary and joining details: "our client needs" / "we need". */
    public static function whoNeeds(string $type): string
    {
        return $type === 'inhouse' ? 'we need' : 'our client needs';
    }

    /** "a good match for our client" / "a good match for the role". */
    public static function matchFor(string $type): string
    {
        return $type === 'inhouse' ? 'the role' : 'our client';
    }

    /** The email footer's "why you got this". */
    public static function footerReason(string $type): string
    {
        return match ($type) {
            'inhouse' => 'You are receiving this email because you applied for a role at Eshcol Global Services.',
            'staffing' => 'You are receiving this email because you applied for a role on Eshcol Global\'s payroll with one of our clients.',
            default => 'You are receiving this email because you applied for a role that Eshcol Global is recruiting for on behalf of our client.',
        };
    }

    /** Is the "May we put your profile forward to our client?" question asked? Not for our own team. */
    public static function asksPermission(string $type): bool
    {
        return $type !== 'inhouse';
    }

    /** The staffing question on the details form (approved wording). */
    public static function contractQuestion(array $role): string
    {
        $client = trim((string) ($role['client_name'] ?? ''));
        $place = trim((string) ($role['location'] ?? ''));
        $months = (int) ($role['contract_months'] ?? 0);
        return 'This is a contract role on Eshcol\'s payroll, working at ' . ($client !== '' ? $client . '\'s site' : 'our client\'s site')
            . ($place !== '' ? " in $place" : '') . ($months > 0 ? " for $months months" : '') . '. Are you open to it?';
    }

    public const CONTRACT_ANSWERS = ['yes' => 'Yes', 'no' => 'No', 'more_info' => 'I\'d like to know more'];

    /** The job post's first line: "We are hiring for one of our clients" and the like. */
    public static function postHeadline(array $role, bool $nameClient): string
    {
        return match (self::of($role)) {
            'inhouse' => self::NAME . ' is hiring for its own team',
            'staffing' => self::NAME . ' is hiring for ' . (trim((string) ($role['client'] ?? '')) !== '' ? $role['client'] . '\'s site' : 'a client site')
                . (trim((string) ($role['location'] ?? '')) !== '' ? ' in ' . $role['location'] : '') . ' (on Eshcol\'s payroll)',
            default => $nameClient ? $role['client'] . ' is hiring' : 'We are hiring for one of our clients',
        };
    }

    /** The general line for the whole database (CV requests, keep-my-details). */
    public static function generalPurpose(): string
    {
        return 'suitable roles with our clients or at Eshcol';
    }
}
