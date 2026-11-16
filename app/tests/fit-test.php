<?php
/**
 * Claude's fit assessment (the hybrid): free relevance gate, Claude's judgement with fake
 * replies (no key, no network, no cost), and the app's fixed-weight scoring. LOCAL ONLY.
 * Usage: php tests/fit-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\ExperienceCheck;
use Eshcol\Ats\Services\FitAssessor;
use Eshcol\Ats\Services\Retention;
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
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$routingWas = $app->setting('routing_enabled', '1');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$ym = date('Ym');
$was = ["fit_count_$ym" => $app->setting("fit_count_$ym"), "fit_cost_$ym" => $app->setting("fit_cost_$ym"), 'fit_monthly_limit' => $app->setting('fit_monthly_limit'), "ai_cache_read_$ym" => $app->setting("ai_cache_read_$ym"), "ai_cache_write_$ym" => $app->setting("ai_cache_write_$ym"), "ai_cache_saved_$ym" => $app->setting("ai_cache_saved_$ym")];
$clientId = $db->insert('clients', ['name' => "ZZ Fit $tag", 'interview_address' => 'Office 301, Archway, Sopan Baug, Balewadi, Pune']);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $was, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    foreach ($was as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    echo "\n$ok passed, $bad failed\n";
});

$roleId = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounts Executive $tag", 'location' => 'Pune', 'status' => 'open', 'exp_min' => 2, 'exp_max' => 5,
    'score_threshold' => 50, 'code' => 'ZZF-' . random_int(1000, 9999), 'jd_text' => 'We need an Accounts Executive who files GST returns, handles TDS and keeps books in Tally.']);
foreach (['GST', 'TDS', 'Tally'] as $i => $m) {
    $db->insert('role_criteria', ['role_id' => $roleId, 'kind' => 'must', 'value' => $m, 'sort' => $i]);
}
$db->insert('role_criteria', ['role_id' => $roleId, 'kind' => 'qualification', 'value' => 'B.Com', 'sort' => 0]);
$role = $db->one('SELECT * FROM roles WHERE id = ?', [$roleId]);

$importText = function (string $name, string $text) use ($app, $uid, $roleId, &$made) {
    $tmp = $app->storage('uploads-tmp/zz-fit-' . bin2hex(random_bytes(4)) . '.txt');
    file_put_contents($tmp, $text);
    $r = (new CandidateImporter($app))->import($tmp, $name, $roleId, $uid);
    @unlink($tmp);
    $made[] = $r['candidate_id'];
    return $r;
};
$filler = str_repeat("Handled day to day work with care and attention to detail across the team. ", 6);
$acc = $importText("Asha_Rao_$tag.txt", "Asha Rao\nasha.$tag@candidates.test | +91 98" . random_int(10000000, 99999999) . " | Wakad, Pune\nAccounts Executive with 4 years of experience.\n"
    . "Accounts Executive, Shah & Co, Pune  Apr 2022 - Present\nFiled GSTR-1 and GSTR-3B monthly, TDS returns, books in Tally Prime.\nAccounts Assistant, Vardhan Traders  Jan 2021 - Mar 2022\nB.Com, Pune University 2020\n$filler");
$hr = $importText("Ravi_Hr_$tag.txt", "Ravi Kulkarni\nravi.$tag@candidates.test | +91 97" . random_int(10000000, 99999999) . " | Pune\nHR Executive with 4 years of experience.\n"
    . "HR Executive, People Co  Mar 2022 - Present\nRecruitment, onboarding, payroll inputs, employee engagement.\nHR Assistant, Talent Hub  Jan 2021 - Feb 2022\nMBA HR 2020\n$filler");
$fresher = $importText("Neha_Fresh_$tag.txt", "Neha Patil\nneha.$tag@candidates.test | +91 96" . random_int(10000000, 99999999) . " | Pune\nB.Com 2026 graduate. Knowledge of GST, TDS and Tally from coursework.\n$filler");
$appOf = fn (int $cid) => (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $roleId]);
$accApp = $appOf($acc['candidate_id']);
$hrApp = $appOf($hr['candidate_id']);
$frApp = $appOf($fresher['candidate_id']);

echo "The free gate\n";
$fit = new FitAssessor($app);
$check('an accounts CV is relevant to the accounts role', $fit->relevance($accApp) === [true, '']);
[$okHr, $whyHr] = $fit->relevance($hrApp);
$check("an HR CV is not [$whyHr]", !$okHr && str_contains($whyHr, 'different field'));
[$okFr, $whyFr] = $fit->relevance($frApp);
$check("a fresher is not, for a 2-5 year role [$whyFr]", !$okFr && str_contains($whyFr, 'experience'));
$db->run('UPDATE candidates SET unreliable_at = NOW() WHERE id = ?', [$acc['candidate_id']]);
$check('someone marked unreliable is never sent', $fit->relevance($accApp)[0] === false);
$db->run('UPDATE candidates SET unreliable_at = NULL WHERE id = ?', [$acc['candidate_id']]);
$q = $fit->queueRole($roleId);
$check('queuing the role sends only the relevant one', $q === ['queued' => 1, 'skipped' => 0, 'not_relevant' => 2]
    && (int) $db->col('SELECT COUNT(*) FROM fit_assessments WHERE role_id = ?', [$roleId]) === 1);

echo "\nLadder and requirements\n";
$l = $fit->ladder($role);
$check('default ladder for a B.Com role: M.Com / MBA higher, CA Inter etc. top', $l['base'] === 'B.Com' && str_contains($l['higher'], 'M.Com') && str_contains($l['top'], 'CA Inter') && !$l['custom']);
$check('without requirements, the must-have keywords are the checklist', array_column($fit->requirements($roleId), 'text') === ['GST', 'TDS', 'Tally']);
$v1 = $fit->roleVersion($role);
$fit->saveRequirements($roleId, [['text' => 'Files GSTR-1 and GSTR-3B independently', 'critical' => true], ['text' => 'Handles TDS returns', 'critical' => false],
    ['text' => 'Keeps books in Tally', 'critical' => false], ['text' => '', 'critical' => true]]);
$check('requirements saved (blank lines dropped), critical kept', count($fit->requirements($roleId)) === 3 && $fit->requirements($roleId)[0]['critical']);
$check('changing them changes the role version', $fit->roleVersion($role) !== $v1);

echo "\nClaude's assessment (fake reply) and the score\n";
$reply = [
    'requirements' => [['number' => 1, 'verdict' => 'met', 'evidence' => 'Filed GSTR-1 and GSTR-3B monthly', 'recency' => 'current'],
        ['number' => 2, 'verdict' => 'met', 'evidence' => 'TDS returns', 'recency' => 'current'],
        ['number' => 3, 'verdict' => 'partly', 'evidence' => 'books in Tally Prime', 'recency' => 'current']],
    'relevant_experience_months' => 55, 'relevant_experience_note' => 'About 4.5 years in accounts.', 'seniority' => 'right', 'seniority_note' => 'Executive level.',
    'qualification_level' => 'base', 'qualification_note' => 'B.Com.', 'stability' => 'good', 'stability_note' => 'Steady.', 'fit' => 'mixed', 'fit_note' => 'CA practice.',
    'red_flags' => [], 'questions' => ['Which GST returns do you file yourself?'], 'summary' => 'A solid fit.',
];
$msg = fn (array $content, string $stop = 'end_turn') => new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content,
    'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 5000, 'output_tokens' => 1500],
]));
$client = function (array $responses, ?array &$history = null) {
    $stack = HandlerStack::create(new MockHandler($responses));
    $history = [];
    $stack->push(Middleware::history($history));
    return new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]);
};
$h = [];
$fa = new FitAssessor($app, $client([$msg([['type' => 'text', 'text' => json_encode($reply)]])], $h));
$id = $fa->processNext($roleId);
$body = json_decode((string) $h[0]['request']->getBody(), true);
$blocks = (array) ($body['messages'][0]['content'] ?? []);
$sent = implode("\n", array_column($blocks, 'text'));
$check('prompt cache: the role (requirements, ladder, JD) comes first and is marked for caching; the candidate after it, not cached',
    count($blocks) === 2 && ($blocks[0]['cache_control']['type'] ?? '') === 'ephemeral' && str_contains($blocks[0]['text'], 'Requirements:')
    && !isset($blocks[1]['cache_control']) && str_contains($blocks[1]['text'], '<cv>') && !str_contains($blocks[0]['text'], '<cv>'));
$check('asks Claude Sonnet with the refusal fallback, structured', ($body['model'] ?? '') === 'claude-sonnet-5-5' && ($body['fallbacks'] ?? '') === 'default' && ($body['output_config']['format']['type'] ?? '') === 'json_schema');
$check('sends the numbered requirements, critical marked, and the ladder', str_contains($sent, '1. Files GSTR-1 and GSTR-3B independently [critical]') && str_contains($sent, 'base = B.Com'));
$check('sends the CV, and the commute only as a note', str_contains($sent, 'Shah & Co') && str_contains($sent, 'for notes only'));
$check('blind: the CV Claude assesses has no name, email or phone', !str_contains($sent, 'Asha') && !str_contains($sent, "asha.$tag") && str_contains($sent, '[phone removed]')
    && str_contains(json_encode($body['system']), 'removed from the CV on purpose'));
$check('tells Claude never to use age, gender, religion, caste…', str_contains(json_encode($body['system']), 'religion, caste'));
$f = $db->one('SELECT * FROM fit_assessments WHERE id = ?', [$id]);
// Must: (2*1 + 1*1 + 1*0.5) / 4 * 40 = 35; experience 4.58 yrs in 2-5 rises to 95.8% = 28.75; seniority 10; qualification base with tiers = 5; stability 5 + fit 2.5 = 7.5. Total 86.25 -> 86.
$check('scored with the fixed weights: 35 + 28.75 + 10 + 5 + 7.5 = 86 [' . $f['score'] . ']', (int) $f['score'] === 86 && $f['band'] === 'Strong' && $f['status'] === 'done');
$check('cost recorded and counted for the month', (float) $f['cost_usd'] > 0 && (int) $app->setting("fit_count_$ym") >= 1);
// Cost with the prompt cache: 1,000 input + 3,000 read (×0.05) + 1,000 written (×1.25) at $2, 1,500 output at $10.
$cached = (object) ['inputTokens' => 1000, 'cacheReadInputTokens' => 3000, 'cacheCreationInputTokens' => 1000, 'outputTokens' => 1500];
$savedWas = (float) $app->setting('ai_cache_saved_' . date('Ym'), '0');
[$cc, $cin] = \Eshcol\Ats\Services\CvReader::costOf($app, $cached, 'claude-sonnet-5-5', 2.0, 10.0);
$check('cost counts cache reads at 5% and writes at 125%: (1000 + 150 + 1250) × $2 + 1500 × $10 per million = $0.0198 [' . $cc . ']', abs($cc - 0.0198) < 0.000001 && $cin === 5000);
$check('…and the saving is counted for Settings: (3000 × 0.95 − 1000 × 0.25) × $2 per million = $0.0052',
    abs((float) $app->setting('ai_cache_saved_' . date('Ym')) - $savedWas - 0.0052) < 0.000001);
[$cb] = \Eshcol\Ats\Services\CvReader::costOf($app, $cached, 'claude-sonnet-5-5', 2.0, 10.0, 0.5);
$check('in a half-price batch it is all halved', abs($cb - 0.0099) < 0.000001);
$fd = $fa->forApplication($accApp);
$check('the page sees it as up to date', $fd['outdated'] === false && $fd['result']['summary'] === 'A solid fit.');
$db->run("UPDATE fit_assessments SET score = 1, band = 'Weak' WHERE id = ?", [$id]);
$h0 = count($h);
[$rn, $rch] = $fa->rescoreAll();
$check("re-scoring works it out again from Claude's stored answers, without asking Claude [$rn, $rch changed]", (int) $db->col('SELECT score FROM fit_assessments WHERE id = ?', [$id]) === 86
    && $db->col('SELECT band FROM fit_assessments WHERE id = ?', [$id]) === 'Strong' && count($h) === $h0 && $rch >= 1);

echo "\nReady to shortlist\n";
$db->update('roles', ['ctc_max_lpa' => 4.2, 'notice_max_days' => 30], $roleId);
$db->run("UPDATE candidates SET ctc_min_lpa = 3.8, notice_days = 15, location = 'Pune' WHERE id = ?", [$acc['candidate_id']]);
$rl = $fa->readyList($roleId);
$mine = fn (array $list) => array_values(array_filter($list, fn ($r) => (int) $r['candidate_id'] === (int) $acc['candidate_id']))[0] ?? null;
$r0 = $mine($rl['ready']);
$check('Strong on the CV, lowest salary 3.8 within 4.2, 15 days within 30, lives in Pune: ready to shortlist',
    $r0 && $r0['checks']['salary'][0] === 'ok' && $r0['checks']['notice'][0] === 'ok' && $r0['checks']['place'][0] === 'ok');
$db->run('UPDATE candidates SET ctc_min_lpa = 5 WHERE id = ?', [$acc['candidate_id']]);
$r1 = $mine($fa->readyList($roleId)['check']);
$check('lowest salary 5 against a 4.2 budget: "one thing to check", with the reason', $r1 && $r1['checks']['salary'] === ['no', 'lowest ' . lpa(5) . ' (budget ' . lpa(4.2) . ')']);
$db->run('UPDATE candidates SET ctc_min_lpa = NULL, ctc_expected_lpa = NULL, notice_days = NULL WHERE id = ?', [$acc['candidate_id']]);
$check('Strong, salary and notice not given yet: waiting for their details (not lost among the rest)', (bool) $mine($fa->readyList($roleId)['waiting']));
$db->run('UPDATE candidates SET ctc_min_lpa = 5, notice_days = 60 WHERE id = ?', [$acc['candidate_id']]);
$check('salary and notice both over the limit: the rest', (bool) $mine($fa->readyList($roleId)['rest']));
$db->update('roles', ['ctc_max_lpa' => null, 'notice_max_days' => null], $roleId);

echo "\nScoring rules\n";
$reqs = $fit->requirements($roleId);
$sc = fn (array $over) => FitAssessor::score($over + $reply, $reqs, $role, $l);
$check('a critical requirement not met: Weak, whatever the score', $sc(['requirements' => [['number' => 1, 'verdict' => 'not_met'] + $reply['requirements'][0]] + $reply['requirements']])['band'] === 'Weak');
$olderReqs = $reply['requirements'];
$olderReqs[1]['recency'] = 'older';
$check('when a requirement was met is shown, not scored: an older job still earns full points (35 of 40)', $sc(['requirements' => $olderReqs])['parts']['must']['points'] == 35);
$check('ladder: higher 7, top 10, below 0', $sc(['qualification_level' => 'higher'])['parts']['qualification']['points'] == 7
    && $sc(['qualification_level' => 'top'])['parts']['qualification']['points'] == 10 && $sc(['qualification_level' => 'below'])['parts']['qualification']['points'] == 0);
$check('a role asking for one level only: meeting it gives 10', FitAssessor::score($reply, $reqs, $role, ['base' => 'B.E.', 'higher' => '', 'top' => ''])['parts']['qualification']['points'] == 10);
$check('in the band it rises: 2 yrs (bottom of 2–5) 70% = 21, 5 yrs 100% = 30', $sc(['relevant_experience_months' => 24])['parts']['experience']['points'] == 21
    && $sc(['relevant_experience_months' => 60])['parts']['experience']['points'] == 30);
$check('below the band: 1.5 yrs 40% = 12, 1 yr (the allowance limit) 30% = 9', $sc(['relevant_experience_months' => 18])['parts']['experience']['points'] == 12
    && $sc(['relevant_experience_months' => 12])['parts']['experience']['points'] == 9);
$check('above the band within the allowance 100%, beyond it 30%', $sc(['relevant_experience_months' => 84])['parts']['experience']['points'] == 30
    && $sc(['relevant_experience_months' => 132])['parts']['experience']['points'] == 9);
$check('seniority: above 5, below 4', $sc(['seniority' => 'above'])['parts']['seniority']['points'] == 5 && $sc(['seniority' => 'below'])['parts']['seniority']['points'] == 4);
$check('stability and fit both poor: 0', $sc(['stability' => 'poor', 'fit' => 'poor'])['parts']['stability']['points'] == 0);
$check('commute, notice and salary are not scored', array_keys($sc([])['parts']) === ['must', 'experience', 'seniority', 'qualification', 'stability']);

echo "\nQueue behaviour\n";
$q = $fit->queueRole($roleId, true);
$check('re-assess everyone puts the done one back in the queue', $q['queued'] === 1 && $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$id]) === 'pending');
try {
    (new FitAssessor($app, $client([new Response(429, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow']]))])))->processNext($roleId);
    $err = 'done';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$check('Claude busy: it waits in the queue', str_starts_with($err, 'busy:') && $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$id]) === 'pending');
(new FitAssessor($app, $client([$msg([], 'refusal')])))->processNext($roleId);
$check('a refusal is listed as failed', $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$id]) === 'failed');
$db->run("INSERT INTO settings (k, v) VALUES ('fit_monthly_limit', '0') ON DUPLICATE KEY UPDATE v = '0'");
$db->run("UPDATE fit_assessments SET status = 'pending' WHERE id = ?", [$id]);
try {
    (new FitAssessor($app, $client([])))->processNext($roleId);
    $err = 'done';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$check('the monthly limit stops it', str_starts_with($err, 'stop:'));
$db->run("UPDATE settings SET v = '600' WHERE k = 'fit_monthly_limit'");
$check('no queuing while Claude has yet to read the CV', (function () use ($app, $db, $acc, $fit) {
    $fid = (int) $db->col('SELECT MAX(id) FROM cv_files WHERE candidate_id = ?', [$acc['candidate_id']]);
    $db->run('DELETE FROM fit_assessments WHERE candidate_id = ?', [$acc['candidate_id']]);
    (new AiReading($app))->queue($fid, (int) $acc['candidate_id']);
    $n = (new FitAssessor($app, new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000')))->queueForCandidate((int) $acc['candidate_id']);
    $db->run('DELETE FROM cv_ai_reads WHERE cv_file_id = ?', [$fid]);
    return $n === 0;
})());

echo "\nClaude drafts requirements from the JD\n";
$draft = ['requirements' => [['text' => 'Files GST returns monthly', 'critical' => true], ['text' => 'Prepares TDS returns', 'critical' => false], ['text' => 'Maintains books in Tally', 'critical' => false]]];
(new FitAssessor($app, $client([$msg([['type' => 'text', 'text' => json_encode($draft)]])])))->suggestRequirements($roleId);
$check('the draft replaces the list, critical kept', array_column($fit->requirements($roleId), 'text') === ['Files GST returns monthly', 'Prepares TDS returns', 'Maintains books in Tally'] && $fit->requirements($roleId)[0]['critical']);

echo "\nCopy the set-up from another role\n";
$db->update('roles', ['qual_ladder' => json_encode(['base' => 'B.Com', 'higher' => 'M.Com', 'top' => 'CA Inter'])], $roleId);
$mk = fn (string $title) => $db->insert('roles', ['client_id' => $clientId, 'title' => "$title $tag", 'location' => 'Chennai', 'status' => 'open', 'score_threshold' => 50, 'code' => 'ZZF-' . random_int(1000, 9999)]);
$newId = $mk('Senior Accounts Executive');
$docId = $mk('Document Controller');
$bareId = $mk('HR Executive');
$fit->saveRequirements($docId, [['text' => 'Maintains document registers in EDMS', 'critical' => false]]);
$new = $db->one('SELECT * FROM roles WHERE id = ?', [$newId]);
$src = array_column($fit->copySources($new), 'id');
$check('roles with their own set-up are offered, the most similar title first', ($src[0] ?? 0) === $roleId && in_array($docId, $src, true));
$check('roles with nothing of their own (and the role itself) are not', !in_array($bareId, $src, true) && !in_array($newId, $src, true));
$r = $fit->copyFrom($newId, $roleId);
$new = $db->one('SELECT * FROM roles WHERE id = ?', [$newId]);
$check('requirements copied in order, critical ticks kept', $r === ['reqs' => 3, 'ladder' => true]
    && $fit->requirements($newId) === $fit->requirements($roleId) && $fit->requirements($newId)[0]['critical']);
$check('the ladder copied as the role\'s own', $fit->ladder($new) === ['base' => 'B.Com', 'higher' => 'M.Com', 'top' => 'CA Inter', 'custom' => true]);
$check('the source role is untouched', count($fit->requirements($roleId)) === 3);
$fit->copyFrom($newId, $docId);
$new = $db->one('SELECT * FROM roles WHERE id = ?', [$newId]);
$check('copying a role without its own ladder keeps the ladder here', array_column($fit->requirements($newId), 'text') === ['Maintains document registers in EDMS'] && $fit->ladder($new)['top'] === 'CA Inter');
try {
    $fit->copyFrom($newId, $newId);
    $err = '';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$check('a role cannot copy from itself', $err !== '');

echo "\n\"CV looks old\" false alarm\n";
$raw = ['jobs' => [['title' => 'Accounts Executive', 'company' => 'Sosyo', 'start' => '2025-11', 'end' => 'present', 'internship' => false],
    ['title' => 'Accountant', 'company' => 'Shelke', 'start' => '2016-04', 'end' => '2025-10', 'internship' => false]]];
$check('the current job\'s start is found', AiReading::currentJobStart($raw) === '2025-11-01');
$c = ['field_sources' => '{"exp_months":"ai"}', 'exp_stated_months' => 108, 'exp_dates_months' => (int) ((date('Y') - 2016) * 12 + date('n') - 4 + 1), 'cv_has_current_job' => 1, 'cv_dated' => null, 'exp_months' => 108, 'exp_as_of' => date('Y-m-d')];
$check('before: "9+ years" with dates since 2016 looks like an old CV', ExperienceCheck::assess($c)['old'] === true);
$c['cv_not_before'] = '2025-11-01';
$x = ExperienceCheck::assess($c);
$check('after: the current job started in Nov 2025, so the CV is not called old [' . $x['label'] . ']', $x['old'] === false);
$check('…and the note no longer claims the CV was written long ago', !str_contains($x['note'], 'written') && !str_contains($x['note'], 'old'));
