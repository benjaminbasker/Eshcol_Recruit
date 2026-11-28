<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;

/**
 * The read-only link for Claude (28 Nov 2026): a small MCP server on Benjamin's own computer (mcp/server.php) asks Recruit
 * questions over HTTPS, each request signed like the HRMS link (HMAC-SHA256 of method, path, time, body hash; five-minute
 * window). Everything here only READS. Nothing is written, moved, deleted or emailed, and there are no email addresses,
 * phone numbers or street addresses in any answer: a candidate is a reference (EG-nnnn), a name, a town and the work history.
 * People who asked to be removed, or are marked do-not-contact, never appear.
 */
final class McpLink
{
    public const MAX_SKEW = 300;
    public const KEY = 'mcp_link_key';

    public function __construct(private readonly App $app)
    {
    }

    public static function sign(string $key, string $method, string $path, int $time, string $body): string
    {
        return HrmsLink::sign($key, $method, $path, $time, $body);
    }

    public function configured(): bool
    {
        return Secrets::has($this->app, self::KEY);
    }

    public function newKey(): string
    {
        $key = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        Secrets::put($this->app, self::KEY, $key);
        return $key;
    }

    public function verify(string $method, string $path, ?string $time, ?string $signature, string $body, ?int $now = null): bool
    {
        $key = Secrets::get($this->app, self::KEY);
        if (!$key || $time === null || $signature === null || !ctype_digit($time) || abs(($now ?? time()) - (int) $time) > self::MAX_SKEW) {
            return false;
        }
        return hash_equals(self::sign($key, $method, $path, (int) $time, $body), $signature);
    }

    private function hidden(): string
    {
        return Contact::sql('c');
    }

    /** Open positions with how many people are at each stage. */
    public function positions(): array
    {
        $db = $this->app->db;
        $roles = $db->all("SELECT r.id, r.code, r.title, r.location, r.status, r.positions, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id
            WHERE r.is_pool = 0 AND r.status IN ('open','on_hold') ORDER BY r.id DESC");
        foreach ($roles as &$r) {
            $r['id'] = (int) $r['id'];
            $r['people_by_stage'] = $this->stageCounts((int) $r['id']);
        }
        return $roles;
    }

    private function stageCounts(int $roleId): array
    {
        $rows = $this->app->db->all('SELECT s.name, COUNT(*) n FROM applications a JOIN stages s ON s.id = a.stage_id JOIN candidates c ON c.id = a.candidate_id
            WHERE a.role_id = ? AND ' . $this->hidden() . ' GROUP BY s.id, s.name ORDER BY s.sort', [$roleId]);
        return array_column($rows, 'n', 'name');
    }

    /** One position: the job description and where everyone stands, with the kind-of-work counts. */
    public function position(int $id): ?array
    {
        $r = $this->app->db->one('SELECT r.id, r.code, r.title, r.location, r.status, r.positions, r.jd_text, r.exp_min, r.exp_max, r.notice_max_days, r.eng_branches, cl.name AS client
            FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ? AND r.is_pool = 0', [$id]);
        if (!$r) {
            return null;
        }
        $r['id'] = (int) $r['id'];
        $r['jd_text'] = mb_substr((string) $r['jd_text'], 0, 6000);
        $r['people_by_stage'] = $this->stageCounts($id);
        $r['kind_of_work'] = SuitabilityCheck::mode($this->app) === 'off' ? null
            : array_column($this->app->db->all("SELECT sc.verdict, COUNT(*) n FROM suitability_checks sc JOIN candidates c ON c.id = sc.candidate_id
                WHERE sc.role_id = ? AND sc.status = 'done' AND " . $this->hidden() . ' GROUP BY sc.verdict', [$id]), 'n', 'verdict');
        return $r;
    }

    /** People on a position, optionally one stage or one kind-of-work answer. No contact details. */
    public function candidates(int $roleId, ?string $stage = null, ?string $verdict = null, int $limit = 25, int $offset = 0): array
    {
        $sql = "SELECT a.id AS application_id, c.id, c.full_name, c.location, c.current_title, c.current_company, c.exp_months, c.qualifications, c.notice_days, c.ctc_expected_lpa,
                s.name AS stage, a.fit_note, a.score, sc.verdict AS kind_of_work, sc.confidence AS kind_confidence, sc.reason AS kind_reason
            FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN stages s ON s.id = a.stage_id
            LEFT JOIN suitability_checks sc ON sc.application_id = a.id AND sc.status = 'done'
            WHERE a.role_id = ? AND " . $this->hidden();
        $args = [$roleId];
        if ($stage !== null && $stage !== '') {
            $sql .= ' AND s.name = ?';
            $args[] = $stage;
        }
        if ($verdict !== null && isset(SuitabilityCheck::VERDICTS[$verdict])) {
            $sql .= ' AND sc.verdict = ?';
            $args[] = $verdict;
        }
        $sql .= ' ORDER BY a.applied_at DESC LIMIT ' . max(1, min(50, $limit)) . ' OFFSET ' . max(0, $offset);
        return array_map(fn ($r) => $this->person($r), $this->app->db->all($sql, $args));
    }

    private function person(array $r): array
    {
        return [
            'ref' => 'EG-' . (int) $r['id'], 'candidate_id' => (int) $r['id'], 'name' => $r['full_name'], 'town' => $r['location'],
            'current_title' => $r['current_title'], 'current_company' => $r['current_company'],
            'experience_years' => $r['exp_months'] !== null ? round((int) $r['exp_months'] / 12, 1) : null,
            'qualifications' => $r['qualifications'], 'notice_days' => $r['notice_days'], 'expected_ctc_lpa' => $r['ctc_expected_lpa'],
            'stage' => $r['stage'] ?? null, 'rules_note' => $r['fit_note'] ?? null,
            'kind_of_work' => $r['kind_of_work'] ?? null, 'kind_of_work_confidence' => $r['kind_confidence'] ?? null, 'kind_of_work_reason' => $r['kind_reason'] ?? null,
        ];
    }

    /** One person: the facts, their applications, and the CV with name and contact details taken out. */
    public function candidate(int $id): ?array
    {
        $db = $this->app->db;
        $c = $db->one('SELECT c.* FROM candidates c WHERE c.id = ? AND ' . $this->hidden(), [$id]);
        if (!$c) {
            return null;
        }
        $out = $this->person($c + ['stage' => null]);
        unset($out['stage'], $out['rules_note'], $out['kind_of_work'], $out['kind_of_work_confidence'], $out['kind_of_work_reason']);
        $out['applications'] = $db->all("SELECT r.id AS role_id, r.code, r.title, s.name AS stage, a.stage_reason, a.fit_note, sc.verdict AS kind_of_work, sc.reason AS kind_of_work_reason
            FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id LEFT JOIN suitability_checks sc ON sc.application_id = a.id AND sc.status = 'done'
            WHERE a.candidate_id = ? ORDER BY a.applied_at DESC", [$id]);
        $out['cv_without_name_or_contact'] = mb_substr((new FitAssessor($this->app))->blindCv($id), 0, 8000);
        return $out;
    }

    /** Find people by words in their title, company, qualifications or CV facts. Names are searched too. */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . strtr($q, ['%' => '', '_' => '']) . '%';
        $rows = $this->app->db->all('SELECT c.* FROM candidates c WHERE ' . $this->hidden() . '
            AND (c.full_name LIKE ? OR c.current_title LIKE ? OR c.current_company LIKE ? OR c.qualifications LIKE ? OR c.location LIKE ? OR c.applied_for LIKE ?)
            ORDER BY c.updated_at DESC LIMIT ' . max(1, min(50, $limit)), array_fill(0, 6, $like));
        return array_map(fn ($r) => $this->person($r), $rows);
    }
}
