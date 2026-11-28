<?php
/**
 * 28 Nov 2026: Claude's read-only link. Signed requests only; answers carry no email, phone or street address;
 * people who asked to be removed or are do-not-contact never show; the local MCP script speaks the protocol and has no write tool.
 * Run: php tests/mcp-link-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\HrmsLink;
use Eshcol\Ats\Services\McpLink;
use Eshcol\Ats\Services\Retention;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$hadKey = Secrets::get($app, McpLink::KEY);
$clientId = $db->insert('clients', ['name' => "ZZ Mcp $tag"]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $hadKey, &$ok, &$bad) {
    foreach (array_unique($made) as $cid) {
        (new Retention($app))->delete($cid, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $hadKey ? Secrets::put($app, McpLink::KEY, $hadKey) : Secrets::forget($app, McpLink::KEY);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});

$L = new McpLink($app);
echo "The key and the signature\n";
Secrets::forget($app, McpLink::KEY);
$check('no key: the link is off and nothing verifies', !$L->configured() && !$L->verify('GET', '/hooks/mcp/positions', (string) time(), 'x', ''));
$key = $L->newKey();
$check('a key is made (long, URL-safe) and stored', $L->configured() && preg_match('/^[A-Za-z0-9_-]{40,64}$/', $key) === 1 && Secrets::get($app, McpLink::KEY) === $key);
$now = time();
$sig = McpLink::sign($key, 'GET', '/hooks/mcp/positions', $now, '');
$check('the signature is the same one the HRMS link uses', $sig === HrmsLink::sign($key, 'GET', '/hooks/mcp/positions', $now, ''));
$check('a good signature passes', $L->verify('GET', '/hooks/mcp/positions', (string) $now, $sig, ''));
$check('another path, key, or an old request fails', !$L->verify('GET', '/hooks/mcp/search', (string) $now, $sig, '')
    && !$L->verify('GET', '/hooks/mcp/positions', (string) $now, McpLink::sign('wrongkey', 'GET', '/hooks/mcp/positions', $now, ''), '')
    && !$L->verify('GET', '/hooks/mcp/positions', (string) ($now - 600), McpLink::sign($key, 'GET', '/hooks/mcp/positions', $now - 600, ''), ''));
$check('a query cannot be swapped under the same signature', !$L->verify('GET', '/hooks/mcp/search?q=other', (string) $now, McpLink::sign($key, 'GET', '/hooks/mcp/search?q=mine', $now, ''), ''));
$key2 = $L->newKey();
$check('a new key stops the old one at once', $key2 !== $key && !$L->verify('GET', '/hooks/mcp/positions', (string) $now, $sig, ''));

echo "\nWhat Recruit answers\n";
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Mcp Technician $tag", 'location' => 'Chennai', 'status' => 'open', 'score_threshold' => 0, 'accepts_routed' => 0,
    'jd_text' => 'Assembly and testing of hydraulic pumps on the shop floor.']);
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-mcp-$tag.txt");
$pad = str_repeat('Responsible for daily work and reporting to the manager. ', 12);
$person = function (string $name, string $body) use ($imp, $tmp, $uid, $tag, $role, &$made, $db): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    $phone = '+91 98765 4' . random_int(1000, 9999);
    file_put_contents($tmp, "$name\n$email | $phone\nChennai\n$body");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $role, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], $email, $phone];
};
[$cA, $emailA, $phoneA] = $person("Visible $tag Person", "B.E. Mechanical Engineering 2026. Internship at a pump assembly unit.\n$pad");
[$cB] = $person("Removed $tag Person", "B.E. Mechanical Engineering 2026.\n$pad");
[$cC] = $person("Silent $tag Person", "B.E. Mechanical Engineering 2026.\n$pad");
$db->run('UPDATE candidates SET removal_requested_at = NOW() WHERE id = ?', [$cB]);
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$cC]);

$pos = $L->positions();
$mine = array_values(array_filter($pos, fn ($p) => $p['id'] === $role));
$check('the position is listed with its client and how many are at each stage', count($mine) === 1 && $mine[0]['client'] === "ZZ Mcp $tag" && array_sum($mine[0]['people_by_stage']) === 1);
$p = $L->position($role);
$check('one position: the whole job description is there', str_contains($p['jd_text'], 'hydraulic pumps') && $L->position(999999) === null);
$list = $L->candidates($role);
$check('people on the position: the visible one only (removed and do-not-contact are hidden)', count($list) === 1 && $list[0]['candidate_id'] === $cA);
$json = json_encode([$pos, $p, $list, $L->candidate($cA), $L->search("Visible $tag")]);
$check('no email address and no phone number anywhere in any answer', !str_contains($json, $emailA) && !str_contains($json, '@candidates.test') && !str_contains($json, str_replace(' ', '', $phoneA)) && !str_contains($json, $phoneA));
$check('a removed or do-not-contact person cannot be opened or found', $L->candidate($cB) === null && $L->candidate($cC) === null && $L->search("Removed $tag") === [] && $L->search("Silent $tag") === []);
$one = $L->candidate($cA);
$check('one person: facts, applications and a CV without their name', strcasecmp($one["name"], "Visible $tag Person") === 0 && count($one['applications']) === 1
    && str_contains($one['cv_without_name_or_contact'], 'pump assembly') && stripos($one["cv_without_name_or_contact"], "Visible $tag") === false);
$check('search finds by name and ignores one-letter searches', count($L->search("Visible $tag")) === 1 && $L->search('a') === []);
$check('stage and kind-of-work filters narrow the list; a made-up stage finds nobody', count($L->candidates($role, 'Applied')) + count($L->candidates($role, 'Nonexistent stage')) <= 1 && $L->candidates($role, 'Nonexistent stage') === []);

$db->run("INSERT INTO suitability_checks (application_id, candidate_id, role_id, status, verdict, confidence, reason) VALUES ((SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?), ?, ?, 'done', 'no', 'high', 'x') ON DUPLICATE KEY UPDATE status = 'done', verdict = 'no', confidence = 'high', reason = 'x'", [$cA, $role, $cA, $role]);
$check('the kind-of-work filter works: "no" finds the person, "yes" does not, a made-up answer is ignored', count($L->candidates($role, null, 'no')) === 1 && $L->candidates($role, null, 'yes') === [] && count($L->candidates($role, null, 'bogus')) === 1);

echo "\nThe controller and routes\n";
$routes = (string) file_get_contents(dirname(__DIR__) . '/routes.php');
$check('every Claude route is GET only, under /hooks/mcp/', preg_match_all('#\$router->(\w+)\(\'/hooks/mcp/#', $routes, $m) === 5 && array_unique($m[1]) === ['get']);
$ctl = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/McpApiController.php');
$check('the controller refuses anything but GET and checks the signature first', str_contains($ctl, "Http::method() !== 'GET'") && str_contains($ctl, '$L->verify('));
$check('the owner page and the key are owner-only', substr_count($ctl, 'Auth::requireOwner();') === 2);

echo "\nThe MCP script on your computer\n";
$script = dirname(__DIR__, 2) . '/mcp/recruit-mcp.php';
$php = PHP_BINARY;
$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open([$php, $script], $spec, $pipes, null, ['RECRUIT_URL' => 'http://127.0.0.1:1', 'RECRUIT_KEY' => 'k' . str_repeat('x', 45)] + getenv());
$send = function (array $m) use ($pipes) { fwrite($pipes[0], json_encode($m) . "\n"); };
$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]);
$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
$send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_positions', 'arguments' => new stdClass()]]);
$send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'delete_everything', 'arguments' => new stdClass()]]);
fclose($pipes[0]);
$lines = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", stream_get_contents($pipes[1])))));
proc_close($proc);
$by = array_column($lines, null, 'id');
$check('it answers initialize with the same protocol version and a tools capability', ($by[1]['result']['protocolVersion'] ?? '') === '2025-06-18' && isset($by[1]['result']['capabilities']['tools']));
$names = array_column($by[2]['result']['tools'] ?? [], 'name');
$check('it offers five tools, all marked read-only, and none that writes', $names === ['list_positions', 'get_position', 'list_people', 'get_person', 'search_people']
    && !array_filter($by[2]['result']['tools'], fn ($t) => empty($t['annotations']['readOnlyHint'])));
$check('an unreachable Recruit gives a plain error, not a crash', ($by[3]['result']['isError'] ?? false) === true && str_contains($by[3]['result']['content'][0]['text'], 'Could not reach'));
$check('an unknown tool is refused', isset($by[4]['error']) && str_contains($by[4]['error']['message'], 'Unknown tool'));
$check('no answer was sent for the notification', count($lines) === 4);
$src = (string) file_get_contents($script);
$check('the script only ever sends GET', !preg_match('/\'method\'\s*=>\s*\'(POST|PUT|DELETE|PATCH)\'/', $src) && str_contains($src, "'method' => 'GET'"));
