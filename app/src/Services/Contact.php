<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * Who we may contact, and who we may put forward (audit F17, 16 Nov 2026): one place for the rules that were written
 * out by hand in about 25 places.
 *  - silenced: marked do-not-contact, or asked us not to keep their details. Nothing goes to them; nothing is read
 *    or assessed for them.
 *  - put forward (shortlists, "add to position", routing, matching, details requests): not silenced, and not marked
 *    unreliable. Before this, four places that put people forward forgot the "asked us not to keep their details" half.
 */
final class Contact
{
    public static function silenced(array $c): bool
    {
        return !empty($c['do_not_contact']) || !empty($c['removal_requested_at']);
    }

    public static function canPutForward(array $c): bool
    {
        return !self::silenced($c) && empty($c['unreliable_at']) && empty($c['red_listed_at']);
    }

    /** SQL: the candidate (table alias $a) may be contacted. */
    public static function sql(string $a = 'c'): string
    {
        return "$a.do_not_contact = 0 AND $a.removal_requested_at IS NULL";
    }

    /** SQL: the candidate (table alias $a) may be put forward. Red-listed (18 Nov 2026): a hard rejection
     * on one position, same as "unreliable", keeps them off every future one too, any client. */
    public static function putForwardSql(string $a = 'c'): string
    {
        return self::sql($a) . " AND $a.unreliable_at IS NULL AND $a.red_listed_at IS NULL";
    }
}
