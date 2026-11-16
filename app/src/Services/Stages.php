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
