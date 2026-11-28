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
    // ---- Reading back from the HRMS (28 Nov 2026, update 081) ----

    /** Recruit's rate => how to make it from the HRMS's rates (the HRMS owns them, D7). */
    public const RATE_MAP = [
        'pf_employer_pct' => ['pf_rate'], 'pf_admin_pct' => ['pf_admin_rate', 'edli_rate'], 'pf_ceiling' => ['pf_ceiling'],
        'esi_employer_pct' => ['esi_employer_rate'], 'esi_ceiling' => ['esi_ceiling'], 'bonus_pct' => ['bonus_min_rate'],
        'bonus_eligibility' => ['bonus_eligibility'], 'bonus_calc_ceiling' => ['bonus_calc_ceiling'], 'lwf_employer_year' => ['lwf_employer_year'],
        'gratuity_pct' => ['gratuity_days', 'gratuity_divisor'],
    ];

    /** @var null|callable(string $url, array $headers): array{0: int, 1: string} for tests */
    public static $transport = null;

    public function hrmsUrl(): string
    {
        return rtrim((string) $this->app->setting('hrms_url', 'https://hrms.eshcolglobal.com'), '/');
    }

    /** A signed GET to the HRMS. @return array{0: ?array, 1: ?string} the JSON, or an error */
    public function pull(string $pathWithQuery): array
    {
        $key = Secrets::get($this->app, 'hrms_link_key');
        if (!$key) {
            return [null, 'The link key is not set.'];
        }
        $t = time();
        $headers = ['Accept: application/json', 'User-Agent: EshcolRecruit/1', 'X-Eshcol-Time: ' . $t, 'X-Eshcol-Signature: ' . self::sign($key, 'GET', $pathWithQuery, $t, '')];
        if (self::$transport) {
            [$code, $resp] = (self::$transport)($this->hrmsUrl() . $pathWithQuery, $headers);
        } else {
            $ch = curl_init($this->hrmsUrl() . $pathWithQuery);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            $resp = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 0) {
                return [null, 'The HRMS could not be reached: ' . mb_substr(curl_error($ch), 0, 150)];
            }
        }
        $json = json_decode((string) $resp, true);
        if ($code !== 200 || !is_array($json)) {
            return [null, is_array($json) && isset($json['error']) ? 'The HRMS said: ' . $json['error'] : "The HRMS answered $code."];
        }
        return [$json, null];
    }

    /** Fetch the rates and the leavers. @return string what happened, for the job log */
    public function fetchFeeds(): string
    {
        if (!$this->configured()) {
            return 'not linked';
        }
        $db = $this->app->db;
        $out = [];
        [$r, $err] = $this->pull('/hooks/recruit/rates');
        if ($err) {
            $out[] = "rates: $err";
        } else {
            $db->tx(function () use ($db, $r): void {
                $db->run('DELETE FROM hrms_rates');
                foreach ((array) ($r['rates'] ?? []) as $k => $v) {
                    if (!preg_match('/^[a-z0-9_]{2,40}$/', (string) $k) || !is_numeric($v['value'] ?? null)) {
                        continue;
                    }
                    $db->insert('hrms_rates', ['k' => $k, 'label' => mb_substr((string) ($v['label'] ?? $k), 0, 200), 'value' => (float) $v['value'], 'unit' => mb_substr((string) ($v['unit'] ?? ''), 0, 20),
                        'effective_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($v['effective_from'] ?? '')) ? $v['effective_from'] : null, 'confirmed' => !empty($v['confirmed']) ? 1 : 0, 'fetched_at' => date('Y-m-d H:i:s')]);
                }
            });
            $out[] = 'rates: ' . count((array) ($r['rates'] ?? []));
        }
        [$x, $err] = $this->pull('/hooks/recruit/exits?since=' . date('Y-m-d', strtotime('-120 days')));
        if ($err) {
            $out[] = "exits: $err";
        } else {
            $n = 0;
            foreach ((array) ($x['exits'] ?? []) as $e) {
                $no = mb_substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($e['emp_no'] ?? '')), 0, 20);
                $last = (string) ($e['last_day'] ?? '');
                if ($no === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
                    continue;
                }
                $cand = preg_match('/^EG-(\d+)$/', (string) ($e['candidate_ref'] ?? ''), $m) ? (int) $m[1] : null;
                if ($cand && !$db->col('SELECT 1 FROM candidates WHERE id = ?', [$cand])) {
                    $cand = null;
                }
                $client = isset($e['recruit_client_id']) && $db->col('SELECT 1 FROM clients WHERE id = ?', [(int) $e['recruit_client_id']]) ? (int) $e['recruit_client_id'] : null;
                $db->run('INSERT INTO hrms_exits (candidate_id, application_id, emp_no, client_id, designation, joined_on, last_day, reason, has_left, rehire_ok) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE candidate_id = VALUES(candidate_id), application_id = VALUES(application_id), client_id = VALUES(client_id), designation = VALUES(designation),
                    last_day = VALUES(last_day), reason = VALUES(reason), has_left = VALUES(has_left), rehire_ok = VALUES(rehire_ok)',
                    [$cand, isset($e['application_id']) ? (int) $e['application_id'] : null, $no, $client, mb_substr((string) ($e['designation'] ?? ''), 0, 160) ?: null,
                        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['joined_on'] ?? '')) ? $e['joined_on'] : null, $last, mb_substr((string) ($e['reason'] ?? ''), 0, 80) ?: null,
                        !empty($e['left']) ? 1 : 0, array_key_exists('rehire_ok', $e) && $e['rehire_ok'] !== null ? (int) (bool) $e['rehire_ok'] : null]);
                $n++;
            }
            $out[] = "exits: $n";
        }
        $db->run("INSERT INTO settings (k, v) VALUES ('hrms_feeds_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [date('Y-m-d H:i:s') . ' ' . implode('; ', $out)]);
        return implode('; ', $out);
    }

    /** The HRMS's figure for one of Recruit's rates, or null when the HRMS has none. */
    public function hrmsValue(string $k): ?array
    {
        $parts = self::RATE_MAP[$k] ?? null;
        if (!$parts) {
            return null;
        }
        $rows = array_column($this->app->db->all('SELECT * FROM hrms_rates WHERE k IN (' . implode(',', array_fill(0, count($parts), '?')) . ')', $parts), null, 'k');
        if (count($rows) !== count($parts)) {
            return null;
        }
        $v = $k === 'gratuity_pct' ? (float) $rows['gratuity_days']['value'] / max(1, (float) $rows['gratuity_divisor']['value']) / 12 * 100
            : array_sum(array_map(fn ($p) => (float) $rows[$p]['value'], $parts));
        return ['value' => round($v, 4), 'confirmed' => !in_array(0, array_map(fn ($p) => (int) $rows[$p]['confirmed'], $parts), true)];
    }
}
