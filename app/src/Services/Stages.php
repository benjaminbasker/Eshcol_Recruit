<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * The stages the app's rules depend on, found by their fixed code (update 059), never by the name shown, which can be
 * renamed freely in Settings (audit Q1, 16 Nov 2026). Before update 059 is applied, the default names are used.
 */
final class Stages
{
    public const SHORTLISTED = 'shortlisted';
    public const SUBMITTED = 'submitted';
    public const INTERVIEW = 'interview';
    public const OFFER = 'offer';
    public const JOINED = 'joined';
    public const NOT_SUITABLE = 'not_suitable';
    public const WITHDREW = 'withdrew';
    public const FUTURE = 'future';
    // Update 067 (audit Q1 follow-up, 17 Nov 2026).
    public const REVIEW = 'review';
    public const NO_RESPONSE = 'no_response';
    public const AWAITING = 'awaiting_reply';
    // Update 072 (the red list, 18 Nov 2026): a hard rejection, not tied to one role.
    public const REJECTED = 'rejected';
    // Update 074 (20 Nov 2026): the client turned them down; never sent to that client again.
    public const CLIENT_REJECTED = 'client_rejected';

    /** Codes every install must have: the app's rules (offer approval, joining, shortlists, reports) depend on them. */
    public const REQUIRED = [self::SHORTLISTED, self::SUBMITTED, self::INTERVIEW, self::OFFER, self::JOINED, self::NOT_SUITABLE, self::WITHDREW, self::FUTURE];

    /** Required codes no stage carries (e.g. a stage renamed before update 059): shown as a warning in Settings. */
    public static function missing(App $app): array
    {
        return array_values(array_diff(self::REQUIRED, array_keys(self::map($app))));
    }

    /** The stage's own decision, not the candidate's doing: Withdrew and No response are theirs. */
    public static function isTheirChoice(App $app, int $stageId): bool
    {
        return self::is($app, $stageId, self::WITHDREW) || self::is($app, $stageId, self::NO_RESPONSE);
    }

    private const DEFAULT_NAMES = [
        'shortlisted' => 'Shortlisted', 'submitted' => 'Submitted to client', 'interview' => 'Client interview', 'offer' => 'Offer made',
        'joined' => 'Joined', 'not_suitable' => 'Not suitable', 'withdrew' => 'Withdrew', 'future' => 'Future requirement',
    ];

    /** @var array<string, array<string, int>> app object id => code => stage id */
    private static array $ids = [];

    /** @return array<string, int> code => stage id */
    private static function map(App $app): array
    {
        $k = (string) spl_object_id($app);
        if (!isset(self::$ids[$k])) {
            try {
                $rows = $app->db->all('SELECT id, code FROM stages WHERE code IS NOT NULL');
                $map = array_map('intval', array_column($rows, 'id', 'code'));
            } catch (\PDOException) {
                $map = [];
                foreach (self::DEFAULT_NAMES as $code => $name) {
                    $id = $app->db->col('SELECT id FROM stages WHERE name = ? ORDER BY id LIMIT 1', [$name]);
                    if ($id) {
                        $map[$code] = (int) $id;
                    }
                }
            }
            self::$ids[$k] = $map;
        }
        return self::$ids[$k];
    }

    public static function id(App $app, string $code): ?int
    {
        return self::map($app)[$code] ?? null;
    }

    public static function is(App $app, int $stageId, string $code): bool
    {
        return $stageId > 0 && self::id($app, $code) === $stageId;
    }

    /** The code of this stage, or null when the app has no rule tied to it. */
    public static function code(App $app, int $stageId): ?string
    {
        $c = array_search($stageId, self::map($app), true);
        return $c === false ? null : $c;
    }

    /** SQL for "stage column $col is the stage with this code" (a number, safe to embed). */
    public static function sqlIs(App $app, string $col, string $code): string
    {
        return "$col = " . (self::id($app, $code) ?? 0);
    }

    public static function forget(): void
    {
        self::$ids = [];
    }
}
