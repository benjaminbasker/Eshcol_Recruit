<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Analyser improvement 5a (approved 15 Nov 2026): how Claude's bands compare with your decisions on a position, and
 * with the client's answers on the shortlist. Free: it only reads what is already recorded.
 *  - your decision is where the application is now: taken forward (Shortlisted or later, or Joined), kept for later
 *    (Future requirement), turned down (Not suitable, Rejected: insufficient information), withdrew (their choice,
 *    left out of the agreement), or not decided yet (Applied, Screening);
 *  - agreement: Strong taken forward plus Weak turned down, out of all Strong and Weak you decided on. "Possible" is
 *    the grey zone and is shown, not judged;
 *  - the disagreements are listed with your reason, which is what the next step (5b, suggest rule changes) reads.
 */
final class Agreement
{
    public const BANDS = ['Strong', 'Possible', 'Weak'];
    public const DECISIONS = ['forward' => 'Taken forward', 'later' => 'Kept for later', 'down' => 'Turned down', 'withdrew' => 'Withdrew', 'open' => 'Not decided yet'];
    /** Below this many decided Strong + Weak, the agreement figure is shown as "too early". */
    public const ENOUGH = 20;

    public function __construct(private readonly App $app)
    {
    }

    /** Your decision, from the stage an application is at now. Pure: tested directly. */
    public static function decision(string $kind, int $sort, string $name, int $shortlistedSort = 30): string
    {
        return match (true) {
            $kind === 'success' => 'forward',
            $kind === 'hold' => 'later',
            // "No response from candidate" (16 Nov 2026) is their doing, like Withdrew: not your turning them down.
            $kind === 'fail' => stripos($name, 'withdr') === 0 || stripos($name, 'no response') === 0 ? 'withdrew' : 'down',
            $sort >= $shortlistedSort => 'forward',
            default => 'open',
        };
    }

    /**
     * The extra analysers (6 examples, 5b suggest rule changes, 7 fair check, and the "why take a Weak forward" box) are
     * hidden (Benjamin, 16 Nov 2026): Claude's first analysis gives the details and the score, and the recruiter judges
     * and shortlists. The code stays, switched off; setting `analyser_extras` = 1 shows it all again.
     */
    public static function extrasOn(App $app): bool
    {
        return $app->setting('analyser_extras', '0') === '1';
    }

    /** Is this stage "taken forward" (Shortlisted or later, or Joined)? */
    public static function isForward(App $app, int $stageId): bool
    {
        return Shortlists::isShortlisted($app, $stageId);
    }

    /** Did Claude call this application Weak? (Taking a Weak forward keeps your reason, so 5b can learn from it.) */
    public static function claudeWeak(App $app, int $applicationId): bool
    {
        return $app->db->col("SELECT band FROM fit_assessments WHERE application_id = ? AND status = 'done'", [$applicationId]) === 'Weak';
    }

    /**
     * The reason kept on an application after a move (16 Nov 2026). A closing stage (Not suitable, Future requirement)
     * keeps the note, as before. Taking forward someone Claude called Weak now keeps it too, and moving them on
     * between forward stages (Shortlisted to Submitted) keeps the reason already given. Anything else clears it.
     * Pure apart from what it is told. $defaultNote: an automatic note ("Details reviewed and approved") is no reason.
     */
    public static function keptReason(bool $closing, bool $weakForward, bool $wasForward, ?string $note, ?string $existing, string $defaultNote = 'Details reviewed and approved'): ?string
    {
        $note = $note !== null && trim($note) !== '' && $note !== $defaultNote ? trim($note) : null;
        if ($closing) {
            return $note;
        }
        if ($weakForward) {
            return $note ?? ($wasForward && $existing !== null && trim($existing) !== '' ? $existing : null);
        }
        return null;
    }

    /** The report for one position. */
    public function forRole(int $roleId): array
    {
        $db = $this->app->db;
        $shortSort = (int) ($db->col("SELECT sort FROM stages WHERE kind = 'active' AND name LIKE 'Shortlist%' ORDER BY sort LIMIT 1") ?: 30);
        $rows = $db->all("SELECT a.id, a.candidate_id, a.stage_reason, c.full_name, f.band, f.score, s.kind, s.sort, s.name AS stage,
                (SELECT i.decision FROM shortlist_items i WHERE i.application_id = a.id AND i.decision IS NOT NULL ORDER BY i.id DESC LIMIT 1) AS client
            FROM fit_assessments f JOIN applications a ON a.id = f.application_id JOIN candidates c ON c.id = a.candidate_id JOIN stages s ON s.id = a.stage_id
            WHERE f.role_id = ? AND f.status = 'done' AND f.band IS NOT NULL", [$roleId]);
        $grid = [];
        foreach (self::BANDS as $b) {
            $grid[$b] = array_fill_keys(array_keys(self::DECISIONS), 0) + ['client_shortlist' => 0, 'client_hold' => 0, 'client_reject' => 0];
        }
        $differ = [];
        foreach ($rows as $r) {
            $band = in_array($r['band'], self::BANDS, true) ? $r['band'] : 'Weak';
            $d = self::decision((string) $r['kind'], (int) $r['sort'], (string) $r['stage'], $shortSort);
            $grid[$band][$d]++;
            if ($r['client']) {
                $grid[$band]['client_' . $r['client']]++;
            }
            if (($band === 'Strong' && $d === 'down') || ($band === 'Weak' && $d === 'forward')) {
                $differ[] = ['application_id' => (int) $r['id'], 'candidate_id' => (int) $r['candidate_id'], 'name' => (string) $r['full_name'],
                    'band' => $band, 'score' => (int) $r['score'], 'decision' => $d, 'stage' => (string) $r['stage'], 'reason' => (string) ($r['stage_reason'] ?? '')];
            }
        }
        $agree = $grid['Strong']['forward'] + $grid['Weak']['down'];
        $decided = $grid['Strong']['forward'] + $grid['Strong']['down'] + $grid['Weak']['forward'] + $grid['Weak']['down'];
        usort($differ, fn ($a, $b) => [$a['band'], -$a['score']] <=> [$b['band'], -$b['score']]);
        return ['grid' => $grid, 'assessed' => count($rows), 'agree' => $agree, 'decided' => $decided,
            'pct' => $decided ? (int) round($agree / $decided * 100) : null, 'enough' => $decided >= self::ENOUGH, 'differ' => $differ];
    }
}
