<?php
/**
 * Claude reads every CV (Settings › AI reading), with fake Claude replies: no API key, no
 * network, no cost. LOCAL ONLY. Usage: php tests/ai-reading-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvReader;
use Eshcol\Ats\Services\CvReport;
use Eshcol\Ats\Services\ExperienceCheck;
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
$countWas = $app->setting("ai_read_count_$ym");
$costWas = $app->setting("ai_read_cost_$ym");
$limitWas = $app->setting('ai_read_monthly_limit');
// These checks are about Sonnet's reading; Haiku for CVs that fit no role is tested in cost-test.php.
$cheapWas = $app->setting('ai_read_cheap');
$db->run("INSERT INTO settings (k, v) VALUES ('ai_read_cheap', '0') ON DUPLICATE KEY UPDATE v = '0'");
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $routingWas, $ym, $countWas, $costWas, $limitWas, $cheapWas, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    foreach (["ai_read_count_$ym" => $countWas, "ai_read_cost_$ym" => $costWas, 'ai_read_monthly_limit' => $limitWas, 'ai_read_cheap' => $cheapWas] as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    echo "\n$ok passed, $bad failed\n";
});

$import = function (string $fixture, string $name) use ($app, $uid, &$made) {
    $tmp = $app->storage('uploads-tmp/zz-air-' . bin2hex(random_bytes(4)) . '.' . pathinfo($fixture, PATHINFO_EXTENSION));
    copy(__DIR__ . "/fixtures/$fixture", $tmp);
    $r = (new CandidateImporter($app))->import($tmp, $name, null, $uid);
    @unlink($tmp);
    $made[] = $r['candidate_id'];
    return $r;
};
$msg = fn (array $content, string $stop = 'end_turn') => new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content,
    'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 6000, 'output_tokens' => 900],
]));
$reply = fn (array $over = []) => $msg([['type' => 'text', 'text' => json_encode($over + [
    'full_name' => 'MR. PRIYA RAMANATHAN', 'email' => "priya.$tag@candidates.test / old.$tag@candidates.test", 'phone' => '+91 98765 ' . random_int(10000, 99999) . ' / 9000011111',
    'city' => 'Chennai', 'locality' => 'Velachery', 'current_title' => 'SENIOR ACCOUNTANT', 'current_company' => 'Sundaram Traders',
    'qualifications' => ['M.Com - Expected 2027', 'B.Com'], 'stated_total_experience_years' => '6',
    'jobs' => [['title' => 'Senior Accountant', 'company' => 'Sundaram Traders', 'start' => '2022-04', 'end' => 'present', 'internship' => false],
        ['title' => 'Accountant', 'company' => 'Lakshmi & Co', 'start' => '2019-06', 'end' => '2022-03', 'internship' => false],
        ['title' => 'Articleship', 'company' => 'KPR Associates', 'start' => '2017-01', 'end' => '2019-05', 'internship' => true]],
    'notice_period_days' => '', 'current_ctc_lpa' => '', 'expected_ctc_lpa' => '',
])]]);
$fake = function (array $responses, ?array &$history = null) use ($app) {
    $stack = HandlerStack::create(new MockHandler($responses));
    $history = [];
    $stack->push(Middleware::history($history));
    return new CvReader($app, new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]));
};

echo "A new CV, read and applied\n";
$r = $import('Priya_Ramanathan_CV.pdf', "Zz_Air_$tag.pdf");
$cid = (int) $r['candidate_id'];
$fid = (int) $r['cv_file_id'];
// As if the free reader had guessed a salary and a wrong title, and the CV arrived from an email address.
$db->run("UPDATE candidates SET ctc_current_lpa = 9, current_title = 'Lecturer', email = ?, email_norm = ?, field_sources = JSON_SET(COALESCE(field_sources, '{}'), '$.ctc_current_lpa', 'cv', '$.current_title', 'cv', '$.email', 'email') WHERE id = ?",
    ["sender.$tag@candidates.test", "sender.$tag@candidates.test", $cid]);
$ai = new AiReading($app, $fake([$reply()], $h));
$ai->queue($fid, $cid);
$check('the CV is queued once', (int) $db->col('SELECT COUNT(*) FROM cv_ai_reads WHERE cv_file_id = ?', [$fid]) === 1);
$ai->queue($fid, $cid);
$check('queuing it twice does not read it twice', (int) $db->col('SELECT COUNT(*) FROM cv_ai_reads WHERE cv_file_id = ?', [$fid]) === 1);
$id = $ai->processNext();
$body = json_decode((string) $h[0]['request']->getBody(), true);
$check('read by Claude Sonnet, with the PDF itself', ($body['model'] ?? '') === 'claude-sonnet-5-5' && ($body['messages'][0]['content'][0]['type'] ?? '') === 'document');
$check('a PDF with text is not written out again', !isset($body['output_config']['format']['schema']['properties']['cv_text']));
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$src = json_decode((string) $c['field_sources'], true);
$check('"Mr." dropped and capitals tidied in the name', $c['full_name'] === 'Priya Ramanathan' && $c['first_name'] === 'Priya');
$check('the wrong title is corrected (capitals tidied)', $c['current_title'] === 'Senior Accountant' && $src['current_title'] === 'ai');
$check('employer, city and locality filled in', $c['current_company'] === 'Sundaram Traders' && $c['location'] !== '' && $c['locality'] !== null);
$check('the free reader\'s salary guess is cleared (not in the CV)', $c['ctc_current_lpa'] === null && !isset($src['ctc_current_lpa']));
$check('the email the CV came from is kept', $c['email'] === "sender.$tag@candidates.test");
$check('only the first of two phone numbers', !str_contains((string) $c['phone'], '/') && $c['phone_norm'] !== null);
$check('experience: the stated 6 years, cross-checked against dates without the articleship', (int) $c['exp_months'] === 72 && (int) $c['exp_stated_months'] === 72
    && (int) $c['exp_dates_months'] >= 89 && (int) $c['cv_has_current_job'] === 1);
$check('qualification without "Expected 2027"', $c['qualifications'] === 'M.Com, B.Com');
$check('the reading is saved and marked applied', (bool) $db->col('SELECT applied_at FROM cv_ai_reads WHERE id = ?', [$id]));
$check('counted for the month', (int) $app->setting("ai_read_count_$ym") >= 1);
$xc = ExperienceCheck::assess($c);
$check('the experience check now uses Claude\'s reading: stated 6 yrs against the dates [' . $xc['label'] . ']', $xc['label'] === 'Consistent');
$hist = (new AiReading($app))->history($cid);
$check('the CV report history comes from Claude\'s job list', count($hist) === 3 && $hist[0]['current'] && $hist[0]['label'] === 'Senior Accountant, Sundaram Traders' && $hist[2]['education']);
$rep = (new CvReport($app))->build($cid);
$check('…and the report uses it', (bool) array_filter($rep['history'], fn ($x) => $x['label'] === 'Accountant, Lakshmi & Co'));
$check('nothing left in the queue', $ai->processNext() === null);

echo "\nPlaces\n";
$cl = (new AiReading($app))->clean(['location' => 'Chinchwad', 'locality' => 'Udyog Nagar']);
$check('a suburb given as the city: city Pune, locality the suburb [' . $cl['location'] . ' / ' . $cl['locality'] . ']', $cl['location'] === 'Pune' && $cl['locality'] === 'Chinchwad');
$cl = (new AiReading($app))->clean(['location' => 'Pune', 'locality' => 'Wakad']);
$check('a real city and known locality are left as they are', $cl['location'] === 'Pune' && $cl['locality'] === 'Wakad');
$db->run("UPDATE candidates SET locality = 'Dhankawadi', field_sources = JSON_SET(field_sources, '$.locality', 'cv') WHERE id = ?", [$cid]);
$db->run("UPDATE cv_ai_reads SET fields = JSON_SET(fields, '$.locality', 'Taljai Pathar'), mode = 'review', applied_at = NULL WHERE id = ?", [$id]);
$check('a known locality is not swapped for an area the app does not know', !isset((new AiReading($app))->proposals($id)['locality']));
$db->run("UPDATE cv_ai_reads SET mode = 'auto', applied_at = NOW() WHERE id = ?", [$id]);

echo "\nWhat is never changed\n";
$db->run("UPDATE candidates SET current_title = 'Finance Lead', field_sources = JSON_SET(field_sources, '$.current_title', 'you') WHERE id = ?", [$cid]);
$other = $db->insert('candidates', ['full_name' => "Zz Other $tag", 'phone' => '9811122233', 'phone_norm' => '9811122233', 'source' => 'manual']);
$made[] = $other;
$db->run("UPDATE cv_ai_reads SET status = 'pending', applied_at = NULL WHERE id = ?", [$id]);
(new AiReading($app, $fake([$reply(['phone' => '9811122233', 'current_title' => 'Accountant'])])))->processNext();
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('a title you confirmed stays', $c['current_title'] === 'Finance Lead');
$check('a phone already used by someone else is not taken', $c['phone_norm'] !== '9811122233');

echo "\nA scan: Claude writes the text out too\n";
$s = $import('Divya_Krishnan_scan.png', "Zz_Scan_$tag.png");
$scid = (int) $s['candidate_id'];
$sfid = (int) $s['cv_file_id'];
$scanText = "Divya Krishnan\nAccounts Executive\nTally, GST returns, TDS, bank reconciliation, MIS reports\nB.Com, Madras University\nChennai";
$h2 = [];
$ai2 = new AiReading($app, $fake([$reply(['full_name' => 'Divya Krishnan', 'email' => "divya.$tag@candidates.test", 'phone' => '', 'current_title' => 'Accounts Executive', 'cv_text' => $scanText])], $h2));
$ai2->queue($sfid, $scid);
$ai2->processNext();
$body = json_decode((string) $h2[0]['request']->getBody(), true);
$check('a scan asks Claude for the full text as well', isset($body['output_config']['format']['schema']['properties']['cv_text']));
$f = $db->one('SELECT text, text_status, text_source FROM cv_files WHERE id = ?', [$sfid]);
$check('the scan now has text, marked as written out by Claude', $f['text'] === $scanText && $f['text_status'] === 'ok' && $f['text_source'] === 'ai');
$check('skills are found in it', (bool) $db->col('SELECT COUNT(*) FROM candidate_skills WHERE candidate_id = ?', [$scid]));

echo "\nRe-reading the CVs already here, with approval\n";
$db->run("UPDATE candidates SET current_title = 'Accounts Executive', ctc_current_lpa = 7, field_sources = JSON_SET(field_sources, '$.current_title', 'cv', '$.ctc_current_lpa', 'cv') WHERE id = ?", [$cid]);
$n = (new AiReading($app))->queueAll();
$check("every candidate's latest CV is queued for review ($n)", $n >= 2 && $db->col("SELECT mode FROM cv_ai_reads WHERE cv_file_id = ?", [$fid]) === 'review');
// Only this test's two CVs are read here (others may be queued locally).
$db->run("DELETE FROM cv_ai_reads WHERE status = 'pending' AND cv_file_id NOT IN (?, ?)", [$fid, $sfid]);
$air = new AiReading($app, $fake([$reply(['current_title' => 'Senior Accountant']), $reply(['full_name' => 'Divya Krishnan', 'cv_text' => $scanText])]));
$air->processNext();
$air->processNext();
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('a re-read changes nothing before approval', $c['current_title'] === 'Accounts Executive' && (float) $c['ctc_current_lpa'] === 7.0);
$readId = (int) $db->col('SELECT id FROM cv_ai_reads WHERE cv_file_id = ?', [$fid]);
$p = (new AiReading($app))->proposals($readId);
$check('the proposed changes are listed', isset($p['current_title']) && $p['current_title']['new'] === 'Senior Accountant' && isset($p['ctc_current_lpa']) && $p['ctc_current_lpa']['new'] === '');
$applied = (new AiReading($app))->apply($readId, ['current_title']);
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('only the ticked change is applied', $applied === ['current_title'] && $c['current_title'] === 'Senior Accountant' && (float) $c['ctc_current_lpa'] === 7.0);
$check('…and the reading leaves the approval list', !array_filter((new AiReading($app))->waitingForApproval(), fn ($w) => $w['read_id'] === $readId));

echo "\nLimits and problems\n";
$db->run("INSERT INTO settings (k, v) VALUES ('ai_read_monthly_limit', '0') ON DUPLICATE KEY UPDATE v = '0'");
$db->run("UPDATE cv_ai_reads SET status = 'pending', mode = 'auto', applied_at = NULL WHERE id = ?", [$readId]);
try {
    (new AiReading($app, $fake([])))->processNext();
    $err = 'read';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$check('the monthly limit stops reading, and the CV waits', str_starts_with($err, 'stop:') && $db->col('SELECT status FROM cv_ai_reads WHERE id = ?', [$readId]) === 'pending');
$db->run("UPDATE settings SET v = '600' WHERE k = 'ai_read_monthly_limit'");
try {
    (new AiReading($app, $fake([new Response(429, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']]))])))->processNext();
    $err = 'read';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$check('Claude busy: the CV waits for the next run', str_starts_with($err, 'busy:') && $db->col('SELECT status FROM cv_ai_reads WHERE id = ?', [$readId]) === 'pending');
(new AiReading($app, $fake([$msg([], 'refusal')])))->processNext();
$check('a CV Claude declines is listed as failed', $db->col('SELECT status FROM cv_ai_reads WHERE id = ?', [$readId]) === 'failed');
$check('the five-minute job does nothing without a key', (new AiReading($app))->runBatch() === '' || \Eshcol\Ats\Secrets::has($app, 'anthropic_api_key'));
$check('a deleted candidate takes their readings with them', (function () use ($app, $db, $scid) {
    (new Retention($app))->delete($scid, 'manual', false);
    return !$db->col('SELECT COUNT(*) FROM cv_ai_reads WHERE candidate_id = ?', [$scid]);
})());
