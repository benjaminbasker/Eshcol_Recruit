<?php
/**
 * 27 Nov 2026 (Benjamin: "read the whole CV against the whole JD, not keywords; an admin executive was evaluated for a
 * technician's job"): the "same kind of work?" check. Claude (mocked here) answers yes / maybe / no with a reason; the
 * trial mode records and reports, "on" keeps a confident "no" out of the assessments; nothing moves without the button.
 * Run: php tests/suitability-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FitAssessor;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Stages;
use Eshcol\Ats\Services\SuitabilityCheck as S;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

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
$ym = date('Ym');
$keys = ['suitability_mode', 'suitability_reader', 'suitability_monthly_limit', 'routing_enabled', 'auto_sort_unassigned', 'talent_pools_enabled', "suit_count_$ym", "suit_cost_$ym"];
$was = [];
foreach ($keys as $k) {
    $was[$k] = $app->setting($k);
}
$set = fn (string $k, ?string $v) => $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
foreach (['routing_enabled' => '0', 'auto_sort_unassigned' => '0', 'suitability_mode' => 'trial', 'suitability_reader' => 'haiku', 'suitability_monthly_limit' => '3000', "suit_count_$ym" => '0', "suit_cost_$ym" => '0'] as $k => $v) {
    $set($k, $v);
}
$maxRole = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM roles');
$clientId = $db->insert('clients', ['name' => "ZZ Suitability $tag"]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $was, $set, $maxRole, &$ok, &$bad) {
    foreach (array_unique($made) as $cid) {
        (new Retention($app))->delete($cid, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($was as $k => $v) {
        $set($k, $v);
    }
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});

$mock = function (array $replies, ?array &$history = null) {
    $responses = array_map(fn (array $r) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_s', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 5000, 'output_tokens' => 200],
        'content' => [['type' => 'text', 'text' => json_encode($r + ['confidence' => 'high', 'cv_work' => 'HR and admin', 'position_work' => 'Hydraulic assembly', 'reason' => 'x', 'evidence' => ['quote']])]],
    ])), $replies);
    $stack = HandlerStack::create(new MockHandler($responses));
    $history = [];
    $stack->push(Middleware::history($history));
    return new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]);
};

$tech = $db->insert('roles', ['client_id' => $clientId, 'title' => "Trainee Technician $tag", 'location' => 'Chennai', 'status' => 'open', 'score_threshold' => 0, 'accepts_routed' => 0,
    'jd_text' => "Trainee Technicians: fresh engineering graduates. Assembly and testing of high-precision hydraulic pumps on the shop floor. Read drawings, use torque wrenches and test benches, follow quality checklists."]);
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-suit-$tag.txt");
$padding = str_repeat('Responsible for daily work and reporting to the manager. ', 12);
$person = function (string $name, string $body, int $role) use ($imp, $tmp, $uid, $tag, $db, &$made): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 98765 4" . random_int(1000, 9999) . "\nChennai\n$body");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $role, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $role])];
};
[$cMech, $aMech] = $person("Mechanic $tag Fresher", "B.E. Mechanical Engineering 2026. Final year project: hydraulic press design. Internship at a pump assembly unit.\n$padding", $tech);
[$cHr, $aHr] = $person("Admin $tag Person", "MBA Human Resources 2025. Worked as HR executive: payroll inputs, onboarding, office administration, vendor bills.\n$padding", $tech);
[$cMay, $aMay] = $person("Maybe $tag Person", "B.Sc Physics 2026. Lab assistant in a college workshop, operated a lathe in a training course.\n$padding", $tech);
[$cThin, $aThin] = $person("Thin $tag Person", 'Short.', $tech);

echo "Queueing\n";
$check('a new application is queued for the check (trial mode)', (int) $db->col("SELECT COUNT(*) FROM suitability_checks WHERE application_id IN (?, ?, ?)", [$aMech, $aHr, $aMay]) === 3);
$set('suitability_mode', 'off');
[$cOff, $aOff] = $person("Offmode $tag Person", "B.E. Mechanical Engineering. Fitter.\n$padding", $tech);
$check('off: nothing is queued', !(int) $db->col('SELECT COUNT(*) FROM suitability_checks WHERE application_id = ?', [$aOff]) && S::forRole($app, $tech) === []);
$set('suitability_mode', 'trial');
$db->run('DELETE FROM suitability_checks WHERE application_id = ?', [$aOff]);

echo "\nWhat Claude is given\n";
$svc0 = new S($app);
$in = $svc0->inputs($aHr);
$check('the position: the title, where, and the WHOLE job description', str_contains($in['role'], "Trainee Technician $tag") && str_contains($in['role'], 'high-precision hydraulic pumps') && str_contains($in['role'], 'quality checklists'));
$check('the CV goes in blind: no name, no email, no phone', !str_contains($in['cv'], "Admin $tag") && !str_contains($in['cv'], '@candidates.test') && str_contains($in['cv'], 'MBA Human Resources'));
$h1 = $in['hash'];
$db->run('UPDATE roles SET jd_text = CONCAT(jd_text, " Also stores.") WHERE id = ?', [$tech]);
$check('the fingerprint changes when the job description changes', $svc0->inputs($aHr)['hash'] !== $h1);
$db->run('UPDATE roles SET jd_text = REPLACE(jd_text, " Also stores.", "") WHERE id = ?', [$tech]);
$check('...and is the same again when it is put back', $svc0->inputs($aHr)['hash'] === $h1);
$set('suitability_reader', 'sonnet');
$check('...and changes with the reader', $svc0->inputs($aHr)['hash'] !== $h1);
$set('suitability_reader', 'haiku');

echo "\nThe answers\n";
$hist = [];
$cli = $mock([
    ['verdict' => 'yes', 'reason' => 'B.E. Mechanical fresher with a pump assembly internship: the same work.', 'cv_work' => 'Mechanical engineering fresher', 'position_work' => 'Hydraulic assembly and testing'],
    ['verdict' => 'no', 'reason' => 'An HR and office administration background; the job is hydraulic assembly on a shop floor.'],
    ['verdict' => 'maybe', 'confidence' => 'medium', 'reason' => 'Some workshop exposure, but a physics degree.'],
], $hist);
$svc = new S($app, $cli);
foreach ([$aMech, $aHr, $aMay] as $id) {
    $svc->check($id);
}
$body = json_encode(array_map(fn ($t) => (string) $t['request']->getBody(), $hist));
$check('Claude was sent the whole job description and the CV, in one request each', count($hist) === 3 && str_contains($body, 'hydraulic pumps') && str_contains($body, 'MBA Human Resources'));
$check('...never the candidate\'s name or contact details', !str_contains($body, "Admin $tag") && !str_contains($body, '@candidates.test'));
$check('...the answer is forced into yes / maybe / no with a reason', str_contains($body, 'maybe') && str_contains($body, 'cv_work'));
$rows = array_column($db->all('SELECT application_id, verdict, confidence, reason, status, cost_usd, model FROM suitability_checks WHERE application_id IN (?, ?, ?)', [$aMech, $aHr, $aMay]), null, 'application_id');
$check('the mechanical fresher is "yes", the HR person "no", the physics graduate "maybe"', $rows[$aMech]['verdict'] === 'yes' && $rows[$aHr]['verdict'] === 'no' && $rows[$aMay]['verdict'] === 'maybe');
$check('the reason is kept, in plain words', str_contains($rows[$aHr]['reason'], 'HR and office administration') && $rows[$aHr]['status'] === 'done');
$check('the cost is counted (Haiku: a few paise)', (float) $rows[$aHr]['cost_usd'] > 0 && (float) $rows[$aHr]['cost_usd'] < 0.01 && $svc->usedThisMonth() === 3);
$r = $svc->check($aThin);
$check('an unreadable CV is skipped, not guessed at', ($r['skipped'] ?? false) && $db->col('SELECT status FROM suitability_checks WHERE application_id = ?', [$aThin]) === 'skipped');

echo "\nTrial mode changes nothing; \"on\" keeps a confident no out of the assessments\n";
$fit = new FitAssessor($app);
[$relTrial] = $fit->relevance($aHr);
[$outTrial] = S::leaveOut($app, $aHr);
$check('trial: a "no" is still judged by the old rules (nothing hidden)', !$outTrial);
$set('suitability_mode', 'on');
[$outOn, $whyOn] = S::leaveOut($app, $aHr);
[$relOn, $relWhy] = $fit->relevance($aHr);
$check('on: the HR person is left out, with Claude\'s reason', $outOn && !$relOn && str_contains($relWhy, 'different kind of work') && str_contains($relWhy, 'HR and office administration'));
$check('on: the mechanical fresher and the physics graduate are not left out', !S::leaveOut($app, $aMech)[0] && !S::leaveOut($app, $aMay)[0]);
$db->run("UPDATE suitability_checks SET confidence = 'low' WHERE application_id = ?", [$aHr]);
$check('on: a "no" Claude is unsure about never keeps anyone out', !S::leaveOut($app, $aHr)[0]);
$db->run("UPDATE suitability_checks SET confidence = 'high' WHERE application_id = ?", [$aHr]);
$check('an application with no answer yet is never left out', !S::leaveOut($app, $aThin)[0]);
$set('suitability_mode', 'trial');

echo "\nThe report and the button\n";
$stageShort = (int) Stages::id($app, Stages::SHORTLISTED);
[$cSh, $aSh] = $person("Shortlisted $tag Person", "HR executive for ten years, payroll and admin.\n$padding", $tech);
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$stageShort, $aSh]);
$db->run("UPDATE suitability_checks SET status = 'done', verdict = 'no', confidence = 'high', reason = 'HR work', cv_work = 'HR', position_work = 'Hydraulics', checked_at = NOW() WHERE application_id = ?", [$aSh]);
$rep = $svc->report($tech);
$check('counts: 1 yes, 1 maybe, 2 no (one of them already shortlisted), the thin CV skipped', $rep['counts']['yes'] === 1 && $rep['counts']['maybe'] === 1 && $rep['counts']['no'] === 2 && $rep['counts']['skipped'] === 1);
$names = array_column($rep['different_active'], 'full_name');
$check('"a different kind of work, still being considered" lists the HR person but never the shortlisted one', count($names) === 1 && str_contains($names[0], 'Admin'));
$check('"agree with you": the shortlisted person Claude calls a different kind is counted for a look', $rep['agree']['shortlisted']['no'] === 1);
$ns = (int) Stages::id($app, Stages::NOT_SUITABLE);
$db->run("UPDATE suitability_checks SET confidence = 'low' WHERE application_id = ?", [$aHr]);
$check('the button moves nobody Claude is unsure about', $svc->applyNo($tech, $uid, false) === []);
$db->run("UPDATE suitability_checks SET confidence = 'high' WHERE application_id = ?", [$aHr]);
$mailsBefore = (int) $db->col('SELECT COUNT(*) FROM email_queue');
$list = $svc->applyNo($tech, $uid);
$check('the button moves the HR person to Not suitable, with Claude\'s reason', count($list) === 1 && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aHr]) === $ns
    && str_starts_with((string) $db->col('SELECT stage_reason FROM applications WHERE id = ?', [$aHr]), 'Different kind of work: An HR and office administration'));
$check('...the shortlisted one, the mechanical fresher and the physics graduate stay', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aSh]) === $stageShort
    && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aMech]) !== $ns && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aMay]) !== $ns);
$check('...and nobody was emailed', (int) $db->col('SELECT COUNT(*) FROM email_queue') === $mailsBefore);
$check('pressing it again finds nobody', $svc->applyNo($tech, $uid) === []);
$check('a rule-screened "same kind of work" is listed for a second look (here: none yet)', $svc->report($tech)['same_screened'] === []);
$db->run("UPDATE applications SET fit_note = 'Screened out: Lives in Pune' WHERE id = ?", [$aHr]);
$db->run("UPDATE suitability_checks SET verdict = 'yes' WHERE application_id = ?", [$aHr]);
$check('...and a "yes" the rules screened out shows there', count($svc->report($tech)['same_screened']) === 1);

echo "\nQueue, changes and limits\n";
$db->run("UPDATE suitability_checks SET status = 'pending' WHERE application_id = ?", [$aMay]);
$set('suitability_monthly_limit', '3');
$stopped = (new S($app, $mock([['verdict' => 'yes']])))->runBatch(5, 10);
$check('the monthly limit stops the checks, saying why', str_contains($stopped, 'paused') && str_contains($stopped, 'limit') && $db->col('SELECT status FROM suitability_checks WHERE application_id = ?', [$aMay]) === 'pending');
$set('suitability_monthly_limit', '3000');
$ran = (new S($app, $mock([['verdict' => 'maybe']])))->runBatch(5, 10);
$check("the five-minute job checks what is waiting [$ran]", str_contains($ran, 'kind-of-work check') && $db->col('SELECT status FROM suitability_checks WHERE application_id = ?', [$aMay]) === 'done');
$check('nothing changed: nothing is queued again, nothing is paid for', $svc->requeueStale() === 0);
$db->run("UPDATE roles SET jd_text = CONCAT(jd_text, ' Night shifts every second week.'), updated_at = NOW() + INTERVAL 1 MINUTE WHERE id = ?", [$tech]);
$db->run('UPDATE suitability_checks SET checked_at = NOW() - INTERVAL 1 HOUR WHERE role_id = ? AND status = ?', [$tech, 'done']);
$n = $svc->requeueStale();
$check('the job description edited: the answers are asked for again (' . $n . ')', $n >= 3 && $db->col('SELECT status FROM suitability_checks WHERE application_id = ?', [$aMech]) === 'pending');
$db->run('UPDATE roles SET jd_text = REPLACE(jd_text, " Night shifts every second week.", "") WHERE id = ?', [$tech]);

echo "\nOn the pages\n";
$view = (string) file_get_contents(dirname(__DIR__) . '/views/roles/show.php');
$check('the position page shows a chip for "different kind of work" and "related", and links to the report', str_contains($view, 'Different kind of work') && str_contains($view, 'Related: check') && str_contains($view, '/suitability'));
$check('the five-minute job runs it before the assessments', strpos((string) file_get_contents(dirname(__DIR__) . '/bin/cron.php'), "'suitability' =>") < strpos((string) file_get_contents(dirname(__DIR__) . '/bin/cron.php'), "'fit' =>"));
$check('every new application is queued (CandidateImporter::attach)', str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Services/CandidateImporter.php'), 'new SuitabilityCheck($this->app))->queue($appId)'));
