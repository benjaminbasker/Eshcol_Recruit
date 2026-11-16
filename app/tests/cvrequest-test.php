<?php
/**
 * "Share your updated CV" (14 Nov 2026): groups in Benjamin's order, 175 a day (its own allowance), 9 am to 7 pm,
 * reminders (day 3 for all, day 7 for group 1) before new emails, no-answer handling, the three answers, the
 * updated CV landing on the right person, and the email wording. Throwaway data, cleaned up. LOCAL ONLY.
 * Run: php tests/cvrequest-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvRequests;
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
$saved = [];
foreach (['cvreq_daily_cap', 'cvreq_reminders_g1', 'cvreq_reminders_other', 'cvreq_paused', 'routing_enabled'] as $k) {
    $saved[$k] = $app->setting($k);
}
$set = function (string $k, string $v) use ($db) {
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
};
$set('routing_enabled', '0');
// Only this test's requests: park anything else waiting (none expected locally) so the daily counts are ours.
$others = array_map('intval', array_column($db->all("SELECT id FROM cv_requests WHERE status IN ('queued','sent')"), 'id'));
if ($others) {
    $db->run("UPDATE cv_requests SET status = 'cancelled' WHERE id IN (" . implode(',', $others) . ')');
}
$client = $db->insert('clients', ['name' => "ZZ CvReq $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Officer ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0,
    'function_codes' => 'ACC', 'location' => 'Pune']);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $saved, $set, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM upload_items WHERE batch_id = ?', [$GLOBALS['zzBatch'] ?? 0]);
    $db->run('DELETE FROM upload_batches WHERE id = ?', [$GLOBALS['zzBatch'] ?? 0]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    foreach ($saved as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $set($k, (string) $v);
    }
    echo "\n$ok passed, $bad failed\n";
});
$set('cvreq_daily_cap', '2');
$set('cvreq_reminders_g1', '3,7');
$set('cvreq_reminders_other', '3');
$set('cvreq_paused', '0');

$batch = $GLOBALS['zzBatch'] = $db->insert('upload_batches', ['user_id' => $uid, 'role_id' => null, 'channel' => 'indeed', 'label' => "ZZ old CVs $tag", 'send_ack' => 0]);
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-cvreq-' . getmypid() . '.txt');
$person = function (string $name, ?string $code, string $city, bool $email = true) use ($db, $imp, $tmp, $uid, $tag, $batch, &$made): int {
    $mail = $email ? strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test | " : '';
    file_put_contents($tmp, "$name\n$mail+91 9" . random_int(100000000, 999999999) . "\n$city\nAccountant, 2021 – Present\n4 years of experience, Tally, GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid, 'upload', 'indeed');
    $cid = (int) $r['candidate_id'];
    $made[] = $cid;
    $db->insert('upload_items', ['batch_id' => $batch, 'filename' => "$name.txt", 'outcome' => 'new', 'candidate_id' => $cid]);
    if ($code) {
        $db->run('UPDATE candidate_categories SET is_current = 0 WHERE candidate_id = ?', [$cid]);
        $db->insert('candidate_categories', ['candidate_id' => $cid, 'function_code' => $code, 'current_city' => $city, 'source' => 'recruiter']);
    }
    $db->run("INSERT INTO categorise_queue (candidate_id, status) VALUES (?, 'done') ON DUPLICATE KEY UPDATE status = 'done'", [$cid]); // finished rows stay, as on live
    $db->run("UPDATE cv_ai_reads SET status = 'done' WHERE candidate_id = ? AND status IN ('pending','reading')", [$cid]);
    return $cid;
};
$stage = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' ORDER BY sort LIMIT 1");
$assess = function (int $cid, string $band, int $score) use ($db, $role, $stage): void {
    $app = $db->insert('applications', ['role_id' => $role, 'candidate_id' => $cid, 'stage_id' => $stage]);
    $db->insert('fit_assessments', ['application_id' => $app, 'candidate_id' => $cid, 'role_id' => $role, 'status' => 'done', 'score' => $score, 'band' => $band]);
};
$strong = $person('Asha Strongfit', 'ACC', 'Pune');
$assess($strong, 'Strong', 82);
$possible = $person('Bala Possiblefit', 'ACC', 'Pune');
$assess($possible, 'Possible', 64);
$weak = $person('Chitra Weakfit', 'ACC', 'Pune');
$assess($weak, 'Weak', 40);
$elsewhere = $person('Deepak Otherplace', 'ACC', 'Chennai');
$sales = $person('Esha Salesperson', 'SAL', 'Pune');
$noMail = $person('Farid Nomail', 'ACC', 'Pune', false);
$dnc = $person('Gita Donotcontact', 'ACC', 'Pune');
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$dnc]);
@unlink($tmp);

$now = strtotime('2026-11-16 10:00:00');
$svc = new CvRequests($app);
$svc->clock = function () use (&$now) { return $now; };

echo "Who gets it, in your order\n";
$plan = $svc->plan([$batch]);
$ids = fn (int $g) => array_map(fn ($r) => (int) $r[0], $plan['groups'][$g]);
$check('group 1 = Strong then Possible on an open position, best first', $ids(1) === [$strong, $possible]);
$check('group 2 = right field but not a likely fit (weak, or another city)', count(array_intersect($ids(2), [$weak, $elsewhere])) === 2 && count($ids(2)) === 2);
$check('group 3 = other fields', $ids(3) === [$sales]);
$check('finished CVs do not count as "still being given a field"', $plan['waiting']['categorising'] === 0);
$check('left out: no email, and do-not-contact', ($plan['excluded']['No email address'] ?? 0) === 1 && ($plan['excluded']['Do not contact, or asked to be removed'] ?? 0) === 1);
$added = $svc->start([$batch], $uid);
$check('starting puts all 5 in the queue, nothing sent yet', $added === [1 => 2, 2 => 2, 3 => 1]
    && !$db->col("SELECT 1 FROM email_queue WHERE kind = 'cv_request' AND candidate_id IN ($strong,$possible,$weak,$elsewhere,$sales)"));
$check('a second start for the same upload adds nobody', array_sum($svc->start([$batch], $uid)) === 0);

echo "\nSending: 2 a day here (175 live), 9 am to 7 pm\n";
$now = strtotime('2026-11-16 08:30:00');
$check('before 9 am: nothing goes', $svc->tick() === '' && $svc->usedToday() === 0);
$now = strtotime('2026-11-16 10:00:00');
$svc->tick();
$sentTo = fn () => array_map('intval', array_column($db->all("SELECT candidate_id FROM email_queue WHERE kind = 'cv_request' AND candidate_id IN ($strong,$possible,$weak,$elsewhere,$sales) ORDER BY id"), 'candidate_id'));
$check('the daily allowance goes to group 1 first', $sentTo() === [$strong, $possible]);
$svc->tick();
$check('a second run the same day sends no more (emails waiting in the queue count too)', count($sentTo()) === 2);
$row = $db->one("SELECT * FROM email_queue WHERE kind = 'cv_request' AND candidate_id = ?", [$strong]);
$check('lowest priority, so other email always goes first', (int) $row['priority'] === 95);
$check('group 1 subject and wording: "on Indeed", "matches a current requirement"', $row['subject'] === 'Thank you for your application on Indeed'
    && str_contains($row['text'], 'it matches a current requirement of one of our clients'));
$check('the email has the three answers and the LinkedIn button', str_contains($row['html'], 'Share my updated CV') && str_contains($row['html'], 'keep me for the future')
    && str_contains($row['html'], 'Remove my details') && str_contains($row['html'], 'Follow us on LinkedIn'));
$check('never names Claude or AI', !preg_match('/\bClaude\b|\bAI\b/', $row['text']));
$db->run("UPDATE email_queue SET status = 'sent', sent_at = ? WHERE kind = 'cv_request' AND candidate_id IN ($strong,$possible)", [date('Y-m-d H:i:s', $now)]);

$now = strtotime('2026-11-17 10:00:00');
$svc->tick();
$check('next day: group 2 (weak and other city) follow', count(array_intersect($sentTo(), [$weak, $elsewhere])) === 2 && count($sentTo()) === 4);
$g2 = $db->one("SELECT * FROM email_queue WHERE kind = 'cv_request' AND candidate_id = ?", [$elsewhere]);
$check('groups 2 and 3 say "current and upcoming requirements", not "matches"', str_contains($g2['text'], 'current and upcoming requirements') && !str_contains($g2['text'], 'it matches a current'));
$db->run("UPDATE email_queue SET status = 'sent', sent_at = ? WHERE kind = 'cv_request' AND status = 'pending' AND candidate_id IN ($weak,$elsewhere)", [date('Y-m-d H:i:s', $now)]);

echo "\nReminders before new emails\n";
$now = strtotime('2026-11-19 10:30:00'); // day 3 for group 1
$svc->tick();
$rem = $db->all("SELECT candidate_id, subject FROM email_queue WHERE kind = 'cv_request' AND subject LIKE 'Reminder:%' AND candidate_id IN ($strong,$possible)");
$check('day 3: both group 1 reminders go, ahead of group 3\'s first email', count($rem) === 2 && !in_array($sales, $sentTo(), true));
$db->run("UPDATE email_queue SET status = 'sent', sent_at = ? WHERE kind = 'cv_request' AND status = 'pending'", [date('Y-m-d H:i:s', $now)]);
$check('the reminder is worded as one', str_contains((string) $db->col("SELECT text FROM email_queue WHERE kind = 'cv_request' AND subject LIKE 'Reminder:%' AND candidate_id = ?", [$strong]), "haven't heard back"));

echo "\nThe answers\n";
$req = fn (int $cid) => $db->one('SELECT * FROM cv_requests WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
$tok = fn (array $r) => \Eshcol\Ats\Secrets::open($app, (string) $r['token_enc'], 'cv_request');
$check('the personal link opens; a made-up one does not', $svc->byToken($tok($req($strong))) !== null && $svc->byToken(str_repeat('x', 32)) === null);
$check('a queued (not yet sent) request has no working link', $svc->byToken($tok($req($sales))) === null);
$svc->answer($req($possible), 'keep');
$check('"keep me for the future": recorded, consent given, no more reminders', $req($possible)['answer'] === 'keep' && $req($possible)['next_at'] === null
    && $db->col("SELECT status FROM consents WHERE candidate_id = ? ORDER BY id DESC LIMIT 1", [$possible]) === 'given');
$db->insert('email_queue', ['kind' => 'questionnaire', 'candidate_id' => $weak, 'to_email' => 'x@candidates.test', 'subject' => 'x', 'status' => 'pending']);
$svc->answer($req($weak), 'remove');
$c = $db->one('SELECT removal_requested_at FROM candidates WHERE id = ?', [$weak]);
$check('"remove my details": flagged for you to delete, anything waiting to them cancelled', $c['removal_requested_at'] !== null
    && !$db->col("SELECT 1 FROM email_queue WHERE candidate_id = ? AND status = 'pending'", [$weak])
    && $db->col("SELECT status FROM consents WHERE candidate_id = ? ORDER BY id DESC LIMIT 1", [$weak]) === 'withdrawn');
// Their updated CV, with a NEW email address in it, still lands on them (not a new person).
file_put_contents($tmp, "Asha Strongfit\nasha.new.$tag@elsewhere.test | +91 9" . random_int(100000000, 999999999) . "\nPune\nSenior Accountant, 2024 – Present\n5 years of experience, Tally, GST, SAP.\nB.Com, CA Inter");
$r = $imp->importFor($strong, $tmp, 'Asha_CV_2026.txt', $uid);
@unlink($tmp);
$check('an updated CV with a new address lands on the same person', (int) $r['candidate_id'] === $strong && $r['outcome'] === 'updated');
$svc->cvReceived($req($strong));
$svc->answer($req($strong), 'share');
$link = $svc->detailsLink($req($strong));
$q = $db->one('SELECT * FROM questionnaires WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$strong]);
$check('"share": then a details link on their position (group 1)', str_contains($link, '/q/') && $q && (int) $q['role_id'] === $role);

echo "\nNo answer\n";
$now = strtotime('2026-11-20 10:00:00'); // day 3 for group 2
$svc->tick();
$db->run("UPDATE email_queue SET status = 'sent', sent_at = ? WHERE kind = 'cv_request' AND status = 'pending'", [date('Y-m-d H:i:s', $now)]);
$check('day 3 for group 2: its single reminder', (int) $req($elsewhere)['reminders'] === 1);
$now = strtotime('2026-11-24 11:00:00'); // day 7 after group 2's first email: a week after its last reminder is day 10
$svc->tick();
$check('group 2 is not closed before a week after its last reminder', $req($elsewhere)['status'] === 'sent');
$now = strtotime('2026-11-27 11:00:00');
$svc->tick();
$check('then: no answer', $req($elsewhere)['status'] === 'no_answer' && CvRequests::silenced($app, $elsewhere));
$check('before 14 May 2027, no answer does not shorten how long we keep them', (string) $db->col('SELECT purge_after FROM candidates WHERE id = ?', [$elsewhere]) > '2027-01-01');
CvRequests::reapplied($app, $elsewhere);
$check('if they apply again, "no answer" no longer holds', !CvRequests::silenced($app, $elsewhere));

echo "\nFrom 14 May 2027 (DPDP consent rules)\n";
$db->run("UPDATE cv_requests SET status = 'sent', next_at = ?, reminders = 2 WHERE id = ?", ['2027-05-20 09:00:00', $req($possible)['id']]);
$db->run("UPDATE cv_requests SET answer = NULL WHERE id = ?", [$req($possible)['id']]);
$now = strtotime('2027-05-20 09:30:00');
$svc->tick();
$check('no answer from then on: due for deletion 30 days later', $req($possible)['status'] === 'no_answer' && $db->col('SELECT purge_after FROM candidates WHERE id = ?', [$possible]) === '2027-06-19');

echo "\nPause\n";
$set('cvreq_paused', '1');
$now = strtotime('2026-11-28 10:00:00');
$before = count($sentTo());
$svc->tick();
$check('paused: nothing new goes', count($sentTo()) === $before);
$set('cvreq_paused', '0');
$svc->tick();
$check('resumed: group 3 gets its turn', in_array($sales, $sentTo(), true));
$db->run("DELETE FROM email_queue WHERE kind IN ('cv_request','questionnaire') AND candidate_id IN ($strong,$possible,$weak,$elsewhere,$sales)");
