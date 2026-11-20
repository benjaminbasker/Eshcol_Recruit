<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The red list (Benjamin, 18 Nov 2026): a hard rejection is a verdict on the person, not the role -
 * their profile won't help us at all, the details they gave are insufficient, or they showed an
 * attitude problem. Once red-listed, they are left out of everything where we reach out (database
 * search, adding to roles, shortlists, screening, interview invitations), same as "Unreliable".
 */
final class RedList
{
    public function __construct(private readonly App $app)
    {
    }

    public function mark(int $candidateId, string $reason, ?int $userId): void
    {
        $this->app->db->run('UPDATE candidates SET red_listed_at = NOW(), red_listed_reason = ?, red_listed_by = ? WHERE id = ? AND red_listed_at IS NULL',
            [mb_substr(trim($reason), 0, 300) ?: null, $userId, $candidateId]);
        Audit::log('candidate_red_listed', 'candidate', $candidateId, ['reason' => $reason]);
    }

    public function clear(int $candidateId, ?int $userId): void
    {
        $this->app->db->run('UPDATE candidates SET red_listed_at = NULL, red_listed_reason = NULL, red_listed_by = NULL WHERE id = ?', [$candidateId]);
        Audit::log('candidate_red_listed_cleared', 'candidate', $candidateId);
    }

    /** "Red-listed: too high salary expectation · 18 Nov 2026" */
    public static function describe(array $c): string
    {
        if (empty($c['red_listed_at'])) {
            return '';
        }
        return 'Red-listed' . (!empty($c['red_listed_reason']) ? ': ' . $c['red_listed_reason'] : '') . ' · ' . date('j M Y', strtotime((string) $c['red_listed_at']));
    }
}
