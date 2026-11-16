<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * How long candidate data is kept, and how it is deleted.
 *
 * Rolling period: the clock restarts whenever the candidate is in contact (applies
 * again, sends a new CV, has details confirmed with them). Someone active stays;
 * someone silent for the whole period comes up for deletion.
 *
 * Nobody is deleted silently: candidates whose time is up go to a review list, and
 * anyone in an active stage on an open role is never due.
 */
final class Retention
{
    public function __construct(private readonly App $app)
    {
    }

    public function months(): int
    {
        return max(6, min(120, (int) $this->app->setting('retention_months', '60')));
    }

    /** Restart a candidate's clock after contact with them. */
    public function touch(int $candidateId): void
    {
        $this->app->db->run('UPDATE candidates SET last_activity_at = NOW(), purge_after = DATE_ADD(CURDATE(), INTERVAL ? MONTH) WHERE id = ?',
            [$this->months(), $candidateId]);
    }

    /** Recalculate every candidate's date, e.g. after the period is changed in Settings. */
    public function recalculateAll(): int
    {
        return $this->app->db->run('UPDATE candidates SET purge_after = DATE_ADD(DATE(COALESCE(last_activity_at, created_at)), INTERVAL ? MONTH)
                                    WHERE retention_note IS NULL', [$this->months()])->rowCount();
    }

    /** Candidates whose time is up and who are not currently in play on an open role. */
    public function due(int $limit = 500): array
    {
        return $this->app->db->all("
            SELECT c.id, c.full_name, c.purge_after, c.last_activity_at, c.created_at, c.do_not_contact
            FROM candidates c
            WHERE c.purge_after < CURDATE()
              AND NOT EXISTS (
                SELECT 1 FROM applications a JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = a.role_id
                WHERE a.candidate_id = c.id AND s.kind = 'active' AND r.status IN ('open','on_hold') AND r.is_pool = 0)
            ORDER BY c.purge_after LIMIT " . (int) $limit);
    }

    public function dueCount(): int
    {
        return count($this->due(5000));
    }

    /** Keeps someone for another full period, with a reason (e.g. they asked to stay on file). */
    public function extend(int $candidateId, string $reason): void
    {
        $this->app->db->run('UPDATE candidates SET purge_after = DATE_ADD(CURDATE(), INTERVAL ? MONTH), retention_note = ? WHERE id = ?',
            [$this->months(), mb_substr($reason, 0, 255), $candidateId]);
        Audit::log('retention_extended', 'candidate', $candidateId, ['reason' => $reason]);
    }

    /**
     * Permanently deletes a candidate: database rows, stored CV files, and their copies
     * in the off-site backup. With $suppress, keeps one-way hashes of their email and
     * phone so a future CV from them is flagged "asked to be removed".
     */
    public function delete(int $candidateId, string $reason, bool $suppress = false): void
    {
        $db = $this->app->db;
        $c = $db->one('SELECT id, email_norm, phone_norm, unreliable_at, unreliable_reason FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c) {
            return;
        }
        $files = $db->all('SELECT storage_path, sha256, ext, backed_up_at FROM cv_files WHERE candidate_id = ?', [$candidateId]);
        // Branded copies made for client shortlists hold the same personal data, so they go too.
        $branded = array_column($db->all('SELECT i.branded_path FROM shortlist_items i JOIN applications a ON a.id = i.application_id
                                          WHERE a.candidate_id = ? AND i.branded_path IS NOT NULL', [$candidateId]), 'branded_path');
        if ($suppress) {
            foreach (['email' => $c['email_norm'], 'phone' => $c['phone_norm']] as $kind => $v) {
                if ($v) {
                    $db->run('INSERT IGNORE INTO suppression (hash, kind, reason) VALUES (?, ?, ?)', [self::hash($v), $kind, mb_substr($reason, 0, 40)]);
                }
            }
        }
        if ($c['unreliable_at']) {
            foreach (['email' => $c['email_norm'], 'phone' => $c['phone_norm']] as $kind => $v) {
                if ($v) {
                    $db->run('INSERT IGNORE INTO suppression (hash, kind, reason) VALUES (?, ?, ?)', [self::hash($v), $kind, 'unreliable:' . ($c['unreliable_reason'] ?: 'other') . ':' . substr((string) $c['unreliable_at'], 0, 7)]);
                }
            }
        }
        $db->run('DELETE FROM notes WHERE entity_type = \'candidate\' AND entity_id = ?', [$candidateId]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$candidateId]);
        foreach ($branded as $p) {
            @unlink($this->app->storage($p));
        }
        $remote = Backup::remote($this->app);
        foreach ($files as $f) {
            @unlink($this->app->storage($f['storage_path']));
            if ($remote && $f['backed_up_at']) {
                try {
                    $remote->delete(Backup::cvKey($f['sha256'], $f['ext']));
                } catch (\Throwable) {
                    // Retried by the nightly job, which removes orphaned backup copies.
                }
            }
        }
        // Only the id and reason are logged: no name, email or file name survives deletion.
        Audit::log('candidate_deleted', 'candidate', $candidateId, ['reason' => $reason, 'suppressed' => $suppress]);
    }

    /** Was this email or phone on file for someone who asked to be removed? */
    public function isSuppressed(?string $email, ?string $phone): bool
    {
        $hashes = array_filter([$email ? self::hash(mb_strtolower($email)) : null, $phone ? self::hash($phone) : null]);
        if (!$hashes) {
            return false;
        }
        $in = implode(',', array_fill(0, count($hashes), '?'));
        return (bool) $this->app->db->col("SELECT 1 FROM suppression WHERE hash IN ($in) AND reason NOT LIKE 'unreliable:%' LIMIT 1", array_values($hashes));
    }

    /** Was this email or phone on file for someone marked unreliable whose record was since deleted? Returns e.g. "unreliable:no_show:2026-11". */
    public function pastUnreliable(?string $email, ?string $phone): ?string
    {
        $hashes = array_filter([$email ? self::hash(mb_strtolower($email)) : null, $phone ? self::hash($phone) : null]);
        if (!$hashes) {
            return null;
        }
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $r = $this->app->db->col("SELECT reason FROM suppression WHERE hash IN ($in) AND reason LIKE 'unreliable:%' LIMIT 1", array_values($hashes));
        return $r ? (string) $r : null;
    }

    private static function hash(string $v): string
    {
        return hash('sha256', 'eshcol-suppression|' . $v);
    }
}
