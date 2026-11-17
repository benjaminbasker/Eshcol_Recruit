<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * The B.Com rule (Benjamin, 17 Nov 2026), for positions whose qualification is B.Com:
 *  - pass: B.Com (or Bachelor of Commerce, B.Com in Accounting & Finance / BAF), or M.Com, CA or CMA,
 *    because B.Com is the basic qualification for those (an Indian CA or CMA, not US CMA);
 *  - fail: a known graduation that isn't B.Com (BBM, BBA, BMS, BCA, B.Sc, B.A., B.E./B.Tech and the like)
 *    with no M.Com, CA or CMA;
 *  - confirm: no graduation shown (MBA or PGDM only, or nothing): any graduate can do those, so the
 *    recruiter confirms. Never rejected on this alone.
 * Pure: tested directly.
 */
final class QualificationGate
{
    /** Does this position require B.Com? From its qualification criteria ("BCom", "B.Com or M.Com"). */
    public static function requiresBcom(array $qualificationCriteria): bool
    {
        foreach ($qualificationCriteria as $q) {
            if (preg_match('/\bb\.?\s?com\b|bachelor of commerce/i', (string) $q)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: 'pass'|'fail'|'confirm', 1: string} the verdict and a short reason */
    public static function verdict(?string $qualifications): array
    {
        $q = mb_strtolower(trim((string) $qualifications));
        if ($q === '') {
            return ['confirm', 'qualification not known: confirm B.Com'];
        }
        // B.Com, or BAF (Bachelor of Accounting / Accounts and Finance: a B.Com in Accounting & Finance).
        if (preg_match('/\bb\.?\s?com\b|bachelor\'?s?(?: degree)? (?:of|in) commerce|\bbaf\b|accounting (?:and|&) finance\)|bachelor\'?s?(?: degree)? (?:of|in) (?:accounting|accounts|accountancy) (?:and|&) finance/u', $q)) {
            return ['pass', 'B.Com'];
        }
        if (preg_match('/\bm\.?\s?com\b|master\'?s? (?:of|in) commerce/u', $q)) {
            return ['pass', 'M.Com (B.Com assumed)'];
        }
        // CA in any form except the entry-level Foundation; CMA / ICWA except US CMA.
        $noUs = preg_replace('/\bus\s*cma\b|\bcma\s*\(?us\)?/u', ' ', $q);
        if (preg_match('/\bca\b(?!\s*foundation)|chartered accountant|\bipcc\b|\baca\b|\bfca\b/u', $q) || preg_match('/\bcma\b|\bicwa\b|cost (?:and|&) (?:works|management) accountan|cost accountant/u', $noUs)) {
            return ['pass', 'CA/CMA (B.Com assumed)'];
        }
        // A graduation that isn't B.Com; dots ignored ("B.C.A." = "BCA", "B.E." = "BE").
        $d = str_replace('.', '', $q);
        // "Bachelor of …" / "Bachelor's in …" names the subject; a bare "Bachelor's" or "Bachelor's degree" doesn't, so you confirm.
        if (preg_match('/\b(?:bbm|bba|bms|bca|bbi|bfm|b\s?sc|ba|be|b\s?tech|b\s?pharm|b\s?arch|llb)\b|\bbachelor\'?s?(?: degree)? (?:of|in) /u', $d)) {
            return ['fail', 'not a B.Com (' . self::shortQual($qualifications) . ')'];
        }
        return ['confirm', 'no graduation shown (' . self::shortQual($qualifications) . '): confirm B.Com'];
    }

    private static function shortQual(?string $q): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string) $q)), 0, 60, '…');
    }
}
