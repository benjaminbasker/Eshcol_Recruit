<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

/**
 * How far to trust a candidate's experience figure.
 *
 * Order of trust: confirmed by you > the candidate's own answer > the total the CV states
 * (grown from when the CV was written) > the CV's job dates added up > unknown.
 * The stated total and the job dates are compared; more than a year apart is "Check".
 * A CV whose newest date is over a year old, with no current job, is an "Old CV".
 */
final class ExperienceCheck
{
    /** Allowed difference: a year, or 15% of the total for long careers. */
    private const TOLERANCE = 12;
    private const TOLERANCE_SHARE = 0.15;

    /**
     * @return array{label:string, tone:string, note:string, check:bool, old:bool, old_since:?string}
     */
    public static function assess(array $c): array
    {
        $src = (json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [])['exp_months'] ?? '';
        $stated = $c['exp_stated_months'] ?? null;
        $dates = $c['exp_dates_months'] ?? null;
        $cvDated = $c['cv_dated'] ?? null;
        $current = (bool) ($c['cv_has_current_job'] ?? false);
        $since = fn (?string $d) => $d ? max(0, (int) round((time() - strtotime($d)) / (86400 * 30.44))) : 0;
        // Both figures brought to today, on the same footing (assuming they stayed in work).
        $statedNow = $stated !== null ? (int) $stated + $since($cvDated) : null;
        $datesNow = $dates !== null ? (int) $dates + ($current ? 0 : $since($cvDated)) : null;
        $tol = $statedNow !== null && $datesNow !== null ? max(self::TOLERANCE, (int) round(self::TOLERANCE_SHARE * max($statedNow, $datesNow))) : self::TOLERANCE;
        $old = $cvDated !== null && !$current && $since($cvDated) > 12;
        $oldSince = $old ? date('M Y', strtotime($cvDated)) : null;
        $inferred = null;
        // A current job and no "Date:" line: if the job dates run past the stated total, the CV is
        // most likely that much older ("5 years" written two years ago, job still "Present").
        // Not when the current job started after that date: the CV must be newer, and the total is
        // simply understated ("9+ years" for about 10).
        $notBefore = $c['cv_not_before'] ?? null;
        $age = $statedNow !== null && $datesNow !== null ? $datesNow - $statedNow : 0;
        if ($cvDated === null && $current && $statedNow !== null && $datesNow !== null && $datesNow > $statedNow && $age <= 48
            && !($notBefore && strtotime("-$age months") < strtotime($notBefore))) {
            $inferred = date('M Y', strtotime("-$age months"));
            $statedNow = $datesNow;
            if ($age > 12) {
                $old = true;
                $oldSince = $inferred;
            }
        }
        $mismatch = $statedNow !== null && $datesNow !== null && abs($statedNow - $datesNow) > $tol;
        $mismatchNote = $mismatch ? 'The CV says ' . experience($stated) . ', but its job dates add up to ' . experience($dates) . '.' : '';

        [$label, $tone, $note] = match (true) {
            $src === 'you' => ['Confirmed', 'yes', 'Confirmed by you.'],
            $src === 'candidate' => ['From candidate', 'yes', 'Given by the candidate in the screening questions.' . ($mismatch ? ' ' . $mismatchNote : '')],
            // Read by Claude: checked like any other reading (its stated total against its job dates).
            $src === 'ai' && $stated === null && $dates === null => ['Read by Claude', '', 'Read from the CV by Claude.'],
            $mismatch => ['Check', 'bad', $mismatchNote . ' Ask them to walk you through it.'],
            $stated !== null && $dates !== null => ['Consistent', 'yes', 'The stated total and the job dates agree' . ($inferred ? ', if the CV was written about ' . $inferred : '') . '.'],
            $stated !== null => ['Stated', '', 'As stated in the CV' . ($cvDated ? ' (written about ' . date('M Y', strtotime($cvDated)) . '), grown to today' : '') . '. No job dates to check it against.'],
            $dates !== null => ['Estimated', 'sand', 'No total stated: added up from the job dates.'],
            default => ['Unknown', '', 'The CV gives no experience or job dates.'],
        };
        if ($old) {
            $note .= $inferred ? " The CV looks about " . experience($datesNow - (int) $stated) . " old (written around $oldSince)." : " The CV's newest date is $oldSince, so it may be out of date.";
        }
        return ['label' => $label, 'tone' => $tone, 'note' => $note, 'check' => $mismatch && !in_array($src, ['you'], true), 'old' => $old, 'old_since' => $oldSince];
    }
}
