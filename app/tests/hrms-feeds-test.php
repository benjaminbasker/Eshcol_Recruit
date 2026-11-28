<?php
/**
 * Reading back from the HRMS (update 081, 28 Nov 2026), end to end: the local HRMS app runs on PHP's built-in server,
 * both apps hold the same throwaway link key, and Recruit fetches the HRMS's rates and leavers. Then: the HRMS's figure
 * beside each Recruit rate (PF admin = admin + EDLI; gratuity = 15/26/12), and the leaver on the candidate.
 * LOCAL ONLY, needs the HRMS checked out next to Recruit (E:/Claude/Eshcol/HRMS). Run: php tests/hrms-feeds-test.php
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
$hrmsApp = 'E:/Claude/Eshcol/HRMS/app';
if (!is_dir($hrmsApp)) {
    echo "  ok   (the HRMS is not checked out here: skipped)\n\n1 passed, 0 failed\n";
    exit(0);
}
$key = 'zz' . bin2hex(random_bytes(24));
$hadKey = Secrets::get($app, 'hrms_link_key');
$hadUrl = $app->setting('hrms_url', 'https://hrms.eshcolglobal.com');
Secrets::put($app, 'hrms_link_key', $key);
$cand = $db->insert('candidates', ['full_name' => 'Zz Feeds Leaver', 'email' => 'zz.feeds@example.test', 'source' => 'manual']);
$client = (int) $db->col("SELECT id FROM clients WHERE name LIKE 'HINE%' LIMIT 1") ?: null;

// The HRMS side: the same key, and a leaver with this candidate's Recruit reference.
$php = PHP_BINARY;
$setup = $hrmsApp . '/../zz-feeds-setup.php';
file_put_contents($setup, '<?php require "' . $hrmsApp . '/vendor/autoload.php"; $a = \Eshcol\Hrms\App::boot(cli: true); $db = $a->db;
$mode = $argv[1];
if ($mode === "up") {
    $old = \Eshcol\Hrms\Secrets::get($a, "recruit_link_key"); file_put_contents(__DIR__ . "/zz-feeds-oldkey", (string) $old);
    \Eshcol\Hrms\Secrets::put($a, "recruit_link_key", $argv[2]);
    $db->run("DELETE FROM employees WHERE full_name = \'Zz Feeds Leaver\'");
    $u = (int) $db->col("SELECT id FROM users ORDER BY id LIMIT 1");
    [$id] = (new \Eshcol\Hrms\Services\Employees($a))->create(["full_name" => "Zz Feeds Leaver", "joined_on" => "2025-01-06", "engagement" => "staffing"], $u);
    $db->run("UPDATE employees SET status = \'active\', recruit_candidate_ref = ? WHERE id = ?", ["EG-" . $argv[3], $id]);
    (new \Eshcol\Hrms\Services\Exits($a))->start($id, ["kind" => "resignation", "last_day" => date("Y-m-d", strtotime("+12 days")), "rehire_ok" => "0"], $u);
    echo $db->col("SELECT emp_no FROM employees WHERE id = ?", [$id]);
} else {
    $old = (string) @file_get_contents(__DIR__ . "/zz-feeds-oldkey"); @unlink(__DIR__ . "/zz-feeds-oldkey");
    $old !== "" ? \Eshcol\Hrms\Secrets::put($a, "recruit_link_key", $old) : \Eshcol\Hrms\Secrets::forget($a, "recruit_link_key");
    $db->run("DELETE FROM employees WHERE full_name = \'Zz Feeds Leaver\'");
}');
$empNo = trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($setup) . ' up ' . escapeshellarg($key) . ' ' . $cand));
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$pub = $hrmsApp . '/public';
$proc = proc_open([$php, '-S', "127.0.0.1:$port", '-t', $pub, "$pub/index.php"], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$db->run("INSERT INTO settings (k, v) VALUES ('hrms_url', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ["http://127.0.0.1:$port"]);

try {
    $L = new HrmsLink($app);
    $check('the HRMS made a leaver (' . $empNo . ')', str_starts_with($empNo, 'ESH-'));
    $out = $L->fetchFeeds();
    $check('fetched: ' . $out, str_contains($out, 'rates: ') && str_contains($out, 'exits: ') && !str_contains($out, 'answered'));
    $check('the HRMS rates are stored', (int) $db->col('SELECT COUNT(*) FROM hrms_rates') > 20);
    $pf = $L->hrmsValue('pf_ceiling');
    $check('PF ceiling from the HRMS: Rs. 25,000', $pf && (float) $pf['value'] === 25000.0);
    $adm = $L->hrmsValue('pf_admin_pct');
    $check('PF admin + EDLI from the HRMS: 1%', $adm && abs($adm['value'] - 1.0) < 0.0001);
    $gr = $L->hrmsValue('gratuity_pct');
    $check('gratuity from the HRMS: 15/26/12 = 4.8077%', $gr && abs($gr['value'] - 4.8077) < 0.0001);
    $check("Recruit's own rates (GST, overheads) have no HRMS figure", $L->hrmsValue('gst_pct') === null);
    $x = $db->one('SELECT * FROM hrms_exits WHERE emp_no = ?', [$empNo]);
    $check('the leaver is matched to the candidate, on notice, marked do not rehire', $x && (int) $x['candidate_id'] === $cand && !(int) $x['has_left'] && (int) $x['rehire_ok'] === 0 && $x['reason'] === 'Resignation');
    $check('fetching again updates rather than doubles', str_contains($L->fetchFeeds(), 'exits:') && (int) $db->col('SELECT COUNT(*) FROM hrms_exits WHERE emp_no = ?', [$empNo]) === 1);
    Secrets::put($app, 'hrms_link_key', 'zz-wrong-' . bin2hex(random_bytes(20)));
    $check('a wrong key is refused by the HRMS', str_contains($L->fetchFeeds(), '401') || str_contains($L->fetchFeeds(), 'Signature'));
} finally {
    proc_terminate($proc);
    shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($setup) . ' down');
    @unlink($setup);
    $hadKey !== null ? Secrets::put($app, 'hrms_link_key', $hadKey) : Secrets::forget($app, 'hrms_link_key');
    $db->run("INSERT INTO settings (k, v) VALUES ('hrms_url', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$hadUrl]);
    $db->run('DELETE FROM hrms_exits WHERE candidate_id = ? OR emp_no = ?', [$cand, $empNo]);
    $db->run('DELETE FROM hrms_rates');
    $db->run("DELETE FROM settings WHERE k = 'hrms_feeds_at'");
    $db->run('DELETE FROM candidates WHERE id = ?', [$cand]);
}

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
