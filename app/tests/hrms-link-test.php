<?php
/**
 * The link to Eshcol HRMS (update 079, 25 Nov 2026): the signature (the same fixed vector the HRMS's test checks, so
 * the two apps can never drift apart), refusing old, unsigned or wrongly signed requests, the joining records handed
 * over (still to set up, and recently withdrawn), and "In the HRMS" with the employee number, over real HTTP on PHP's
 * built-in server. Throwaway data, removed afterwards. LOCAL ONLY. Run: php tests/hrms-link-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\HrmsLink;
use Eshcol\Ats\Services\Migrator;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
(new Migrator($app))->run();
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$hadKey = Secrets::get($app, 'hrms_link_key');
$key = 'zz-' . bin2hex(random_bytes(24));
$payload = fn (string $name) => json_encode(['format' => 'eshcol.joining', 'version' => 1, 'person' => ['full_name' => $name], 'job' => ['type' => 'staffing', 'joining_date' => '2027-01-04']]);
$ids = [];
foreach ([['Zz Hrms Ready', 'ready'], ['Zz Hrms Downloaded', 'downloaded'], ['Zz Hrms Withdrawn', 'withdrawn'], ['Zz Hrms Imported', 'imported']] as [$n, $st]) {
    $ids[$st] = $db->insert('joinings', ['full_name' => $n, 'engagement' => 'staffing', 'joining_date' => '2027-01-04', 'status' => $st, 'payload' => $payload($n)]);
}

$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$pub = dirname(__DIR__) . '/public';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $pub, "$pub/index.php"], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$call = function (string $method, string $path, array $body = [], ?string $k = null, ?int $t = null, bool $sign = true) use ($port, &$key): array {
    $raw = $body ? json_encode($body) : '';
    $t ??= time();
    $h = "Content-Type: application/json\r\nX-Eshcol-Time: $t\r\n" . ($sign ? 'X-Eshcol-Signature: ' . HrmsLink::sign($k ?? $key, $method, $path, $t, $raw) . "\r\n" : '');
    $resp = @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20]]));
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $resp, true)];
};

try {
    echo "The signature\n";
    $check('the fixed vector the HRMS also checks', HrmsLink::sign('zz-test-key', 'GET', '/hooks/hrms/joinings', 1795600000, '') === 'uuUFOXvYWfxBdSCI35N8i-GAkSdneOqiFcUeBjYYBf8');

    echo "Refused\n";
    Secrets::forget($app, 'hrms_link_key');
    [$st] = $call('GET', '/hooks/hrms/joinings');
    $check('not linked yet: refused (503)', $st === 503);
    Secrets::put($app, 'hrms_link_key', $key);
    [$st] = $call('GET', '/hooks/hrms/joinings', [], null, null, false);
    $check('unsigned: refused (401)', $st === 401);
    [$st] = $call('GET', '/hooks/hrms/joinings', [], 'zz-the-wrong-key-' . str_repeat('x', 30));
    $check('signed with another key: refused (401)', $st === 401);
    [$st] = $call('GET', '/hooks/hrms/joinings', [], null, time() - 600);
    $check('ten minutes old: refused (401)', $st === 401);
    [$st] = $call('POST', '/hooks/hrms/joinings/' . $ids['ready'] . '/imported', ['employee_no' => 'ESH-0001'], null, null, false);
    $check('marking imported unsigned: refused, and nothing changed', $st === 401 && $db->col('SELECT status FROM joinings WHERE id = ?', [$ids['ready']]) === 'ready');

    echo "Handed over\n";
    [$st, $j] = $call('GET', '/hooks/hrms/joinings');
    $mine = array_values(array_filter($j['joinings'] ?? [], fn ($r) => in_array($r['id'], $ids, true)));
    $byId = array_column($mine, 'status', 'id');
    $check('ready, downloaded and recently withdrawn records are handed over', $st === 200 && isset($byId[$ids['ready']], $byId[$ids['downloaded']], $byId[$ids['withdrawn']]));
    $check('one already in the HRMS is not', !isset($byId[$ids['imported']]));
    $check('each with its full joining record', ($mine[0]['payload']['format'] ?? '') === 'eshcol.joining');

    echo "In the HRMS\n";
    [$st, $r] = $call('POST', '/hooks/hrms/joinings/' . $ids['ready'] . '/imported', ['employee_no' => 'ESH-0042', 'imported_at' => '2026-12-28 10:00:00']);
    $row = $db->one('SELECT * FROM joinings WHERE id = ?', [$ids['ready']]);
    $check('marked "In the HRMS" with the employee number', $st === 200 && ($r['ok'] ?? false) && $row['status'] === 'imported' && $row['hrms_employee_no'] === 'ESH-0042' && $row['imported_at']);
    [$st] = $call('POST', '/hooks/hrms/joinings/' . $ids['ready'] . '/imported', ['employee_no' => 'ESH-0042']);
    $check('saying it twice is fine', $st === 200);
    [$st] = $call('POST', '/hooks/hrms/joinings/' . $ids['withdrawn'] . '/imported', ['employee_no' => 'ESH-0043']);
    $check('a withdrawn record cannot be marked (409)', $st === 409 && $db->col('SELECT status FROM joinings WHERE id = ?', [$ids['withdrawn']]) === 'withdrawn');
    [$st] = $call('POST', '/hooks/hrms/joinings/99999999/imported', ['employee_no' => 'ESH-0044']);
    $check('an unknown record (409)', $st === 409);
} finally {
    proc_terminate($proc);
    proc_close($proc);
    $db->run('DELETE FROM joinings WHERE full_name LIKE ?', ['Zz Hrms%']);
    $hadKey !== null ? Secrets::put($app, 'hrms_link_key', $hadKey) : Secrets::forget($app, 'hrms_link_key');
}

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
