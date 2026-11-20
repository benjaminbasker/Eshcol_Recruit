<?php
/**
 * Page smoke test (audit F16, 15 Nov 2026): starts its own copy of the app on a spare port, signs in as the
 * owner, a recruiter and a contractor, and opens every page. Each page must answer with the right status, show
 * no PHP warning or error, and (where given) contain a key phrase. The contractor must be refused everything
 * outside their position. Catches what the service-level suites can't: a broken view, a missing variable,
 * a bad include. Uses throwaway data and removes it. LOCAL ONLY. Run: php tests/smoke-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Access;
use Eshcol\Ats\App;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Shortlists;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond, string $why = '') use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . ($cond || $why === '' ? '' : "  [$why]") . "\n";
};
if ((new \Eshcol\Ats\Services\Migrator($app))->pending()) {
    exit("Apply the pending updates first.\n");
}

// --- Throwaway data: a client, two positions, two candidates, a draft shortlist, three users ---
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "ZZ Smoke $tag"]);
$role = fn (string $t) => $db->insert('roles', ['client_id' => $client, 'title' => "$t ZZ$tag", 'location' => 'Pune', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0,
    'exp_min' => 2, 'exp_max' => 5, 'ctc_max_lpa' => 5, 'jd_text' => 'Accounts role. Tally, GST, TDS.', 'opened_at' => date('Y-m-d')]);
$mine = $role('Smoke Accountant');
$other = $role('Smoke Plant Head');
// Phase 1 position types (15 Nov 2026): a staffing position and one of our own.
$staffRole = $role('Smoke Operator');
$db->update('roles', ['engagement' => 'staffing', 'contract_months' => 12, 'location' => 'Tiruvallur'], $staffRole);
$ownRole = $db->insert('roles', ['client_id' => \Eshcol\Ats\Services\Engagement::selfClientId($app), 'engagement' => 'inhouse', 'title' => "Smoke Recruiter ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
foreach (['Tally', 'GST'] as $i => $v) {
    $db->insert('role_criteria', ['role_id' => $mine, 'kind' => 'must', 'value' => $v, 'sort' => $i]);
}
$made = [];
$users = [];
$sessions = [];
$server = null;
$sessDir = $app->storage('sessions');
register_shutdown_function(function () use ($app, $db, &$made, &$users, &$sessions, &$server, $client, $routingWas, $sessDir, &$ok, &$bad) {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM shortlist_items WHERE shortlist_id IN (SELECT s.id FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ?)', [$client]);
    $db->run('DELETE s FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ?', [$client]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run("DELETE FROM roles WHERE title LIKE ? AND engagement = 'inhouse'", ['Smoke Recruiter ZZ%']);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    foreach ($users as $u) {
        $db->run('DELETE FROM role_access WHERE user_id = ?', [$u]);
        $db->run('DELETE FROM users WHERE id = ?', [$u]);
    }
    foreach ($sessions as $sid) {
        @unlink("$sessDir/sess_$sid");
    }
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-smoke-$tag.txt");
$person = function (string $name, int $roleId) use ($imp, $tmp, $owner, $tag, $db, &$made): array {
    file_put_contents($tmp, "$name\n" . strtolower(str_replace(' ', '.', $name)) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999)
        . "\nPune\nAccountant, 2021 – Present\n4 years of experience in Tally, GST and TDS returns.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $owner);
    $made[] = (int) $r['candidate_id'];
    $appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [(int) $r['candidate_id'], $roleId]);
    return [(int) $r['candidate_id'], $appId, (int) $r['cv_file_id']];
};
[$cMine, $aMine, $fMine] = $person('Smoke Mineonly', $mine);
[$cOther, $aOther] = $person('Smoke Otherrole', $other);
@unlink($tmp);
$short = (new Shortlists($app))->create($mine, [[$aMine, 'Good fit']], 'Client Person', "client.$tag@clients.test", '', '', true, $owner);
$rec = $db->insert('users', ['name' => "Smoke Recruiter $tag", 'email' => "rec.$tag@smoke.test", 'role' => 'recruiter', 'password_hash' => 'x', 'is_active' => 1]);
$con = $db->insert('users', ['name' => "Smoke Contractor $tag", 'email' => "con.$tag@smoke.test", 'role' => 'contractor', 'password_hash' => 'x', 'is_active' => 1]);
$users = [$rec, $con];
Access::assign($con, [$mine], $owner);

// --- A signed-in session per user, written as the app writes them ---
session_save_path($sessDir);
session_name(Http::cookieName($app, 'eshcol_ats_'));
$signIn = function (int $uid) use (&$sessions): string {
    $sid = bin2hex(random_bytes(16));
    session_id($sid);
    session_start();
    $_SESSION = ['uid' => $uid, 'login_at' => time(), 'last_seen' => time(), 'trusted' => true];
    session_write_close();
    $sessions[] = $sid;
    return session_name() . '=' . $sid;
};
$cookie = ['owner' => $signIn($owner), 'recruiter' => $signIn($rec), 'contractor' => $signIn($con)];

// --- Our own server, with every warning shown, on a spare port ---
$port = 0;
foreach (range(8131, 8160) as $p) {
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if (!$s) {
        $port = $p;
        break;
    }
    fclose($s);
}
$root = dirname(__DIR__);
$server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"],
    [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); $i++) {
    usleep(100000);
}
$get = function (string $who, string $path) use ($port, &$cookie): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => $cookie[$who]]);
    $body = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
};
$phpError = fn (string $body) => preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:|\b(Warning|Notice|Deprecated|Fatal error): .+ in .+ on line \d+|Uncaught /', $body, $m, PREG_OFFSET_CAPTURE) ? strip_tags(substr($body, $m[0][1], 260)) : '';

// --- Every page: [path, expected status, phrase that must appear (or '')] ---
$titleMine = "Smoke Accountant ZZ$tag";
$pages = [
    ['/', 200, 'My day'], ['/?view=all', 200, ''], ['/search?q=Smoke', 200, ''],
    ['/clients', 200, "ZZ Smoke $tag"], ['/clients/new', 200, ''], ["/clients/$client", 200, "ZZ Smoke $tag"], ["/clients/$client/edit", 200, ''],
    ['/roles', 200, $titleMine], ['/roles/new', 200, ''],
    ["/roles/$mine", 200, $titleMine], ["/roles/$mine?view=board", 200, 'Smoke Mineonly'], ["/roles/$mine?view=list", 200, 'Smoke Mineonly'],
    ["/roles/$mine?part=claude", 200, ''], ["/roles/$mine/edit", 200, ''], ["/roles/$mine/matches", 200, ''], ["/roles/$mine/post", 200, ''],
    ["/roles/$mine/questions", 200, ''], ["/roles/$mine/shortlist/new", 200, ''],
    ['/candidates', 200, 'Smoke Mineonly'], ["/candidates/$cMine", 200, 'Add interview feedback'], ["/candidates/$cMine/edit", 200, ''],
    ["/candidates/$cMine/whatsapp", 303, ''], ["/candidates/$cMine/data", 200, ''], ["/cv/$fMine", 200, ''], ["/applications/$aMine/why", 200, ''],
    ["/shortlists/$short", 200, 'Smoke Mineonly'], ["/shortlists/$short/email", 200, ''],
    ['/details-requests', 200, ''], ['/cv-requests', 200, ''], ['/messages', 200, ''], ['/emails', 200, ''], ['/upload', 200, ''],
    ['/placements', 200, ''], ['/reports', 200, ''], ['/account', 200, ''],
    ["/roles/$staffRole", 200, 'Staffing: on Eshcol'], ["/roles/$ownRole", 200, 'Our own team'], ['/roles?type=inhouse', 200, "Smoke Recruiter ZZ$tag"],
    ['/roles?type=staffing', 200, "Smoke Operator ZZ$tag"], ['/roles/new?type=staffing', 200, 'Who is this for?'], ["/roles/$ownRole/edit", 200, 'Hiring manager'],
    ['/joinings', 200, 'Joinings'], ['/joinings?show=all', 200, ''], ['/settings/staffing', 200, 'Statutory rates'], ['/settings/staffing', 200, 'Minimum wage (Basic + DA)'], ["/clients/$client", 200, 'Staffing terms'],
    ['/settings', 200, ''], ['/settings/new-words', 200, ''], ['/settings/ai-reading', 200, ''], ['/settings/reader-test', 200, ''], ['/settings/categories', 200, ''],
];

echo "The owner: every page opens cleanly\n";
foreach ($pages as [$path, $want, $phrase]) {
    [$code, $body] = $get('owner', $path);
    $err = $phpError($body);
    $check("$path", $code === $want && $err === '' && ($phrase === '' || str_contains($body, $phrase)),
        $code !== $want ? "status $code" : ($err !== '' ? $err : "no \"$phrase\""));
}

echo "\nA recruiter: the same pages, except the owner's own\n";
$ownerOnly = ['/joinings', '/joinings?show=all', '/settings/staffing', "/roles/$ownRole", "/roles/$ownRole/edit", "/candidates/$cMine/data", '/placements', '/settings', '/settings/new-words', '/settings/ai-reading', '/settings/reader-test', '/settings/categories', '/reports'];
foreach ($pages as [$path, $want, $phrase]) {
    [$code, $body] = $get('recruiter', $path);
    $err = $phpError($body);
    if (in_array($path, $ownerOnly, true)) {
        $check("$path refused", $code === 403 && $err === '', "status $code");
    } else {
        $check("$path", $code === $want && $err === '', $code !== $want ? "status $code" : $err);
    }
}

echo "\nA contractor: their position only\n";
[$code] = $get('contractor', '/');
$check('My day sends them to their positions', in_array($code, [302, 303], true), "status $code");
$allowed = ['/roles', "/roles/$mine", "/roles/$mine?view=board", "/roles/$mine?view=list", "/roles/$mine?part=claude", "/candidates/$cMine",
    "/cv/$fMine", "/applications/$aMine/why", "/shortlists/$short", '/upload', '/account', '/search?q=Smoke'];
foreach ($allowed as $path) {
    [$code, $body] = $get('contractor', $path);
    $err = $phpError($body);
    $check("$path opens", $code === 200 && $err === '' && !str_contains($body, 'Smoke Otherrole') && !str_contains($body, "Smoke Plant Head ZZ$tag"),
        $code !== 200 ? "status $code" : ($err ?: 'shows the other position: ' . (str_contains($body, 'Smoke Otherrole') ? 'its candidate' : 'its title') . ' near: ' . strip_tags(substr($body, max(0, (int) strpos($body, "Smoke Plant Head") - 200), 300))));
}
$refused = ["/roles/$other", "/candidates/$cOther", "/applications/$aOther/why", '/candidates', '/clients', "/clients/$client", "/roles/$mine/edit",
    "/roles/$mine/matches", "/candidates/$cMine/edit", '/settings', '/reports', '/emails', '/messages', '/placements', '/details-requests'];
foreach ($refused as $path) {
    [$code, $body] = $get('contractor', $path);
    $check("$path refused", $code === 403 && $phpError($body) === '', "status $code");
}

echo "\nSigned out\n";
$cookie['nobody'] = 'x=y';
[$code] = $get('nobody', "/roles/$mine");
$check('a private page sends you to sign in', in_array($code, [302, 303], true), "status $code");
[$code, $body] = $get('nobody', '/login');
$check('the sign-in page opens', $code === 200 && $phpError($body) === '');
