<?php
/**
 * Cheaper Claude (8 Nov 2026): one assessment per candidate per client, half-price batches,
 * and Claude Haiku reading CVs that fit no open role. Fake Claude replies: no key, no network, no cost.
 * LOCAL ONLY. Usage: php tests/cost-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvReader;
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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop'); // letters only: a name with 3+ digits in a row is not taken as a name
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$ym = date('Ym');
$keys = ['fit_batch_problem_since', 'fit_batch_alerted', 'fit_batch_problem', 'routing_enabled', 'ai_read_enabled', 'ai_read_cheap', 'fit_batch', 'fit_monthly_limit', "fit_count_$ym", "fit_cost_$ym", "ai_read_count_$ym", "ai_read_cost_$ym", 'ai_read_monthly_limit'];
$was = [];
foreach ($keys as $k) {
    $was[$k] = $app->setting($k);
}
$set = fn (string $k, string $v) => $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
$set('routing_enabled', '0');
$set('ai_read_cheap', '1');
$set('ai_read_enabled', '1');
$set('fit_batch', '1');
$set('fit_monthly_limit', '600');
$set('ai_read_monthly_limit', '600');
$clientId = $db->insert('clients', ['name' => "ZZ Cost $tag", 'interview_address' => 'Balewadi, Pune']);
$otherClient = $db->insert('clients', ['name' => "ZZ Cost Other $tag"]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $otherClient, $was, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id IN (?, ?)', [$clientId, $otherClient]);
    $db->run('DELETE FROM clients WHERE id IN (?, ?)', [$clientId, $otherClient]);
    foreach ($was as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    echo "\n$ok passed, $bad failed\n";
});

$fit = new FitAssessor($app);
$mkRole = function (string $title, int $min, int $max, ?int $client = null) use ($db, $clientId, $tag, $fit) {
    $id = $db->insert('roles', ['client_id' => $client ?? $clientId, 'title' => "$title $tag", 'location' => 'Pune', 'status' => 'open', 'exp_min' => $min, 'exp_max' => $max,
        'exp_allow_below' => 1, 'exp_allow_above' => 5, 'score_threshold' => 50, 'code' => 'ZZC-' . random_int(10000, 99999), 'jd_text' => 'Files GST returns, handles TDS and keeps books in Tally.']);
    foreach (['GST', 'TDS', 'Tally'] as $i => $m) {
        $db->insert('role_criteria', ['role_id' => $id, 'kind' => 'must', 'value' => $m, 'sort' => $i]);
    }
    $fit->saveRequirements($id, [['text' => 'Files GST returns', 'critical' => false], ['text' => 'Handles TDS', 'critical' => false]]);
    return $id;
};
$assoc = $mkRole('Accounting Associate', 2, 3);
$mgr = $mkRole('Accounting Manager', 5, 10);
$otherRole = $mkRole('Accounts Executive', 2, 8, $otherClient);

$filler = str_repeat("Handled day to day accounts work with care and attention to detail across the team. ", 6);
$import = function (string $name, string $email) use ($app, $uid, &$made, $filler, $db) {
    $tmp = $app->storage('uploads-tmp/zz-cost-' . bin2hex(random_bytes(4)) . '.txt');
    file_put_contents($tmp, "$name\n$email | +91 98" . random_int(10000000, 99999999) . " | Baner, Pune\nAccountant.\n"
        . "Accountant, Shah & Co, Pune  Nov 2020 - Present\nFiled GSTR-1 and GSTR-3B monthly, TDS returns, books in Tally Prime.\nB.Com, Pune University 2020\n$filler");
    $r = (new CandidateImporter($app))->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid);
    @unlink($tmp);
    $made[] = $r['candidate_id'];
    $db->run('DELETE FROM cv_ai_reads WHERE candidate_id = ?', [$r['candidate_id']]);
    return $r;
};
$years = fn (int $cid, float $y) => $db->run('UPDATE candidates SET exp_months = ?, exp_as_of = CURDATE() WHERE id = ?', [(int) round($y * 12), $cid]);
$attach = function (int $cid, int $roleId) use ($app, $db, $uid) {
    (new CandidateImporter($app))->attach($cid, $roleId, $uid, 'database');
    return (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $roleId]);
};
$fitRow = fn (int $appId) => $db->one('SELECT * FROM fit_assessments WHERE application_id = ?', [$appId]);

echo "Choosing the one role to assess (pure)\n";
$g = [['id' => 1, 'exp_min' => '2', 'exp_max' => '3', 'fit_status' => null], ['id' => 2, 'exp_min' => '5', 'exp_max' => '10', 'fit_status' => null]];
$check('6 years: the Manager role (5–10)', FitAssessor::bestOf($g, 6.0)['id'] === 2);
$check('2.5 years: the Associate role (2–3)', FitAssessor::bestOf($g, 2.5)['id'] === 1);
$check('3.4 years: still the Associate band (half a year of grace)', FitAssessor::bestOf($g, 3.4)['id'] === 1);
$check('4 years, in neither band: the nearer one (Associate, middle 2.5)', FitAssessor::bestOf($g, 4.0)['id'] === 1);
$check('experience unknown: the most junior role', FitAssessor::bestOf($g, null)['id'] === 1);
$g[0]['fit_status'] = 'done';
$check('one already assessed wins: it is paid for', FitAssessor::bestOf($g, 6.0)['id'] === 1);
$check('a single role: nothing to choose', FitAssessor::bestOf([$g[1]], 6.0) === null);

echo "\nOne assessment per candidate per client\n";
$a = $import("Kiran Joshi $tag", "kiran.$tag@candidates.test");
$cid = (int) $a['candidate_id'];
$years($cid, 6);
$appA = $attach($cid, $assoc);
$appM = $attach($cid, $mgr);
$appO = $attach($cid, $otherRole);
$db->run('DELETE FROM fit_assessments WHERE candidate_id = ?', [$cid]);
$check('relevant to both roles by the free check', $fit->relevance($appA)[0] && $fit->relevance($appM)[0]);
$why = $fit->queue($appA);
$check("queuing the Associate role: assessed for the Manager role instead [$why]", $why === 'skipped');
$check('the Manager role is queued, the Associate skipped for it', ($fitRow($appM)['status'] ?? '') === 'pending'
    && ($fitRow($appA)['status'] ?? '') === 'skipped' && (int) $fitRow($appA)['skipped_for'] === $mgr);
$check('another client\'s role is assessed on its own', $fit->queue($appO) === '' && $fitRow($appO)['status'] === 'pending');
$q = $fit->queueRole($assoc);
$check('the role\'s button counts them as assessed elsewhere', $q === ['queued' => 0, 'skipped' => 1, 'not_relevant' => 0]);
$check('"Re-assess everyone" leaves a skipped one skipped', $fit->queue($appA, true) === 'skipped' && $fitRow($appA)['status'] === 'skipped');
$check('"Assess for this role too" queues it', $fit->queue($appA, false, true) === '' && $fitRow($appA)['status'] === 'pending' && $fitRow($appA)['skipped_for'] === null);
$db->run('DELETE FROM fit_assessments WHERE candidate_id = ?', [$cid]);
$db->run("INSERT INTO fit_assessments (application_id, candidate_id, role_id, status, score, band) VALUES (?, ?, ?, 'done', 70, 'Possible')", [$appA, $cid, $assoc]);
$check('assessed for the Associate role before: the Manager role is skipped for it', $fit->queue($appM) === 'skipped' && (int) $fitRow($appM)['skipped_for'] === $assoc);
$failStage = (int) $db->col("SELECT id FROM stages WHERE kind = 'fail' ORDER BY sort LIMIT 1");
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$failStage, $appA]);
$check('once the Associate one is Not suitable, the Manager role is assessed', $fit->queue($appM) === '' && $fitRow($appM)['status'] === 'pending');

echo "\nHalf-price batches\n";
$b = $import("Meera Shah $tag", "meera.$tag@candidates.test");
$cid2 = (int) $b['candidate_id'];
$years($cid2, 4);
$appO2 = $attach($cid2, $otherRole);
$db->run('DELETE FROM fit_assessments WHERE candidate_id = ?', [$cid2]);
$fit->queue($appO2);
$fit->queue($appO);
// Waiting: the Manager one, the other client's one (Kiran) and Meera's.
$ids = array_map('intval', array_column($db->all("SELECT id FROM fit_assessments WHERE candidate_id IN (?, ?) AND status = 'pending' ORDER BY id", [$cid, $cid2]), 'id'));
$db->run("UPDATE fit_assessments SET status = 'done' WHERE status = 'pending' AND candidate_id NOT IN (?, ?)", [$cid, $cid2]); // other local rows stay out of it
$check('three waiting', count($ids) === 3);
$batchJson = fn (string $st) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id' => 'msgbatch_zz' . $GLOBALS['tag'], 'type' => 'message_batch', 'processing_status' => $st,
    'request_counts' => ['processing' => $st === 'ended' ? 0 : 3, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
    'ended_at' => null, 'created_at' => '2026-11-08T10:00:00Z', 'expires_at' => '2026-11-09T10:00:00Z', 'archived_at' => null, 'cancel_initiated_at' => null,
    'results_url' => $st === 'ended' ? 'https://api.anthropic.com/v1/messages/batches/x/results' : null]));
$client = function (array $responses, ?array &$history = null) {
    $stack = HandlerStack::create(new MockHandler($responses));
    $history = [];
    $stack->push(Middleware::history($history));
    return new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]);
};
$h = [];
$fb = new FitAssessor($app, $client([$batchJson('in_progress')], $h));
$out = $fb->submitBatch();
$body = json_decode((string) $h[0]['request']->getBody(), true);
$req = $body['requests'][0] ?? [];
$check("sent as one batch [$out]", count($body['requests'] ?? []) === 3 && str_contains((string) $h[0]['request']->getUri(), '/v1/messages/batches'));
$check('each request is the usual assessment: Sonnet, structured', ($req['custom_id'] ?? '') === 'fit-' . $ids[0]
    && ($req['params']['model'] ?? '') === 'claude-sonnet-5-5'
    && ($req['params']['output_config']['format']['type'] ?? '') === 'json_schema' && str_contains($req['params']['messages'][0]['content'][0]['text'] ?? '', '1. Files GST returns')
    && ($req['params']['messages'][0]['content'][0]['cache_control']['type'] ?? '') === 'ephemeral');
// The Batches API rejects the refusal fallback (live, 9 Nov: every batch got 400 Bad Request).
$check('no refusal fallback in a batch (the Batches API rejects it)', !array_key_exists('fallbacks', $req['params'] ?? [])
    && !str_contains($h[0]['request']->getHeaderLine('anthropic-beta'), 'server-side-fallback'));
$check('all three marked "with Claude", with the batch id', (int) $db->col("SELECT COUNT(*) FROM fit_assessments WHERE status = 'batched' AND batch_id = ?", ["msgbatch_zz$tag"]) === 3);
$h2 = [];
$check('nothing waiting: nothing sent', (new FitAssessor($app, $client([], $h2)))->submitBatch() === '' && $h2 === []);
$check('batch still running: nothing changes', (new FitAssessor($app, $client([$batchJson('in_progress')])))->collectBatches() === ''
    && (int) $db->col("SELECT COUNT(*) FROM fit_assessments WHERE status = 'batched' AND batch_id = ?", ["msgbatch_zz$tag"]) === 3);

$reply = ['requirements' => [['number' => 1, 'verdict' => 'met', 'evidence' => 'Filed GSTR-1', 'recency' => 'current'], ['number' => 2, 'verdict' => 'met', 'evidence' => 'TDS returns', 'recency' => 'current']],
    'relevant_experience_months' => 72, 'relevant_experience_note' => '6 years.', 'seniority' => 'right', 'seniority_note' => '', 'qualification_level' => 'base', 'qualification_note' => '',
    'stability' => 'good', 'stability_note' => '', 'fit' => 'good', 'fit_note' => '', 'red_flags' => [], 'questions' => [], 'summary' => 'Good fit.'];
$lines = [
    ['custom_id' => 'fit-' . $ids[0], 'result' => ['type' => 'succeeded', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 5000, 'output_tokens' => 1500]]]],
    ['custom_id' => 'fit-' . $ids[1], 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]],
    // the third one is missing from the results
];
$jsonl = implode("\n", array_map('json_encode', $lines)) . "\n";
$costBefore = $fit->costThisMonth();
$countBefore = $fit->usedThisMonth();
$out = (new FitAssessor($app, $client([$batchJson('ended'), new Response(200, ['Content-Type' => 'application/x-jsonl'], $jsonl)])))->collectBatches();
$f0 = $db->one('SELECT * FROM fit_assessments WHERE id = ?', [$ids[0]]);
$check("the answered one is saved and scored [$out]", $f0['status'] === 'done' && (int) $f0['score'] > 0 && $f0['batch_id'] === null);
$check('at half price: (5000 × $2 + 1500 × $10) / 1M / 2 = $0.0125 [' . $f0['cost_usd'] . ']', abs((float) $f0['cost_usd'] - 0.0125) < 0.00001
    && abs($fit->costThisMonth() - $costBefore - 0.0125) < 0.00001 && $fit->usedThisMonth() === $countBefore + 1);
$check('Claude busy for one: back in the queue', $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$ids[1]]) === 'pending');
$check('one missing from the results: back in the queue too', $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$ids[2]]) === 'pending'
    && $db->col('SELECT batch_id FROM fit_assessments WHERE id = ?', [$ids[2]]) === null);
$set('fit_monthly_limit', (string) $fit->usedThisMonth());
$h3 = [];
$out = (new FitAssessor($app, $client([], $h3)))->submitBatch();
$check("the monthly limit holds them back [$out]", str_contains($out, 'limit') && $h3 === [] && $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$ids[1]]) === 'pending');
$set('fit_monthly_limit', '600');

$okMsg = fn (string $stop = 'end_turn', ?array $content = null) => new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'msg_s', 'type' => 'message', 'role' => 'assistant',
    'model' => 'claude-sonnet-5-5', 'content' => $content ?? [['type' => 'text', 'text' => json_encode($reply)]], 'stop_reason' => $stop, 'stop_sequence' => null,
    'usage' => ['input_tokens' => 5000, 'output_tokens' => 1500]]));
(new FitAssessor($app, $client([$batchJson('in_progress')])))->submitBatch();
$jsonl = json_encode(['custom_id' => 'fit-' . $ids[1], 'result' => ['type' => 'succeeded', 'message' => json_decode((string) $okMsg('refusal', [])->getBody(), true)]]) . "\n"
    . json_encode(['custom_id' => 'fit-' . $ids[2], 'result' => ['type' => 'succeeded', 'message' => json_decode((string) $okMsg()->getBody(), true)]]) . "\n";
$h7 = [];
$out = (new FitAssessor($app, $client([$batchJson('ended'), new Response(200, ['Content-Type' => 'application/x-jsonl'], $jsonl)], $h7)))->collectBatches();
$check("declined in the batch: not assessed at full price behind your back, but listed [$out]", count($h7) === 2
    && $db->col('SELECT error FROM fit_assessments WHERE id = ?', [$ids[1]]) === FitAssessor::DECLINED
    && $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$ids[2]]) === 'done');
$appOf = (int) $db->col('SELECT application_id FROM fit_assessments WHERE id = ?', [$ids[1]]);
$h8 = [];
$why = (new FitAssessor($app, $client([$okMsg()], $h8)))->assessNow($appOf);
$sync = json_decode((string) $h8[0]['request']->getBody(), true) ?: [];
$check("\"Assess now\" for it: straight away, full price, with the refusal fallback [$why]", $why === '' && $db->col('SELECT status FROM fit_assessments WHERE id = ?', [$ids[1]]) === 'done'
    && ($sync['fallbacks'] ?? '') === 'default' && abs((float) $db->col('SELECT cost_usd FROM fit_assessments WHERE id = ?', [$ids[1]]) - 0.025) < 0.00001);

$db->run("UPDATE fit_assessments SET status = 'pending', score = NULL WHERE id IN (?, ?)", [$ids[1], $ids[2]]);
$db->run("DELETE FROM email_queue WHERE kind = 'alert'");
$bad400 = fn () => new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'requests.0.params: something is not supported on the Batches API']]));
$h9 = [];
$out = (new FitAssessor($app, $client([$bad400()], $h9)))->submitBatch();
$problem = (string) $app->setting('fit_batch_problem');
$check("a batch Claude refuses: its reason is kept, not cut off [$problem]", str_contains($problem, '400: requests.0.params: something is not supported on the Batches API'));
$check("batch only: nothing is assessed at full price; they stay waiting [$out]", count($h9) === 1
    && (int) $db->col("SELECT COUNT(*) FROM fit_assessments WHERE id IN (?, ?) AND status = 'pending'", [$ids[1], $ids[2]]) === 2);
$alert = $db->one("SELECT * FROM email_queue WHERE kind = 'alert' ORDER BY id DESC LIMIT 1");
$owners = (int) $db->col("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role = 'owner'");
$check('you are emailed, with Claude\'s reason and what you can do', $owners === 0 || ($alert && str_contains((string) $alert['text'], 'something is not supported')
    && str_contains((string) $alert['text'], 'Assess now') && str_contains((string) $alert['subject'], '2 waiting')));
$check('…and My day shows it', (new FitAssessor($app))->batchAlert()[2] === 2);
(new FitAssessor($app, $client([$bad400()])))->submitBatch();
$check('refused again five minutes later: no second email', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'alert'") === min(1, $owners) * $owners);
$db->run("DELETE FROM email_queue WHERE kind = 'alert'");
$db->run("DELETE FROM settings WHERE k IN ('fit_batch_problem', 'fit_batch_problem_since', 'fit_batch_alerted')");
(new FitAssessor($app, $client([new Response(529, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]))])))->submitBatch();
$check('Claude only busy: no email for the first hour', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'alert'") === 0
    && $app->setting('fit_batch_problem') !== null && (new FitAssessor($app))->batchAlert() === null);
(new FitAssessor($app, $client([$batchJson('in_progress')])))->submitBatch();
$check('the next batch that goes through clears the warning', $app->setting('fit_batch_problem') === null && $app->setting('fit_batch_alerted') === null);
$db->run("DELETE FROM fit_assessments WHERE candidate_id IN (?, ?)", [$cid, $cid2]);

echo "\nClaude Haiku reads CVs that fit no open role\n";
$readReply = fn (string $model, array $over = []) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id' => 'msg_r', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'stop_reason' => 'end_turn', 'stop_sequence' => null,
    'usage' => ['input_tokens' => 6000, 'output_tokens' => 900],
    'content' => [['type' => 'text', 'text' => json_encode($over + ['full_name' => 'Somebody Else', 'email' => "other.$GLOBALS[tag]@candidates.test", 'phone' => '+91 90000 11111',
        'city' => 'Pune', 'locality' => 'Baner', 'current_title' => 'Accountant', 'current_company' => 'Shah & Co', 'qualifications' => ['B.Com'],
        'stated_total_experience_years' => '6', 'jobs' => [['title' => 'Accountant', 'company' => 'Shah & Co', 'start' => '2020-11', 'end' => 'present', 'internship' => false]],
        'notice_period_days' => '', 'current_ctc_lpa' => '', 'expected_ctc_lpa' => ''])]],
]));
$reader = function (array $responses, ?array &$history = null) use ($app, $client) {
    return new CvReader($app, $client($responses, $history));
};
$c = $import("Anil Rao $tag", "anil.$tag@candidates.test");
$cid3 = (int) $c['candidate_id'];
$before = $db->one('SELECT full_name, email, phone FROM candidates WHERE id = ?', [$cid3]);
$h4 = [];
$ai = new AiReading($app, $reader([$readReply('claude-haiku-5-5')], $h4));
$ai->queue((int) $c['cv_file_id'], $cid3);
$ai->processNext();
$sentModel = json_decode((string) $h4[0]['request']->getBody(), true)['model'] ?? '';
$check("on no client role: read by Haiku [$sentModel]", $sentModel === 'claude-haiku-5-5');
$after = $db->one('SELECT full_name, email, phone, exp_months FROM candidates WHERE id = ?', [$cid3]);
$check('Haiku never replaces the name, email or phone already there', $after['full_name'] === $before['full_name'] && $after['email'] === $before['email'] && $after['phone'] === $before['phone']);
$check('…but its other details are used (experience)', (int) $after['exp_months'] >= 70);
$appM3 = $attach($cid3, $mgr);
$why = (new FitAssessor($app))->queue($appM3);
$check("added to a role it fits: Sonnet reads the CV again first [$why]", $why === 'reading'
    && $db->one('SELECT status, mode FROM cv_ai_reads WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid3]) === ['status' => 'pending', 'mode' => 'auto']
    && !$fitRow($appM3));
$h5 = [];
$ai = new AiReading($app, $reader([$readReply('claude-sonnet-5-5', ['full_name' => "Anil Rao $tag", 'email' => "anil.$tag@candidates.test"])], $h5));
$ai->processNext();
$check('…and that reading is Sonnet\'s', (json_decode((string) $h5[0]['request']->getBody(), true)['model'] ?? '') === 'claude-sonnet-5-5'
    && str_contains((string) $db->col('SELECT model FROM cv_ai_reads WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid3]), 'sonnet'));
$check('then Claude assesses them', (new FitAssessor($app))->queue($appM3) === '' && $fitRow($appM3)['status'] === 'pending');

$d = $import("Sunita Pawar $tag", "sunita.$tag@candidates.test");
$cid4 = (int) $d['candidate_id'];
$years($cid4, 20); // the free reader got it wrong: outside the Manager role's allowance
$appM4 = $attach($cid4, $mgr);
$db->run('DELETE FROM fit_assessments WHERE candidate_id = ?', [$cid4]);
$h6 = [];
$ai = new AiReading($app, $reader([$readReply('claude-haiku-5-5')], $h6));
$ai->queue((int) $d['cv_file_id'], $cid4);
$ai->processNext();
$check('20 years by the free reader: not relevant, so Haiku reads it', (json_decode((string) $h6[0]['request']->getBody(), true)['model'] ?? '') === 'claude-haiku-5-5');
$check('Haiku finds 6 years, so it fits the Manager role: Sonnet is asked to read it straight away', $db->col('SELECT status FROM cv_ai_reads WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid4]) === 'pending');

$check('a scan is always read by Sonnet', $ai->readerFor(['mode' => 'auto', 'candidate_id' => $cid4], true) === 'sonnet');
$check('a re-read you asked for is read by Sonnet', $ai->readerFor(['mode' => 'review', 'candidate_id' => $cid4], false) === 'sonnet');
$set('ai_read_cheap', '0');
$check('switched off in Settings: Sonnet reads everything', $ai->readerFor(['mode' => 'auto', 'candidate_id' => $cid3 + 999999], false) === 'sonnet');
