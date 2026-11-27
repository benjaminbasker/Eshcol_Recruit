<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * The link to Eshcol HRMS (HRMS framework 6.3, 25 Nov 2026). The HRMS signs every request with a key both apps hold:
 * HMAC-SHA256 over the method, the path, the time and a SHA-256 of the body, base64url without padding. Anything more
 * than five minutes old, or signed with another key, is refused. The HRMS's RecruitLink::sign() must stay identical
 * (both apps' tests check the same fixed vector). The link only hands over joining records, which carry no Aadhaar,
 * PAN or bank numbers, and records what the HRMS imported.
 */
final class HrmsLink
{
    public const MAX_SKEW = 300;

    public function __construct(private readonly App $app)
    {
    }

    public static function sign(string $key, string $method, string $path, int $time, string $body): string
    {
        $mac = hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $time . "\n" . hash('sha256', $body), $key, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public function configured(): bool
    {
        return Secrets::has($this->app, 'hrms_link_key');
    }

    /** Is this request from the HRMS? @param ?int $now for tests */
    public function verify(string $method, string $path, ?string $time, ?string $signature, string $body, ?int $now = null): bool
    {
        $key = Secrets::get($this->app, 'hrms_link_key');
        if (!$key || $time === null || $signature === null || !ctype_digit($time) || abs(($now ?? time()) - (int) $time) > self::MAX_SKEW) {
            return false;
        }
        return hash_equals(self::sign($key, $method, $path, (int) $time, $body), $signature);
    }

    /** Records for the HRMS: still to set up, and those withdrawn in the last 90 days (so it can clear them). */
    public function joinings(): array
    {
        $rows = $this->app->db->all("SELECT id, status, payload FROM joinings WHERE status IN ('ready','downloaded')
            OR (status = 'withdrawn' AND COALESCE(updated_at, created_at) >= NOW() - INTERVAL 90 DAY) ORDER BY id");
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'status' => $r['status'], 'payload' => json_decode((string) $r['payload'], true)], $rows);
    }

    /** The HRMS imported a record: "In the HRMS", with the employee number. Saying it twice is fine. @return ?string error */
    public function markImported(int $joiningId, string $employeeNo): ?string
    {
        $r = $this->app->db->one('SELECT * FROM joinings WHERE id = ?', [$joiningId]);
        if (!$r) {
            return 'No such joining record.';
        }
        if ($r['status'] === 'withdrawn') {
            return 'This record was withdrawn in Recruit.';
        }
        $no = mb_substr(preg_replace('/[^A-Za-z0-9-]/', '', $employeeNo), 0, 20);
        $this->app->db->run("UPDATE joinings SET status = 'imported', imported_at = COALESCE(imported_at, NOW()), hrms_employee_no = ? WHERE id = ?", [$no !== '' ? $no : null, $joiningId]);
        if ($r['status'] !== 'imported') {
            Audit::log('joining_in_hrms', 'candidate', (int) $r['candidate_id'], ['joining' => $joiningId, 'employee_no' => $no]);
        }
        return null;
    }
}
