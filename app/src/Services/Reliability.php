<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Candidates marked "Unreliable": they did not turn up, stopped responding once we showed
 * interest, or backed out. The mark keeps them out of everything where we reach out
 * (database search, adding to roles, shortlists, screening, interview invitations).
 * If they apply again themselves, the CV is still saved, with the mark shown.
 * It survives deletion as one-way hashes of email and phone, so a later CV is marked again.
 */
final class Reliability
{
    public const REASONS = [
        'no_show' => 'Did not turn up for the interview',
        'no_response' => 'Stopped responding',
        'backed_out' => 'Backed out after the offer',
        'not_joined' => 'Did not join',
        'other' => 'Other',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function mark(int $candidateId, string $reason, ?string $note, ?int $userId): void
    {
        $reason = isset(self::REASONS[$reason]) ? $reason : 'other';
        $this->app->db->run('UPDATE candidates SET unreliable_at = NOW(), unreliable_reason = ?, unreliable_note = ?, unreliable_by = ? WHERE id = ?',
            [$reason, mb_substr(trim((string) $note), 0, 300) ?: null, $userId, $candidateId]);
        Audit::log('candidate_unreliable', 'candidate', $candidateId, ['reason' => $reason]);
    }

    public function clear(int $candidateId, ?int $userId): void
    {
        $this->app->db->run('UPDATE candidates SET unreliable_at = NULL, unreliable_reason = NULL, unreliable_note = NULL, unreliable_by = NULL WHERE id = ?', [$candidateId]);
        Audit::log('candidate_unreliable_cleared', 'candidate', $candidateId);
    }

    /** "Did not turn up for the interview: Round 1, Accounts Executive · 12 Nov 2026" */
    public static function describe(array $c): string
    {
        if (empty($c['unreliable_at'])) {
            return '';
        }
        $why = self::REASONS[$c['unreliable_reason'] ?? ''] ?? 'Marked unreliable';
        return $why . (!empty($c['unreliable_note']) ? ': ' . $c['unreliable_note'] : '') . ' · ' . date('j M Y', strtotime((string) $c['unreliable_at']));
    }
}
