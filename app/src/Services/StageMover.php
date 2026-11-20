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
    /**
     * The same decision on the candidate's other positions with the same client (Benjamin, 17 Nov 2026: "when I review
     * a CV I check for both jobs"). Future requirement on one → Future requirement on the others, with the same note.
     * Under review, Shortlisted or later on one → Not suitable on the others ("taken forward for X instead").
     * Only positions still in play there (an active stage before Shortlisted) change; nobody is emailed.
     * Returns the messages to show.
     */
    public function mirror(array $a, int $to, ?string $note, int $userId, bool $byOwner): array
    {
        $db = $this->app->db;
        $toStage = $db->one('SELECT id, name, kind FROM stages WHERE id = ?', [$to]);
        if (!$toStage) {
            return [];
        }
        $role = $db->one('SELECT id, title, client_id FROM roles WHERE id = ?', [$a['role_id']]);
        if (!$role) {
            return [];
        }
        $future = Stages::is($this->app, $to, Stages::FUTURE);
        $forward = !$future && $toStage['kind'] === 'active'
            && (Shortlists::isShortlisted($this->app, $to) || (Stages::id($this->app, Stages::REVIEW) !== null ? Stages::is($this->app, $to, Stages::REVIEW) : (bool) preg_match('/review|screen/i', (string) $toStage['name'])));
        if (!$future && !$forward) {
            return [];
        }
        $target = $future ? $to : (int) (Stages::id($this->app, Stages::NOT_SUITABLE) ?? 0);
        if (!$target) {
            return [];
        }
        $why = $future ? ('Same as for ' . $role['title'] . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : ''))
            : 'Taken forward for ' . $role['title'] . ' instead';
        $msgs = [];
        foreach ($db->all("SELECT a.*, r.title FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
                WHERE a.candidate_id = ? AND a.id <> ? AND r.client_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND s.kind = 'active'",
                [$a['candidate_id'], $a['id'], $role['client_id']]) as $other) {
            if (Shortlists::isShortlisted($this->app, (int) $other['stage_id'])) {
                continue; // already taken forward there: you decide that one yourself
            }
            $this->move($other, $target, mb_substr($why, 0, 255), $userId, $byOwner);
            $msgs[] = 'Also on ' . $other['title'] . ': ' . ($future ? 'Future requirement' : 'Not suitable') . ' (' . ($future ? 'same as here' : 'taken forward for ' . $role['title'] . ' instead') . ').';
        }
        if ($msgs) {
            Audit::log('stage_mirrored', 'candidate', (int) $a['candidate_id'], ['from_role' => (int) $role['id'], 'to' => $target, 'n' => count($msgs)]);
        }
        return $msgs;
    }

    /**
     * A hard rejection ("Rejected: insufficient information") is a verdict on the person, not the role
     * (Benjamin, 18 Nov 2026: profile won't help us at all, insufficient details, or an attitude problem).
     * It red-lists them (kept off every future position, any client - see Contact::canPutForward) and
     * closes every other OPEN application they have with any client, not just this one. An application
     * already Shortlisted or further is left for you to decide, since a client may already be in process.
     * Returns the messages to show.
     */
    public function redList(array $a, int $to, ?string $note, int $userId, bool $byOwner): array
    {
        if (!Stages::is($this->app, $to, Stages::REJECTED)) {
            return [];
        }
        $db = $this->app->db;
        $cand = $db->one('SELECT id, red_listed_at FROM candidates WHERE id = ?', [$a['candidate_id']]);
        if (!$cand) {
            return [];
        }
        if (!$cand['red_listed_at']) {
            (new RedList($this->app))->mark((int) $cand['id'], (string) $note, $userId);
        }
        $why = mb_substr('Red-listed' . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : ''), 0, 255);
        $msgs = [];
        foreach ($db->all("SELECT a.*, r.title FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
                WHERE a.candidate_id = ? AND a.id <> ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND s.kind = 'active'",
                [$a['candidate_id'], $a['id']]) as $other) {
            if (Shortlists::isShortlisted($this->app, (int) $other['stage_id'])) {
                $msgs[] = 'Also on ' . $other['title'] . ': already Shortlisted or further - decide that one yourself.';
                continue;
            }
            $this->move($other, $to, $why, $userId, $byOwner);
            $msgs[] = 'Also closed on ' . $other['title'] . ': red-listed.';
        }
        return $msgs;
    }

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
