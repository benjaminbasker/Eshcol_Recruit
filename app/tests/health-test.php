<?php
/**
 * Bounces (B1), consent (B2) and scheduled jobs (B8), 14 Nov 2026, on fictional candidates:
 *  - Brevo's webhook: only with the secret; events recorded on the email and the candidate; repeats ignored;
 *  - a hard bounce, block, spam complaint or unsubscribe stops all email to the address; 3 soft bounces
 *    in 30 days count as a hard one; one soft bounce doesn't stop anything; you can clear it;
 *  - consent: the thank-you email records the notice; the details form records yes or no; "Please remove
 *    me" and a spam complaint record a withdrawal; the export holds everything about the person;
 *  - jobs: failures in a row are counted; two in a row show on My day.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/health-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Consents;
use Eshcol\Ats\Services\EmailHealth;
use Eshcol\Ats\Services\JobsHealth;
use Eshcol\Ats\Services\Mailer;
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
$was = [];
foreach (['brevo_webhook_token', 'routing_enabled', 'category_mode', 'ai_read_enabled', 'ack_enabled'] as $k) {
    $was[$k] = $app->setting($k);
}
foreach (['routing_enabled' => '0', 'category_mode' => 'off', 'ai_read_enabled' => '0', 'ack_enabled' => '1'] as $k => $v) {
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$made = [];
$taskName = "zz_task_$tag";
register_shutdown_function(function () use ($app, $db, &$made, $was, $tag, $taskName, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM email_events WHERE to_email LIKE ?', ["%.$tag@candidates.test"]);
    $db->run('DELETE FROM email_queue WHERE to_email LIKE ?', ["%.$tag@candidates.test"]);
    $db->run('DELETE FROM job_runs WHERE task = ?', [$taskName]);
    foreach ($was as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-health-' . getmypid() . '.txt');
$person = function (string $name) use ($imp, $tmp, $uid, $tag, &$made): int {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccountant, Shah Traders, 2021 – Present\n4 years of experience in accounts, Tally, GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid);
    $made[] = (int) $r['candidate_id'];
    return (int) $r['candidate_id'];
};
$h = new EmailHealth($app);
$m = new Mailer($app);
$queue = fn (int $cid, string $to) => $m->enqueue(['kind' => 'message', 'candidate_id' => $cid, 'to_email' => $to, 'subject' => "Hello $tag", 'text' => 'Hello', 'html' => '<p>Hello</p>']);
$sendNow = function (int $id) use ($db, $m): string {
    $m->sendRow($db->one('SELECT * FROM email_queue WHERE id = ?', [$id]));
    return (string) $db->col('SELECT status FROM email_queue WHERE id = ?', [$id]);
};

echo "The webhook's secret\n";
$token = $h->token();
$check('a long secret is made once and kept', strlen($token) >= 40 && $h->token() === $token);
$check('the webhook address carries it', str_ends_with($h->webhookUrl(), "/hooks/brevo/$token"));
$check('only the right secret gets in', $h->validToken($token) && !$h->validToken('x' . substr($token, 1)) && !$h->validToken(''));

echo "\nBounces stop the emails\n";
$asha = $person('Asha Bouncetest');
$aEmail = "asha.$tag@candidates.test";
$e1 = $queue($asha, $aEmail);
$check('a working address is emailed', $sendNow($e1) === 'sent');
$pid = (string) $db->col('SELECT provider_id FROM email_queue WHERE id = ?', [$e1]);
$check('a delivery is noted on the email only', $h->record(['event' => 'delivered', 'email' => $aEmail, 'message-id' => $pid, 'ts_event' => time()]) === 1
    && $db->col('SELECT delivery FROM email_queue WHERE id = ?', [$e1]) === 'delivered' && $db->col('SELECT email_status FROM candidates WHERE id = ?', [$asha]) === 'ok');
$check('a hard bounce (Brevo\'s spelling) marks the candidate', $h->record(['event' => 'hard_bounce', 'email' => strtoupper($aEmail), 'message-id' => $pid, 'reason' => '550 mailbox does not exist', 'ts_event' => time()]) === 1
    && $db->col('SELECT email_status FROM candidates WHERE id = ?', [$asha]) === 'hard_bounce');
$check('…the same event again is ignored', $h->record(['event' => 'hard_bounce', 'email' => $aEmail, 'message-id' => $pid, 'reason' => '550 mailbox does not exist', 'ts_event' => (int) strtotime((string) $db->col("SELECT MAX(event_at) FROM email_events WHERE to_email = ?", [$aEmail]))]) === 0);
$e2 = $queue($asha, $aEmail);
$check('…and the next email to that address is cancelled, with the reason', $sendNow($e2) === 'cancelled' && str_contains((string) $db->col('SELECT last_error FROM email_queue WHERE id = ?', [$e2]), 'does not work'));
$e3 = $m->enqueue(['kind' => 'alert', 'to_email' => $aEmail, 'subject' => "Alert $tag", 'text' => 'x']);
$check('…even an email not tied to the candidate', $sendNow($e3) === 'cancelled');
$h->clear($asha);
$check('you can clear it, and emails go again', $sendNow($queue($asha, $aEmail)) === 'sent');

$bala = $person('Bala Softtest');
$bEmail = "bala.$tag@candidates.test";
$h->record(['event' => 'softBounce', 'email' => $bEmail, 'reason' => 'mailbox full', 'ts_event' => time() - 7200]);
$check('one soft bounce is noted, but the address is still emailed', $db->col('SELECT email_status FROM candidates WHERE id = ?', [$bala]) === 'soft_bounce' && $sendNow($queue($bala, $bEmail)) === 'sent');
$h->record(['event' => 'soft_bounce', 'email' => $bEmail, 'reason' => 'mailbox full', 'ts_event' => time() - 3600]);
$h->record(['event' => 'soft_bounce', 'email' => $bEmail, 'reason' => 'mailbox full', 'ts_event' => time()]);
$check('three in 30 days count as a hard bounce', $db->col('SELECT email_status FROM candidates WHERE id = ?', [$bala]) === 'hard_bounce'
    && str_contains((string) $db->col('SELECT email_status_note FROM candidates WHERE id = ?', [$bala]), '3 times'));

echo "\nComplaints and unsubscribes\n";
$chitra = $person('Chitra Spamtest');
$cEmail = "chitra.$tag@candidates.test";
$check('a list of events is taken in one go', $h->record([['event' => 'spam', 'email' => $cEmail, 'ts_event' => time()], ['event' => 'nonsense', 'email' => $cEmail], ['event' => 'blocked', 'email' => 'not-an-email']]) === 1);
$check('a spam complaint stops all email', $db->col('SELECT email_status FROM candidates WHERE id = ?', [$chitra]) === 'complained' && $sendNow($queue($chitra, $cEmail)) === 'cancelled');
$check('…and records that they withdrew consent', (new Consents($app))->current($chitra)['status'] === 'withdrawn' && (new Consents($app))->current($chitra)['via'] === 'spam_complaint');
$h->record(['event' => 'soft_bounce', 'email' => $cEmail, 'ts_event' => time()]);
$check('a later soft bounce never undoes a stop', $db->col('SELECT email_status FROM candidates WHERE id = ?', [$chitra]) === 'complained');
$dina = $person('Dina Unsubtest');
$h->record(['event' => 'unsubscribed', 'email' => "dina.$tag@candidates.test", 'ts_event' => time()]);
$check('unsubscribed: stopped and withdrawn', $db->col('SELECT email_status FROM candidates WHERE id = ?', [$dina]) === 'unsubscribed' && (new Consents($app))->current($dina)['via'] === 'unsubscribed');
$check('a test email to you is always sent', $sendNow($m->enqueue(['kind' => 'test', 'to_email' => $cEmail, 'subject' => "Test $tag", 'text' => 'x'])) === 'sent');
$sum = $h->summary();
$check('Settings counts them', $sum['stopped'] >= 3 && ($sum['events']['complained'] ?? 0) >= 1);

echo "\nConsent\n";
$ela = $person('Ela Consenttest');
$k = new Consents($app);
$check('nothing recorded for a CV added without a thank-you', $k->current($ela) === null);
(new Acknowledger($app))->acknowledge($ela, null, $uid);
$cur = $k->current($ela);
$check('the thank-you email records the notice, with its version', $cur && $cur['status'] === 'notice_sent' && $cur['via'] === 'ack_email' && $cur['notice_version'] === Consents::NOTICE_VERSION && $cur['email_id']);
$k->record($ela, 'notice_sent', 'ack_email');
$check('the same notice twice is recorded once', count($k->history($ela)) === 1);
$k->record($ela, 'given', 'details_form');
$check('"Keep my details on file" ticked: agreed', $k->current($ela)['status'] === 'given' && count($k->history($ela)) === 2);
$k->record($ela, 'withdrawn', 'removal_request');
$check('"Please remove me": withdrawn, and the history kept', $k->current($ela)['status'] === 'withdrawn' && count($k->history($ela)) === 3);
$x = $k->export($ela);
$check('the export holds their details, CV names, emails and consents', $x && $x['details']['full_name'] === 'Ela Consenttest' && count($x['cv_files']) === 1 && count($x['consents']) === 3
    && !isset($x['details']['email_norm']) && $x['reference'] === ref_candidate($ela));
$check('…and is valid JSON', json_decode((string) json_encode($x), true) !== null);
$s = $k->summary();
$check('Settings counts where people stand', isset($s['none']) && ($s['withdrawn'] ?? 0) >= 1);

echo "\nScheduled jobs\n";
$jh = new JobsHealth($app);
$db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, NOW() - INTERVAL 15 MINUTE, 1.2, 1, ?)', [$taskName, '3 sent']);
$check('a run that worked is not failing', ($jh->tasks()[$taskName]['failing'] ?? -1) === 0);
$db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, NOW() - INTERVAL 10 MINUTE, 0.4, 0, ?)', [$taskName, 'Could not reach Brevo']);
$check('one failure is counted, not yet on My day', ($jh->tasks()[$taskName]['failing'] ?? 0) === 1 && !isset($jh->failing()[$taskName]));
$db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, NOW() - INTERVAL 5 MINUTE, 0.4, 0, ?)', [$taskName, 'Could not reach Brevo']);
$check('two in a row show on My day, with the reason', isset($jh->failing()[$taskName]) && $jh->failing()[$taskName]['last']['message'] === 'Could not reach Brevo');
$db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, NOW(), 0.9, 1, NULL)', [$taskName]);
$check('a run that works again clears it', ($jh->tasks()[$taskName]['failing'] ?? 1) === 0 && !isset($jh->failing()[$taskName]));
$check('the queues are counted', array_key_exists('Emails waiting to send', $jh->queues()));
@unlink($tmp);
