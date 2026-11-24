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
// "Remove my details" (24 Nov 2026): one person asked, a working link to the confirm page, and a used link.
$db->run('UPDATE candidates SET removal_requested_at = NOW() WHERE id = ?', [$cOther]);
$rmTok = 'smoke' . bin2hex(random_bytes(12));
$db->insert('cv_requests', ['candidate_id' => $cOther, 'grp' => 2, 'token_hash' => hash('sha256', $rmTok), 'token_enc' => 'x', 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')]);
$usedTok = 'smokeused' . bin2hex(random_bytes(12));
$db->run('INSERT INTO removed_links (token_hash) VALUES (?)', [hash('sha256', $usedTok)]);
register_shutdown_function(fn () => $db->run('DELETE FROM removed_links WHERE token_hash = ?', [hash('sha256', $usedTok)]));
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
    ['/candidates?scans=1', 200, 'scan or photo'], ['/candidates?removal=1', 200, 'Smoke Otherrole'],['/candidates?removal=1', 200, 'Delete the ticked'],["/u/$rmTok/remove", 200, 'Delete my details permanently'], ["/u/$usedTok", 200, 'Your details have been deleted'],
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

echo "\nBack to where you came from (Benjamin's rule, 24 Nov 2026)\n";
// Laxmi Singh: My day › Contact changes to approve › Check › "Use the new details" landed on Candidates.
$db->run('UPDATE candidates SET pending_contact = ?, updated_at = NOW() WHERE id = ?', [json_encode(['phone' => '+91 90000 00000', 'at' => date('Y-m-d H:i:s')]), $cMine]);
$post = function (string $who, string $path, array $fields) use ($port, &$cookie): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => $cookie[$who], CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), preg_match('/^Location: (.+)$/mi', $raw, $m) ? trim($m[1]) : ''];
};
$contentOf = fn (string $body) => substr($body, (int) strpos($body, 'id="content"'));
$detailLinks = function (string $body) use ($contentOf): array {
    preg_match_all('#<a\b[^>]*\bhref="(/[a-z-]+/\d+[^"]*)"#i', $contentOf($body), $m, PREG_SET_ORDER);
    return array_values(array_filter(array_map(fn ($x) => html_entity_decode($x[1]), $m),
        fn ($h) => !preg_match('#/(download|data|logo|file|cv)(/|$|\?)|/documents/\d+|\.(csv|pdf)#', $h)));
};
[$code, $day] = $get('owner', '/');
$csrf = preg_match('/<meta name="csrf" content="([^"]+)"/', $day, $cm) ? $cm[1] : '';
$check('My day lists the contact change to approve', str_contains($day, 'Smoke Mineonly gave a new email or mobile'));
$links = $detailLinks($day);
$noBack = array_filter($links, fn ($h) => !str_contains($h, 'back=%2F'));
$check('every My day link into a page carries back=/ (' . count($links) . ' links)', $links && !$noBack, 'missing on: ' . implode(', ', array_slice($noBack, 0, 5)));
$check('…the Check button too, naming its section: back=/#waiting-contact-changes-to-approve', in_array("/candidates/$cMine?back=%2F%23waiting-contact-changes-to-approve", $links, true), implode(', ', array_filter($links, fn ($h) => str_contains($h, "/candidates/$cMine?"))));
foreach (["/candidates/$cMine?back=%2F" => 'My day', "/roles/$mine?back=%2F" => 'My day', "/shortlists/$short?back=%2Fcalendar" => 'Calendar', "/candidates/$cMine?back=%2Femails%3Ftab%3Dheld" => 'Emails'] as $path => $name) {
    [$code, $body] = $get('owner', $path);
    $back = Eshcol\Ats\Back::safe(urldecode((string) explode('back=', $path)[1]));
    $forms = preg_match_all('#<form\b[^>]*method="post"[^>]*>#i', $contentOf($body));
    $carried = preg_match_all('#<form\b[^>]*method="post"[^>]*><input type="hidden" name="_back" value="' . preg_quote(htmlspecialchars($back), '#') . '">#i', $contentOf($body));
    $check("$path: Back button says \"Back to $name\" and goes there", $code === 200 && $phpError($body) === '' && str_contains($body, '<a class="back-btn" href="' . htmlspecialchars($back) . '">‹ Back to ' . $name . '</a>'), "status $code");
    $check("…all $forms of its forms carry the origin", $forms > 0 && $carried === $forms, "$carried of $forms");
}
[$code, $loc] = $post('owner', "/candidates/$cMine/contact", ['_csrf' => $csrf, 'decision' => 'dismiss', '_back' => '/#waiting-contact-changes-to-approve', '_here' => "/candidates/$cMine?back=%2F%23waiting-contact-changes-to-approve"]);
$check('"Keep the old ones" from My day: back to My day, at the Contact changes section', $code === 303 && $loc === '/#waiting-contact-changes-to-approve', "status $code, to $loc");
[$code, $day2] = $get('owner', '/');
$check('…that section is still there to land on once the item has gone (or the browser stays at the Waiting on you card)', !str_contains($day2, 'Smoke Mineonly gave a new email') && str_contains($day2, 'id="waiting"'));
[$code, $loc] = $post('owner', "/candidates/$cMine/unreliable", ['_csrf' => $csrf, 'clear' => '1', '_back' => '/calendar?week=2026-11-23', '_here' => "/candidates/$cMine?back=x"]);
$check('an action from a candidate opened from Calendar: back to the same Calendar week', $code === 303 && $loc === '/calendar?week=2026-11-23', "status $code, to $loc");
[$code, $loc] = $post('owner', "/candidates/$cMine/unreliable", ['_csrf' => $csrf, 'clear' => '1', '_back' => '//evil.example/', '_here' => "/candidates/$cMine"]);
$check('a back to another site is ignored', $code === 303 && $loc === "/candidates/$cMine", "status $code, to $loc");
[$code, $loc] = $post('owner', "/candidates/$cMine/unreliable", ['_csrf' => $csrf, 'clear' => '1']);
$check('opened with no origin: as before, back on their page', $code === 303 && $loc === "/candidates/$cMine", "status $code, to $loc");
// Live check after 1443: the lists carried back= but no row to come back to (24 Nov 2026).
foreach (['/candidates' => "#/candidates/$cMine\?back=%2Fcandidates%23c-$cMine\b#", '/roles' => "#/roles/$mine\?back=%2Froles%23role-$mine\b#", '/clients' => '#/clients/\d+\?back=%2Fclients%23client-\d+#'] as $list => $want) {
    [$code, $body] = $get('owner', $list);
    $check("$list: a row's link brings him back to that row", $code === 200 && preg_match($want, $contentOf($body)) === 1, "status $code");
}
[$code, $body] = $get('owner', "/candidates/$cMine?back=%2Froles%2F999999%23tab-pipeline");
$check('from a position where his row isn\'t found: Back still lands on the section he came from', str_contains($body, 'href="/roles/999999#tab-pipeline" data-back-role="999999"'));

echo "\nA client's own basic share and bonus within the gross, saved from the form (HINE, 24 Nov 2026)\n";
[$code, $loc] = $post('owner', "/clients/$client/staffing-terms", ['_csrf' => $csrf, 'billing_model' => 'markup', 'markup_pct' => '8', 'markup_base' => 'cost', 'wage_rule' => 'gross',
    'wage_share_pct' => '70', 'bonus_in_gross' => '1', 'gst_pct' => '18', 'invoice_timing' => 'month_end', 'payment_due_days' => '0', 'deposit_months' => '0']);
$tr = $db->one('SELECT * FROM client_terms WHERE client_id = ? AND is_current = 1', [$client]);
$check('saved: 8 % on the total cost, basic 70 %, bonus within the gross', $code === 303 && $tr && $tr['markup_base'] === 'cost' && (float) $tr['wage_share_pct'] === 70.0 && (int) $tr['bonus_in_gross'] === 1, "status $code");
[$code, $body] = $get('owner', "/clients/$client");
$check('…and the client page says so', $code === 200 && $phpError($body) === '' && str_contains($body, '<b>70 % of gross</b> (this client\'s own)') && str_contains($body, 'bonus paid monthly within the gross'));
[$code, $loc] = $post('owner', "/clients/$client/staffing-terms", ['_csrf' => $csrf, 'billing_model' => 'markup', 'markup_pct' => '8', 'markup_base' => 'cost', 'wage_share_pct' => '', 'payment_due_days' => '0']);
$tr = $db->one('SELECT * FROM client_terms WHERE client_id = ? AND is_current = 1', [$client]);
$check('left empty: back to the share in Settings › Staffing, and the bonus on top again', $tr && $tr['wage_share_pct'] === null && (int) $tr['bonus_in_gross'] === 0);

echo "\nSigned out\n";
$cookie['nobody'] = 'x=y';
[$code] = $get('nobody', "/roles/$mine");
$check('a private page sends you to sign in', in_array($code, [302, 303], true), "status $code");
[$code, $body] = $get('nobody', '/login');
$check('the sign-in page opens', $code === 200 && $phpError($body) === '');
