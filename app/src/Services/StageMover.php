<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Controllers\DecisionController;

/**
 * The one way an application changes stage (audit S3, 16 Nov 2026): the offer and joining approvals, the stage
 * history, the HRMS joining record and the placement all happen here, whichever button moved it.
 */
final class StageMover
{
    public function __construct(private App $app) {}

    /** Why this move is not allowed yet (offer or joining approval), or null. */
    public function blocked(int $appId, int $to): ?string
    {
        return DecisionController::offerBlocked($this->app, $appId, $to) ?? DecisionController::joinBlocked($this->app, $appId, $to);
    }

    /**
     * Moves it (callers check blocked() first). Returns messages worth showing the person who moved it.
     * @return list<string>
     */
    public function move(array $a, int $to, ?string $note, int $userId, bool $byOwner): array
    {
        $db = $this->app->db;
        $appId = (int) $a['id'];
        if ($to === (int) $a['stage_id']) {
            return [];
        }
        $closing = (bool) $db->col("SELECT kind IN ('fail','hold') FROM stages WHERE id = ?", [$to]);
        // Analyser 5b: a reason for taking forward someone Claude called Weak (only while the extra analysers are on).
        $weakForward = !$closing && Agreement::extrasOn($this->app) && Agreement::isForward($this->app, $to) && Agreement::claudeWeak($this->app, $appId);
        $kept = Agreement::keptReason($closing, $weakForward, Agreement::isForward($this->app, (int) $a['stage_id']), $note, $a['stage_reason'] ?? null);
        $db->update('applications', ['stage_id' => $to, 'stage_reason' => $kept,
            'stage_reason_at' => $kept === null ? null : ($kept === ($a['stage_reason'] ?? null) ? ($a['stage_reason_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'))], $appId);
        $db->insert('stage_history', ['application_id' => $appId, 'from_stage_id' => $a['stage_id'], 'to_stage_id' => $to, 'user_id' => $userId, 'note' => $note]);
        Audit::log('stage_changed', 'candidate', (int) $a['candidate_id'], ['role' => $a['role_id'], 'to' => $to]);
        $msgs = [];
        if (!(new Migrator($this->app))->pending()) {
            // "Joined" writes the joining record for the HRMS; leaving it withdraws it.
            if (Stages::is($this->app, $to, Stages::JOINED)) {
                if ((new Joinings($this->app))->record($appId, $userId)) {
                    $msgs[] = 'Joined. Their joining record is ready for the HRMS (under Joinings).';
                }
            } else {
                $db->run("UPDATE joinings SET status = 'withdrawn' WHERE application_id = ? AND status IN ('ready', 'downloaded')", [$appId]);
            }
            $pid = (new Placements($this->app))->afterStage($appId, $to);
            if ($pid && $byOwner && in_array((string) $db->col('SELECT status FROM placements WHERE id = ?', [$pid]), ['offer', 'joined'], true)) {
                $msgs[] = 'Add the offer and joining details in the Placement box under this position.';
            }
        }
        return $msgs;
    }
}
