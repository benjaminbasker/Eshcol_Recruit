<?php
/**
 * "Complete your application" (the screening questionnaire, reworked 7 Nov 2026), end to end at
 * the service level, on throwaway roles and fictional candidates. Nothing is emailed (this PC is
 * always in test mode). Cleans up after itself, even if a check crashes.
 * LOCAL ONLY. Run: php tests/questionnaire-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Questionnaires;
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
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$tag = bin2hex(random_bytes(3));
$saved = [];
foreach (['routing_enabled' => '0', 'ack_with_questions' => '1', 'ack_enabled' => '1', 'questionnaire_reminder_hours' => '48', 'questionnaire_final_hours' => '48', 'ai_read_enabled' => '0'] as $k => $v) {
    $saved[$k] = $app->setting($k);
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$made = [];
$clientId = $db->insert('clients', ['name' => "ZZ Q Test $tag"]);
$tmp = $app->storage("uploads-tmp/zz-q-$tag.txt");
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $saved, $tmp, $tag, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
    }
    $db->run('DELETE FROM suppression WHERE created_at >= NOW() - INTERVAL 5 MINUTE');
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($saved as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    @unlink($tmp);
    $left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
    echo $left ? "  FAIL clean-up left $left candidate(s)\n" : "  ok   nothing left behind\n";
    echo "\n" . ($ok + ($left ? 0 : 1)) . ' passed, ' . ($bad + ($left ? 1 : 0)) . " failed\n";
});

$role = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Officer', 'location' => 'Pune', 'work_mode' => 'hybrid', 'status' => 'open',
    'notice_max_days' => 30, 'ctc_max_lpa' => 6, 'exp_min' => 2, 'exp_max' => 5, 'score_threshold' => 0]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'Tally']);
$gst = $db->insert('role_questions', ['role_id' => $role, 'sort' => 0, 'kind' => 'yesno', 'prompt' => 'Have you filed GST returns yourself?', 'flag_answers' => json_encode(['No'])]);
$erp = $db->insert('role_questions', ['role_id' => $role, 'sort' => 1, 'kind' => 'choice', 'prompt' => 'Which accounting software do you use daily?',
    'options' => json_encode(['Tally Prime', 'SAP', 'Neither']), 'flag_answers' => json_encode(['Neither'])]);
$role2 = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Payroll Executive', 'location' => 'Pune', 'work_mode' => 'hybrid', 'status' => 'open', 'score_threshold' => 0]);
$pool = $db->insert('roles', ['client_id' => $clientId, 'title' => "ZZ Pool $tag", 'status' => 'open', 'is_pool' => 1]);
$first = (int) $db->col('SELECT id FROM stages ORDER BY sort LIMIT 1');

$imp = new CandidateImporter($app);
$person = function (string $name, ?int $roleId) use ($imp, $tmp, $uid, $tag, $db, &$made): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nMumbai\nAccounts officer, 3 years of experience in Tally and GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], $roleId ? (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $roleId]) : 0];
};
$svc = new Questionnaires($app);
$svc->dns = fn () => true; // .test domains can't resolve; pretend they can
$tokenIn = function (string $text): string {
    preg_match('#/q/([A-Za-z0-9_-]+)#', $text, $m);
    return $m[1] ?? '';
};
$tokenOf = fn (int $qid) => $tokenIn((string) $db->col('SELECT e.text FROM questionnaires q JOIN email_queue e ON e.id = q.email_id WHERE q.id = ?', [$qid]));
$cand = fn (int $id) => $db->one('SELECT * FROM candidates WHERE id = ?', [$id]);
$roleRow = fn (int $id) => $db->one('SELECT * FROM roles WHERE id = ?', [$id]);

echo "The questions\n";
$qList = $svc->questions($roleRow($role));
$keys = array_column($qList, 'key');
$check('part 1 checks their details, part 2 the details we need, part 3 the role', $keys === ['full_name', 'phone', 'email', 'linkedin', 'location', 'locality', 'current_company', 'current_title', 'experience', 'qualifications',
    'ctc_current', 'ctc_expected', 'ctc_min', 'notice', 'relocate', 'permission', "q$gst", "q$erp", 'notes']);
$check('last, an optional "Anything else you would like to share with us?"', end($qList)['prompt'] === 'Anything else you would like to share with us?' && !end($qList)['required'] && end($qList)['kind'] === 'textarea');
$req = array_column(array_filter($qList, fn ($q) => $q['required']), 'key');
$check('must be answered: name, city, locality, current and lowest salary, joining time, the role\'s questions', $req === ['full_name', 'location', 'locality', 'ctc_current', 'ctc_min', 'notice', 'relocate', 'permission', "q$gst", "q$erp"]);
$byKey = array_column($qList, null, 'key');
$check('every salary question says "in lakhs per annum"', str_contains($byKey['ctc_current']['prompt'], 'in lakhs per annum') && str_contains($byKey['ctc_expected']['prompt'], 'in lakhs per annum') && str_contains($byKey['ctc_min']['prompt'], 'in lakhs per annum'));
$check('the minimum-salary question asks for realism', str_contains($byKey['ctc_min']['prompt'], 'lowest salary') && str_contains($byKey['ctc_min']['hint'], 'realistic'));
$check('the work question names the place and says it is hybrid', str_contains($byKey['relocate']['prompt'], 'hybrid, based in Pune'));
$check('the locality question suggests the city\'s localities', in_array('Hinjewadi', $byKey['locality']['suggest'] ?? [], true));
$db->run('UPDATE roles SET screening = ? WHERE id = ?', [json_encode(['std' => ['ctc_expected', 'notice']]), $role]);
$keys = array_column(array_filter($svc->questions($roleRow($role)), fn ($q) => $q['part'] > 1), 'key');
$check('salary questions can be switched off per role; where they can work stays', $keys === ['ctc_expected', 'notice', 'relocate', 'permission', "q$gst", "q$erp", 'notes']);
$db->run('UPDATE roles SET screening = NULL WHERE id = ?', [$role]);
$general = array_column($svc->questionsFor([]), 'key');
$check('with no role: the details and the salary questions, nothing about a role', in_array('ctc_min', $general, true) && in_array('notice', $general, true) && !in_array('relocate', $general, true));

echo "\nSending on its own\n";
[$p, $pa] = $person('Pooja Qtest', $role);
$r = $svc->invite($pa, $uid);
$q = $db->one("SELECT * FROM questionnaires WHERE application_id = ? AND status = 'sent'", [$pa]);
$check('request queued', $r['status'] === 'queued' && (bool) $q);
$e = $db->one('SELECT * FROM email_queue WHERE id = ?', [$q['email_id']]);
$check('subject: "Action needed: complete your Accounts Officer (Hybrid Work) application"', $e['subject'] === 'Action needed: complete your Accounts Officer (Hybrid Work) application');
$check('names the role, the work mode and that it is our client\'s', str_contains($e['text'], 'the Accounts Officer role (Hybrid Work) with our client in Pune') && str_contains($e['html'], 'One step left, Pooja.') && str_contains($e['html'], 'Complete my application'));
$check('…says the details are critical, and ends with "the link is personal to you."', str_contains($e['text'], 'These details are critical for evaluating your application') && str_contains($e['text'], 'The link is personal to you.') && !str_contains($e['text'], 'works until'));
$check('never mentions Claude to the candidate', !str_contains($e['html'] . $e['text'], 'Claude'));
$check('no merge field left unfilled, no em dash', !str_contains($e['html'] . $e['text'], '{{') && !str_contains($e['html'] . $e['text'], '—'));
$token = $tokenOf((int) $q['id']);
$check('the code is stored only hashed and encrypted', strlen($token) >= 30 && $q['token_hash'] === hash('sha256', $token) && !str_contains($q['token_enc'], $token));
$check('the link finds it; a made-up link finds nothing', ($svc->byToken($token)['id'] ?? 0) == $q['id'] && $svc->byToken(str_repeat('x', 32)) === null && $svc->byToken('../../etc') === null);
$svc->invite($pa, $uid);
$check('sending again replaces the old link', $svc->byToken($token)['status'] === 'cancelled');
$q = $db->one("SELECT * FROM questionnaires WHERE application_id = ? AND status = 'sent'", [$pa]);
$token = $tokenOf((int) $q['id']);
$poolApp = $db->insert('applications', ['candidate_id' => $p, 'role_id' => $pool, 'stage_id' => $first]);
$check('talent pools get no request', $svc->invite($poolApp, $uid)['status'] === 'skipped');

echo "\nAnswering\n";
$q = $svc->byToken($token);
$errors = $svc->submit($q, ['full_name' => 'Pooja Qtest', 'ctc_current' => 'four', 'notice' => 'Tomorrow']);
$check('missing and wrong answers are pointed out, nothing saved', isset($errors['ctc_current'], $errors['notice'], $errors['ctc_min'], $errors['locality'], $errors['location'], $errors["q$gst"])
    && $db->col('SELECT status FROM questionnaires WHERE id = ?', [$q['id']]) === 'sent');
$check('…but optional ones are not required (expected salary, employer)', !isset($errors['ctc_expected']) && !isset($errors['current_company']) && !isset($errors['phone']));
$check('a wrong salary says "lakhs per annum"', str_contains($errors['ctc_current'], 'lakhs per annum'));
$before = $cand($p);
$answers = ['full_name' => 'Pooja R Qtest', 'phone' => (string) $before['phone'], 'email' => "pooja.new.$tag@candidates.test", 'location' => 'bangalore', 'locality' => 'whitefield',
    'current_title' => 'Senior Accountant', 'experience' => '6', 'qualifications' => 'M.Com',
    'ctc_current' => '4,5', 'ctc_expected' => '7.5', 'ctc_min' => '9', 'notice' => '8 weeks', 'relocate' => 'No', 'permission' => 'Yes', "q$gst" => 'No', "q$erp" => 'Neither'];
$check('a minimum above the hoped-for salary is queried', isset($svc->submit($q, $answers)['ctc_min']));
$answers['ctc_min'] = '6.5';
$errors = $svc->submit($q, $answers); // keep unticked
$check('valid answers accepted ("4,5" read as 4.5)', $errors === [] && $db->col('SELECT status FROM questionnaires WHERE id = ?', [$q['id']]) === 'answered');
$c = $cand($p);
$src = json_decode($c['field_sources'], true);
$check('their record holds their answers, including the minimum', (float) $c['ctc_current_lpa'] === 4.5 && (float) $c['ctc_expected_lpa'] === 7.5 && (float) $c['ctc_min_lpa'] === 6.5 && (int) $c['notice_days'] === 56);
$check('corrected details saved and marked "from the candidate" (name, title, qualification)', $c['full_name'] === 'Pooja R Qtest' && $c['current_title'] === 'Senior Accountant'
    && $c['qualifications'] === 'M.Com' && ($src['current_title'] ?? '') === 'candidate' && ($src['full_name'] ?? '') === 'candidate');
$check('place names tidied (bangalore → Bengaluru), with the locality', $c['location'] === 'Bengaluru' && $c['locality'] === 'Whitefield');
$check('a new email waits for your approval: not used yet', $c['email'] === $before['email'] && (json_decode((string) $c['pending_contact'], true)['email'] ?? '') === "pooja.new.$tag@candidates.test");
$flags = json_decode((string) $db->col('SELECT flags FROM questionnaires WHERE id = ?', [$q['id']]), true) ?: [];
$check('…and is flagged for you', (bool) array_filter($flags, fn ($f) => str_starts_with($f, 'Gave a new email')));
$check('the same mobile is not a change', !isset(json_decode((string) $c['pending_contact'], true)['phone']));
$check('budget flag uses the lowest they would accept', in_array("Won't accept less than 6.5 LPA (budget up to 6 LPA)", $flags, true));
$check('other flags: where they can work, notice, your own answers', in_array('Not able to work in Pune', $flags, true) && (bool) array_filter($flags, fn ($f) => str_starts_with($f, 'Notice 8 weeks'))
    && in_array('Have you filed GST returns yourself?: No', $flags, true) && in_array('Which accounting software do you use daily?: Neither', $flags, true));
$bd = (string) $db->col('SELECT score_breakdown FROM applications WHERE id = ?', [$pa]);
$check('the score now judges salary by the minimum', str_contains($bd, 'lowest they would accept'));
$check('all 16 answers kept (the permission question too) with the questions as asked', (int) $db->col('SELECT COUNT(*) FROM questionnaire_answers WHERE questionnaire_id = ?', [$q['id']]) === 16);
$check('their own experience figure replaces the CV\'s, marked as theirs', (int) $c['exp_months'] === 72 && ($src['exp_months'] ?? '') === 'candidate');
$check('unticking "keep my details" is recorded for you, not acted on', $c['removal_requested_at'] !== null && (bool) $db->col('SELECT id FROM candidates WHERE id = ?', [$p]));
$check('nobody is moved: still in the first stage', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$pa]) === (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1"));
$check('answering twice is not possible', $svc->invite($pa, $uid)['status'] === 'skipped');
$check('you approve the new email: now used', str_starts_with($svc->resolveContact($p, true), 'New email') && $cand($p)['email'] === "pooja.new.$tag@candidates.test" && $cand($p)['pending_contact'] === null);

echo "\nAsked once, not again for another role\n";
$db->run('UPDATE candidates SET removal_requested_at = NULL WHERE id = ?', [$p]);
$keys2 = array_column($svc->questionsFor([$roleRow($role2)], $cand($p)), 'key');
$check('salary and notice are not asked again; the details check and where they can work are', !array_intersect(['ctc_current', 'ctc_min', 'notice'], $keys2) && in_array('relocate', $keys2, true) && in_array('locality', $keys2, true));

echo "\nThe thank-you email asks for the details\n";
$ack = new Acknowledger($app);
$ack->dns = fn () => true;
[$t1, $t1a] = $person('Tara Thankyou', $role);
$r = $ack->acknowledge($t1, $role, $uid);
$mail = $db->one('SELECT * FROM email_queue WHERE id = ?', [$r['email_id'] ?? 0]);
$tq = $db->one('SELECT * FROM questionnaires WHERE application_id = ?', [$t1a]);
$check('a link is created with the thank-you email', $r['status'] === 'queued' && $tq && (int) $tq['email_id'] === (int) $mail['id']);
$check('subject: "Next step for your Accounts Officer (Hybrid Work) application"', $mail['subject'] === 'Next step for your Accounts Officer (Hybrid Work) application');
$check('the email has the action box, the button and the "critical" sentence', str_contains($mail['html'], 'One step to complete your application') && str_contains($mail['html'], 'Complete my application')
    && str_contains($mail['text'], 'These details are critical for evaluating your application'));
$check('…asks for "your current salary and the lowest salary you would accept"', str_contains($mail['text'], 'Your current salary and the lowest salary you would accept'));
$check('…stops at "The link is personal to you." (no expiry date)', str_contains($mail['text'], 'The link is personal to you.') && !str_contains($mail['text'], 'works until'));
$check('…with the working personal link, and the inbox preview line', ($svc->byToken($tokenIn($mail['text']))['id'] ?? 0) == $tq['id'] && str_contains($mail['html'], 'One step left: about two minutes'));
$db->insert('applications', ['candidate_id' => $t1, 'role_id' => $role2, 'stage_id' => $first]);
$ack->acknowledge($t1, $role2, $uid);
$mail = $db->one('SELECT * FROM email_queue WHERE id = ?', [$r['email_id']]);
preg_match_all('#/q/([A-Za-z0-9_-]+)#', $mail['text'], $mm);
$check('a second role joins the same email and the same link', count(array_unique($mm[1])) === 1 && str_contains($mail['text'], 'the Accounts Officer and Payroll Executive roles (Hybrid Work) with our client in Pune')
    && (int) $db->col('SELECT COUNT(*) FROM questionnaires WHERE parent_id = ?', [$tq['id']]) === 1);
$tRoles = $svc->openRoles((int) $tq['id']);
$tKeys = array_column($svc->questionsFor($tRoles, $cand($t1)), 'key');
$check('one page for both roles: one "where can you work" question for the one place', count(array_filter($tKeys, fn ($k) => str_starts_with($k, 'relocate'))) === 1 && in_array("q$gst", $tKeys, true));
$errs = $svc->submit($svc->byToken($tokenIn($mail['text'])), ['full_name' => 'Tara Thankyou', 'location' => 'Pune', 'locality' => 'Baner', 'ctc_current' => '4', 'ctc_min' => '4.5', 'notice' => '4 weeks',
    'relocate' => 'Yes', 'permission' => 'Yes', "q$gst" => 'Yes', "q$erp" => 'Tally Prime', 'notes' => "I took a 6-month break in 2025 to care for my mother.\nI can buy out my notice.", 'keep' => '1']);
$child = $db->one('SELECT * FROM questionnaires WHERE parent_id = ?', [$tq['id']]);
$tm = $db->one('SELECT * FROM candidate_messages WHERE candidate_id = ?', [$t1]);
$check('what they wrote in "Anything else" reaches you as a message from them', $tm && $tm['source'] === 'form' && str_contains($tm['body'], 'buy out my notice')
    && str_contains((string) $tm['subject'], 'Accounts Officer') && $tm['removal'] === 'none');
$check('answering once completes both roles', $errs === [] && $db->col('SELECT status FROM questionnaires WHERE id = ?', [$tq['id']]) === 'answered' && $child['status'] === 'answered');
$check('…each role keeps its own answers (the Payroll role gets "relocate", not the Accounts questions)', (bool) $db->col("SELECT 1 FROM questionnaire_answers WHERE questionnaire_id = ? AND qkey = 'relocate'", [$child['id']])
    && !$db->col('SELECT 1 FROM questionnaire_answers WHERE questionnaire_id = ? AND qkey = ?', [$child['id'], "q$gst"]));
$db->run("UPDATE settings SET v = '0' WHERE k = 'ack_with_questions'");
[$t2] = $person('Uma Unasked', $role);
$m2 = $db->one('SELECT * FROM email_queue WHERE id = ?', [$ack->acknowledge($t2, $role, $uid)['email_id'] ?? 0]);
$check('with the setting off, a plain thank-you email', $m2 && !str_contains($m2['html'], 'Complete my application') && !$db->col('SELECT 1 FROM questionnaires WHERE candidate_id = ?', [$t2]));
$db->run("UPDATE settings SET v = '1' WHERE k = 'ack_with_questions'");
[$n] = $person('Nora Norole', null);
$m3 = $db->one('SELECT * FROM email_queue WHERE id = ?', [$ack->acknowledge($n, null, $uid)['email_id'] ?? 0]);
$nq = $db->one('SELECT * FROM questionnaires WHERE candidate_id = ?', [$n]);
$check('a CV with no role also gets the link ("a role with an Eshcol Global client")', $m3 && str_contains($m3['html'], 'Complete my application') && $nq && $nq['application_id'] === null
    && str_contains($m3['text'], 'a role with an Eshcol Global client') && $m3['subject'] === "We've received your CV: one step left");
$errs = $svc->submit($svc->byToken($tokenIn($m3['text'])), ['full_name' => 'Nora Norole', 'location' => 'Pune', 'locality' => 'Kothrud', 'ctc_current' => '3', 'ctc_min' => '3.5', 'notice' => 'Immediate joiner', 'keep' => '1']);
$check('…and their answers update the record', $errs === [] && (float) $cand($n)['ctc_min_lpa'] === 3.5 && (int) $cand($n)['notice_days'] === 0 && $cand($n)['locality'] === 'Kothrud');

echo "\nThe thank-you email waits while the CV is being read\n";
[$w] = $person('Wasim Waiting', $role);
$wr = $ack->acknowledge($w, $role, $uid);
$cvf = (int) $db->col('SELECT id FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$w]);
$db->run("INSERT INTO cv_ai_reads (cv_file_id, candidate_id, mode, status) VALUES (?, ?, 'auto', 'pending')", [$cvf, $w]);
$db->run('UPDATE email_queue SET not_before = NOW() - INTERVAL 1 MINUTE, priority = 0 WHERE id = ?', [$wr['email_id']]);
$sentIds = [];
$mailer = new Mailer($app);
$mailer->transport = function (array $row) use (&$sentIds) { $sentIds[] = (int) $row['id']; return 'test:' . $row['id']; };
$mailer->processQueue(200);
$check('not sent while the reading is pending', !in_array((int) $wr['email_id'], $sentIds, true) && strtotime((string) $db->col('SELECT not_before FROM email_queue WHERE id = ?', [$wr['email_id']])) > time() + 60);
$db->run("UPDATE cv_ai_reads SET status = 'done' WHERE candidate_id = ?", [$w]);
$db->run('UPDATE email_queue SET not_before = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$wr['email_id']]);
$mailer->processQueue(200);
$check('sent once the CV has been read', in_array((int) $wr['email_id'], $sentIds, true));

echo "\nReminder after 48 hours, final email 48 hours later\n";
[$f1, $f1a] = $person('Farah Followup', $role);
$fr = $ack->acknowledge($f1, $role, $uid);
$fq = $db->one('SELECT * FROM questionnaires WHERE application_id = ?', [$f1a]);
$db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW() - INTERVAL 47 HOUR WHERE id = ?", [$fr['email_id']]);
$svc->housekeeping(11);
$fEmails = fn () => $db->all("SELECT * FROM email_queue WHERE candidate_id = ? AND kind = 'questionnaire' ORDER BY id", [$f1]);
$check('no reminder before 48 hours', !$fEmails());
$db->run('UPDATE email_queue SET sent_at = NOW() - INTERVAL 49 HOUR WHERE id = ?', [$fr['email_id']]);
$svc->housekeeping(22);
$check('none at night (10 pm)', !$fEmails());
$log = $svc->housekeeping(11);
$rem = $fEmails()[0] ?? null;
$check('after 48 hours, in the day, a gentle reminder: ' . $log, $rem && $rem['subject'] === 'Reminder: complete your Accounts Officer (Hybrid Work) application' && str_contains($rem['html'], 'Just one step left, Farah.'));
$check('…with the same link, ending "The link is personal to you."', $rem && $tokenIn($rem['text']) === $tokenIn((string) $db->col('SELECT text FROM email_queue WHERE id = ?', [$fr['email_id']])) && str_contains($rem['text'], 'The link is personal to you.'));
$check('…and only one reminder', !str_contains($svc->housekeeping(11), '1 reminder'));
$db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$rem['id']]);
$db->run('UPDATE questionnaires SET reminded_at = NOW() - INTERVAL 47 HOUR WHERE id = ?', [$fq['id']]);
$svc->housekeeping(11);
$check('no final email before another 48 hours', count($fEmails()) === 1);
$db->run('UPDATE questionnaires SET reminded_at = NOW() - INTERVAL 49 HOUR WHERE id = ?', [$fq['id']]);
$svc->housekeeping(11);
$fin = $fEmails()[1] ?? null;
$check('then the final email: "Are you still interested in the Accounts Officer role?"', $fin && $fin['subject'] === 'Are you still interested in the Accounts Officer role?' && str_contains($fin['html'], 'Are you still interested, Farah?'));
$check('…with "Yes, complete my application" and a one-click "I\'m no longer interested"', $fin && str_contains($fin['html'], 'Yes, complete my application') && str_contains($fin['html'], "I&apos;m no longer interested") && preg_match('#/q/[A-Za-z0-9_-]+/no#', $fin['text']));
$check('the link stays open 14 days after the final email (Benjamin, 17 Nov 2026)', $db->col('SELECT expires_at FROM questionnaires WHERE id = ?', [$fq['id']]) >= date('Y-m-d 23:59:59', strtotime('+14 days'))
    && Questionnaires::openUntil('2026-11-17 15:10:00') === '2026-12-01 23:59:59');
$db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$fin['id']]);
$db->run('UPDATE questionnaires SET final_at = NOW() - INTERVAL 10 DAY WHERE id = ?', [$fq['id']]);
$svc->housekeeping(11);
$check('nothing more after the final email', count($fEmails()) === 2);

echo "\nI'm no longer interested\n";
$db->insert('applications', ['candidate_id' => $f1, 'role_id' => $role2, 'stage_id' => $first]);
$svc->attach((int) $fq['id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$f1, $role2]));
$pending = $svc->email((int) $fq['id'], 'reminder', null); // pretend one more was waiting
$svc->decline($svc->byToken($tokenIn((string) $fin['text'])));
$check('both roles closed as "declined"', (int) $db->col("SELECT COUNT(*) FROM questionnaires WHERE (id = ? OR parent_id = ?) AND status = 'declined'", [$fq['id'], $fq['id']]) === 2);
$check('…moved to Withdrew on each role, with a note', (int) $db->col("SELECT COUNT(*) FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.candidate_id = ? AND s.name LIKE 'Withdr%'", [$f1]) === 2
    && (bool) $db->col("SELECT 1 FROM stage_history h JOIN applications a ON a.id = h.application_id WHERE a.candidate_id = ? AND h.note LIKE '%no longer interested%'", [$f1]));
$check('…and any email still waiting is cancelled', $db->col('SELECT status FROM email_queue WHERE id = ?', [$pending]) === 'cancelled');
$check('the link now says it is noted', $svc->byToken($tokenIn((string) $fin['text']))['status'] === 'declined');

echo "\nThe one-time request to people already on roles\n";
[$o1, $o1a] = $person('Omar Onetime', $role);
$o1b = $db->insert('applications', ['candidate_id' => $o1, 'role_id' => $role2, 'stage_id' => $first]);
$list = array_column(array_map(fn ($x) => $x + ['cid' => $x['candidate']['id']], $svc->askList()), null, 'cid');
$check('one row per person, with both their roles', isset($list[$o1]) && count($list[$o1]['apps']) === 2 && !$list[$o1]['asked']);
$check('people already asked are marked so', ($list[$t1]['asked'] ?? null) === 'answered');
$r = $svc->inviteCandidate($o1, [$o1a, $o1b], $uid);
$oe = $db->one("SELECT * FROM email_queue WHERE candidate_id = ? AND kind = 'questionnaire' ORDER BY id DESC LIMIT 1", [$o1]);
$check('one email naming both roles, one link', $r['status'] === 'queued' && $oe['subject'] === 'Action needed: complete your application'
    && str_contains($oe['text'], 'the Accounts Officer and Payroll Executive roles (Hybrid Work) with our client in Pune') && str_contains($oe['text'], 'One short page covers both roles.'));
$check('…the person now shows as asked', (string) (array_column(array_map(fn ($x) => $x + ['cid' => $x['candidate']['id']], $svc->askList()), null, 'cid')[$o1]['asked'] ?? '') === 'sent');

echo "\nThe responses summary\n";
$resp = $svc->responses();
$mine = fn (int $cid) => array_values(array_filter($resp['rows'], fn ($r) => (int) $r['candidate_id'] === $cid));
$check('an answer shows what they told us: salary, joining, where they live, can they work there', ($t = $mine($t1)[0] ?? null) && $t['status'] === 'answered'
    && (float) $t['ctc_min_lpa'] === 4.5 && $t['notice_label'] === '4 weeks' && $t['locality'] === 'Baner' && $t['relocate'] === 'Yes');
$check('…both roles of a person who answered once', count($mine($t1)) === 2 && !array_filter($mine($t1), fn ($r) => $r['status'] !== 'answered'));
$check('the over-budget flag is on the row', ($p0 = $mine($p)[0] ?? null) && in_array("Won't accept less than 6.5 LPA (budget up to 6 LPA)", $p0['flags'], true));
$pos = array_flip(array_map(fn ($r) => (int) $r['candidate_id'] . ':' . $r['status'], $resp['rows']));
$check('within budget comes before flagged, answers before waiting', ($pos["$t1:answered"] ?? 99) < ($pos["$p:answered"] ?? 0) && ($pos["$p:answered"] ?? 99) < ($pos["$o1:sent"] ?? 0));
$check('someone who said no shows as no longer interested', ($mine($f1)[0]['status'] ?? '') === 'declined');
$check('the counts are per person', $resp['counts']['asked'] >= 4 && $resp['counts']['answered'] >= 2 && $resp['counts']['declined'] >= 1 && $resp['counts']['within_budget'] >= 1);
$ns = (int) $db->col("SELECT id FROM stages WHERE kind = 'fail' AND name LIKE 'Not suit%' LIMIT 1");
$tApp = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$t1, $role]);
$db->run('UPDATE applications SET stage_id = ?, stage_reason = ? WHERE id = ?', [$ns, 'Qualification below the requirement: BBM and MBA; minimum is B.Com', $tApp]);
$tRow = array_values(array_filter($svc->responses()['rows'], fn ($r) => (int) $r['candidate_id'] === $t1 && (int) $r['role_id'] === $role))[0] ?? null;
$check('someone marked not suitable shows the stage and your reason on the responses', $tRow && $tRow['stage_kind'] === 'fail' && $tRow['stage_reason'] === 'Qualification below the requirement: BBM and MBA; minimum is B.Com');
$fr = (int) $db->col("SELECT id FROM stages WHERE kind = 'hold' AND name = 'Future requirement' LIMIT 1");
$check('there is a "Future requirement" stage, kept for later (not a rejection)', $fr > 0);
$db->run('UPDATE applications SET stage_id = ?, stage_reason = ? WHERE id = ?', [$fr, 'Good accountant, expects 6 LPA against a 4.2 budget', $tApp]);
$tRow = array_values(array_filter($svc->responses()['rows'], fn ($r) => (int) $r['candidate_id'] === $t1 && (int) $r['role_id'] === $role))[0] ?? null;
$check('…shown with your note on the responses', $tRow && $tRow['stage_kind'] === 'hold' && $tRow['stage_name'] === 'Future requirement' && str_contains((string) $tRow['stage_reason'], '4.2 budget'));
$check('…and Claude does not assess them again for that role', !(int) $db->col("SELECT COUNT(*) FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ? AND s.kind NOT IN ('fail','hold')", [$tApp]));
echo "\nWaiting for your review\n";
$pendIds = fn () => array_map(fn ($r) => (int) $r['candidate_id'] . ':' . (int) $r['application_id'], $svc->pendingReviews(1000));
$p2App = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$t1, $role2]);
$check('a completed application waits for your review (whatever its age)', in_array("$t1:$p2App", $pendIds(), true));
$check('…but not once it is kept for a future requirement or not suitable', !in_array("$t1:$tApp", $pendIds(), true));
$db->run("UPDATE questionnaires SET answered_at = NOW() - INTERVAL 20 DAY WHERE application_id = ?", [$p2App]);
$check('…still there after three days (it waits until you act)', in_array("$t1:$p2App", $pendIds(), true));
$svc->markReviewed($p2App, $t1, $uid);
$check('once you act on it, it leaves the list', !in_array("$t1:$p2App", $pendIds(), true) && $db->col('SELECT reviewed_at FROM questionnaires WHERE application_id = ?', [$p2App]) !== null);
$check('details given with no role wait too, until marked reviewed', in_array("$n:0", $pendIds(), true));
$svc->markReviewed(null, $n, $uid);
$check('…and leave the list once marked', !in_array("$n:0", $pendIds(), true));
[$z, $za] = $person('Zara Nilnote', $role);
$zq = $svc->create($za, $uid);
$svc->submit($svc->byToken((string) \Eshcol\Ats\Secrets::open($app, (string) $db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$zq]), 'questionnaire')),
    ['full_name' => 'Zara Nilnote', 'location' => 'Pune', 'locality' => 'Aundh', 'ctc_current' => '3', 'ctc_min' => '3.5', 'notice' => '2 weeks', 'relocate' => 'Yes', 'permission' => 'Yes', "q$gst" => 'Yes', "q$erp" => 'SAP', 'notes' => 'Nil.', 'keep' => '1']);
$check('"Nil" in "Anything else" is not sent to you as a message', $db->col('SELECT status FROM questionnaires WHERE id = ?', [$zq]) === 'answered' && !$db->col('SELECT COUNT(*) FROM candidate_messages WHERE candidate_id = ?', [$z]));

echo "\nAt a glance (the Responses dashboard)\n";
$before = $svc->dashboard();
$leadOf = function (int $cid) use ($db, $svc, $uid): array {
    $l = $db->one('SELECT id, email_id FROM questionnaires WHERE candidate_id = ? AND parent_id IS NULL AND status <> \'cancelled\' ORDER BY id DESC LIMIT 1', [$cid]);
    if (!$l) {
        $a0 = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? LIMIT 1', [$cid]); $id = $svc->create($a0 ?: null, $uid, $a0 ? null : $cid);
        $l = ['id' => $id, 'email_id' => null];
    }
    if (!$l['email_id']) {
        $l['email_id'] = $db->insert('email_queue', ['kind' => 'ack', 'candidate_id' => $cid, 'to_email' => "x$cid@candidates.test", 'subject' => 'Next step', 'status' => 'pending', 'priority' => 50, 'not_before' => date('Y-m-d H:i:s')]);
        $db->run('UPDATE questionnaires SET email_id = ? WHERE id = ?', [$l['email_id'], $l['id']]);
    }
    return $l;
};
$g = [];
foreach (['Gita Glance', 'Hari Glance', 'Isha Glance', 'Jai Glance', 'Kavi Glance'] as $i => $nm) {
    [$gc] = $person($nm, $role);
    $g[$i] = [$gc, $leadOf($gc)];
    if ($i < 4) { // the fifth one's email is still in the queue
        $db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$g[$i][1]['email_id']]);
    }
}
$qOf = fn (int $i) => $svc->byToken((string) \Eshcol\Ats\Secrets::open($app, (string) $db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$g[$i][1]['id']]), 'questionnaire'));
$svc->submit($qOf(0), ['full_name' => 'Gita Glance', 'location' => 'Pune', 'locality' => 'Baner', 'ctc_current' => '3', 'ctc_min' => '3.5', 'notice' => '2 weeks', 'relocate' => 'Yes', 'permission' => 'Yes', "q$gst" => 'Yes', "q$erp" => 'SAP', 'keep' => '1']);
$svc->decline($qOf(1));
$svc->markOpened($qOf(2));
$db->run('UPDATE questionnaires SET reminded_at = NOW() - INTERVAL 1 MINUTE WHERE id = ? OR parent_id = ?', [$g[3][1]['id'], $g[3][1]['id']]);
$after = $svc->dashboard();
$dl = fn (string $k) => $after[$k] - $before[$k];
$check('sent counts emails that went out; one still in the queue is shown apart [sent +' . $dl('sent') . ', queued +' . $dl('queued') . ']', $dl('sent') === 4 && $dl('queued') === 1);
$check('answered +1, no longer interested +1, opened +3 (answering or declining counts as opened)', $dl('answered') === 1 && $dl('declined') === 1 && $dl('opened') === 3);
$check('no answer yet +2: one not reminded yet, one reminded', $dl('waiting') === 2 && $after['stage']['first'] - $before['stage']['first'] === 1 && $after['stage']['reminded'] - $before['stage']['reminded'] === 1);
$check('the one who answered gave salary, lowest salary and joining time', $after['gave']['all'] - $before['gave']['all'] === 1 && $after['after']['first'] - $before['after']['first'] === 1);
$rowOf = fn (array $d) => array_values(array_filter($d['roles'], fn ($r) => str_starts_with($r['title'], 'Accounts Officer')))[0] ?? ['on' => 0, 'asked' => 0, 'answered' => 0, 'declined' => 0, 'within' => 0];
$ra = $rowOf($after);
$rb = $rowOf($before);
$check('by role: asked +4, answered +1 (within the 6 LPA budget), not interested +1', $ra['asked'] - $rb['asked'] === 4 && $ra['answered'] - $rb['answered'] === 1 && $ra['within'] - $rb['within'] === 1 && $ra['declined'] - $rb['declined'] === 1);
$today = array_values(array_filter($after['days'], fn ($r) => $r['day'] === date('Y-m-d')))[0] ?? ['sent' => 0];
$todayB = array_values(array_filter($before['days'], fn ($r) => $r['day'] === date('Y-m-d')))[0] ?? ['sent' => 0];
$check('by day sent: today +4', $today['sent'] - $todayB['sent'] === 4);
$check('a person counts once, whatever number of requests they had', $after['people'] - $before['people'] === 5);
[$lc] = $person('Lila Glance', null);
$ll = $leadOf($lc);
$db->run("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$ll['email_id']]);
$lq = $svc->byToken((string) \Eshcol\Ats\Secrets::open($app, (string) $db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$ll['id']]), 'questionnaire'));
$svc->submit($lq, ['full_name' => 'Lila Glance', 'location' => 'Pune', 'locality' => 'Aundh', 'ctc_current' => '4', 'ctc_min' => '7', 'notice' => '2 weeks', 'keep' => '1']);
$imp->attach($lc, $role, $uid, 'database');
$r2 = $rowOf($svc->dashboard());
$check('by role counts everyone on the role, even when their request came with no role (CV put on the role later)', $r2['on'] - $ra['on'] === 1
    && $r2['asked'] - $ra['asked'] === 1 && $r2['answered'] - $ra['answered'] === 1 && $r2['within'] === $ra['within']);

echo "\nExpiry and deleting a candidate\n";
$db->run('UPDATE questionnaires SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE candidate_id = ?', [$o1]);
$check('an old link expires', $svc->byToken($tokenIn($oe['text']))['status'] === 'expired');
(new Retention($app))->delete($p, 'requested', true);
$check('their questionnaires and answers go with them', !(int) $db->col('SELECT COUNT(*) FROM questionnaires WHERE candidate_id = ?', [$p])
    && !(int) $db->col('SELECT COUNT(*) FROM questionnaire_answers WHERE questionnaire_id = ?', [$q['id']]));
// clean-up and the totals run from the shutdown function above
exit(0);
