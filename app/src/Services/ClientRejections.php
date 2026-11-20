<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Clients who turned a candidate down (Benjamin, 20 Nov 2026): recorded on the candidate, and their CV is never
 * sent to that client again, on any of the client's positions. Other clients are not affected.
 * Recorded when you move an application to "Rejected by client", or when the client marks them "Not suitable"
 * on a shortlist link (taking that answer back removes it). Moving the application out of the stage keeps the
 * record: the client's no stands until you remove it on the candidate's page.
 */
final class ClientRejections
{
    public function __construct(private App $app) {}

    /** False until update 074 is applied, so the app keeps working in between. */
    public static function ready(App $app): bool
    {
        static $ok = [];
        $k = spl_object_id($app);
        if (!isset($ok[$k])) {
            try {
                $app->db->col('SELECT 1 FROM client_rejections LIMIT 1');
                $ok[$k] = true;
            } catch (\PDOException) {
                $ok[$k] = false;
            }
        }
        return $ok[$k];
    }

    /** SQL: candidate (alias $c) has NOT been turned down by the client given as a SQL expression ($clientExpr). */
    public static function notRejectedSql(App $app, string $c, string $clientExpr): string
    {
        return self::ready($app)
            ? "NOT EXISTS (SELECT 1 FROM client_rejections cr WHERE cr.candidate_id = $c.id AND cr.client_id = $clientExpr)"
            : '1 = 1';
    }

    /** The client's rejection of this candidate, newest first, or null. */
    public function find(int $candidateId, int $clientId): ?array
    {
        if (!self::ready($this->app)) {
            return null;
        }
        return $this->app->db->one('SELECT cr.*, r.title FROM client_rejections cr LEFT JOIN roles r ON r.id = cr.role_id
            WHERE cr.candidate_id = ? AND cr.client_id = ? ORDER BY cr.created_at DESC, cr.id DESC LIMIT 1', [$candidateId, $clientId]) ?: null;
    }

    /** Every client that turned this candidate down, for their page. @return list<array> */
    public function forCandidate(int $candidateId): array
    {
        if (!self::ready($this->app)) {
            return [];
        }
        return $this->app->db->all('SELECT cr.*, cl.name AS client, r.title, r.code
            FROM client_rejections cr JOIN clients cl ON cl.id = cr.client_id LEFT JOIN roles r ON r.id = cr.role_id
            WHERE cr.candidate_id = ? ORDER BY cr.created_at DESC, cr.id DESC', [$candidateId]);
    }

    /** Records a rejection from the stage drop-down. Returns true when it is new for this application. */
    public function fromStage(array $a, ?string $note, int $userId): bool
    {
        if (!self::ready($this->app)) {
            return false;
        }
        $db = $this->app->db;
        $clientId = (int) $db->col('SELECT client_id FROM roles WHERE id = ?', [$a['role_id']]);
        if (!$clientId || $db->col("SELECT id FROM client_rejections WHERE application_id = ? AND source = 'stage'", [$a['id']])) {
            return false;
        }
        $db->insert('client_rejections', ['candidate_id' => (int) $a['candidate_id'], 'client_id' => $clientId, 'role_id' => (int) $a['role_id'],
            'application_id' => (int) $a['id'], 'source' => 'stage', 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
            'created_by' => $userId ?: null]);
        Audit::log('client_rejected', 'candidate', (int) $a['candidate_id'], ['client' => $clientId, 'role' => (int) $a['role_id']]);
        return true;
    }

    /** The client's own answer on a shortlist link: "Not suitable" records it, any other answer takes it back. */
    public function fromPortal(int $itemId, ?string $decision, ?string $comment): void
    {
        if (!self::ready($this->app)) {
            return;
        }
        $db = $this->app->db;
        if ($decision !== 'reject') {
            $db->run("DELETE FROM client_rejections WHERE shortlist_item_id = ? AND source = 'portal'", [$itemId]);
            return;
        }
        $row = $db->one('SELECT a.id AS application_id, a.candidate_id, a.role_id, r.client_id FROM shortlist_items si
            JOIN applications a ON a.id = si.application_id JOIN roles r ON r.id = a.role_id WHERE si.id = ?', [$itemId]);
        if (!$row) {
            return;
        }
        $note = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 300) : null;
        $db->run("INSERT INTO client_rejections (candidate_id, client_id, role_id, application_id, source, shortlist_item_id, note)
            VALUES (?, ?, ?, ?, 'portal', ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note)",
            [$row['candidate_id'], $row['client_id'], $row['role_id'], $row['application_id'], $itemId, $note]);
        Audit::log('client_rejected', 'candidate', (int) $row['candidate_id'], ['client' => (int) $row['client_id'], 'role' => (int) $row['role_id'], 'via' => 'shortlist']);
    }

    /** You lift a client's no (e.g. the client asks to see them again). Returns how many records were removed. */
    public function lift(int $candidateId, int $clientId, int $userId): int
    {
        if (!self::ready($this->app)) {
            return 0;
        }
        $n = $this->app->db->run('DELETE FROM client_rejections WHERE candidate_id = ? AND client_id = ?', [$candidateId, $clientId])->rowCount();
        if ($n) {
            Audit::log('client_rejection_lifted', 'candidate', $candidateId, ['client' => $clientId, 'by' => $userId]);
        }
        return $n;
    }
}
