<?php
/**
 * CVs emailed to resume@: a fake Microsoft Graph answers, so this needs no mailbox.
 * LOCAL ONLY. Usage: php tests/mail-intake-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\MailIntake;
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
$tag = bin2hex(random_bytes(3));
$code4 = (string) random_int(1000, 9999);
$keep = [];
foreach (['mail_intake_on', 'mail_intake_since', 'mail_intake_ack', 'mail_intake_last_ok', 'routing_enabled', 'secret_mail_intake'] as $k) {
    $keep[$k] = $app->setting($k);
}
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
$maxIntake = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM mail_intake');
$clientId = $db->insert('clients', ['name' => "ZZ Mail $tag"]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $keep, $maxEmail, $maxIntake, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique(array_filter($made)) as $id) {
        $ret->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM email_queue WHERE id > ?', [$maxEmail]);
    $db->run('DELETE FROM mail_intake WHERE id > ?', [$maxIntake]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($keep as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
    echo "\n$ok passed, $bad failed\n";
});
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");

$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounts Associate $tag", 'location' => 'Pune', 'status' => 'open', 'code' => "ZZM-$code4", 'score_threshold' => 40]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'GST']);

$cv = fn (string $name, string $email) => "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccounts associate with 3 years of experience in GST, TDS and Tally.\nHandled monthly GST returns, TDS filings, bank reconciliations and MIS reports for SME clients.\nB.Com, Savitribai Phule Pune University";
$att = fn (string $name, string $bytes, bool $inline = false) => ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $name, 'isInline' => $inline, 'contentBytes' => base64_encode($bytes)];
$t0 = time() - 3600;
$msgs = [
    ['id' => 'm1', 'internetMessageId' => "<m1-$tag@x>", 'subject' => "Application: ZZM-$code4 Accounts Associate", 'from' => ['emailAddress' => ['address' => "anita.$tag@candidates.test", 'name' => 'Anita Rao']],
     'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0), 'hasAttachments' => true, 'categories' => [],
     'att' => [$att('Anita_Rao_CV.txt', $cv('Anita Rao', "anita.$tag@candidates.test")), $att('logo.png', str_repeat('x', 900), true)]],
    ['id' => 'm2', 'internetMessageId' => "<m2-$tag@x>", 'subject' => 'Looking for a job', 'from' => ['emailAddress' => ['address' => "bala.$tag@candidates.test", 'name' => 'Bala']],
     'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 60), 'hasAttachments' => false, 'categories' => []],
    ['id' => 'm3', 'internetMessageId' => "<m3-$tag@x>", 'subject' => 'CV for any accounts opening', 'from' => ['emailAddress' => ['address' => "chitra.$tag@candidates.test", 'name' => 'Chitra']],
     'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 120), 'hasAttachments' => true, 'categories' => ['Blue'],
     'att' => [$att('Chitra_Nair.txt', str_replace("chitra.$tag@candidates.test | ", '', $cv('Chitra Nair', "chitra.$tag@candidates.test")))]],
    ['id' => 'm4', 'internetMessageId' => "<m4-$tag@x>", 'subject' => "New application for ZZM-$code4", 'from' => ['emailAddress' => ['address' => 'noreply@indeed.com', 'name' => 'Indeed']],
     'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 180), 'hasAttachments' => true, 'categories' => [],
     'att' => [$att('Deepak_K.txt', str_replace("deepak.$tag@candidates.test | ", '', $cv('Deepak Kulkarni', "deepak.$tag@candidates.test")))]],
];
$calls = [];
$tokenOk = true;
$http = function (string $method, string $url, array $headers, ?string $body) use (&$msgs, &$calls, &$tokenOk): array {
    $calls[] = [$method, $url, $body];
    if (str_contains($url, 'login.microsoftonline.com')) {
        return $tokenOk ? [200, json_encode(['access_token' => 'tok'])] : [401, json_encode(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: x"])];
    }
    if (str_contains($url, '/users/nobody%40')) {
        return [404, json_encode(['error' => ['message' => 'The mailbox is either inactive, soft-deleted, or is hosted on-premise.']])];
    }
    if (preg_match('#/messages/(m\d)/attachments$#', $url, $m)) {
        $msg = array_values(array_filter($msgs, fn ($x) => $x['id'] === $m[1]))[0];
        return [200, json_encode(['value' => $msg['att'] ?? []])];
    }
    if ($method === 'PATCH') {
        return [200, '{}'];
    }
    if (str_contains($url, '/mailFolders/inbox/messages')) {
        return [200, json_encode(['value' => array_map(fn ($x) => array_diff_key($x, ['att' => 1]), $msgs)])];
    }
    if (str_contains($url, '/mailFolders/inbox')) {
        return [200, json_encode(['totalItemCount' => 12, 'unreadItemCount' => 3])];
    }
    return [500, '{}'];
};
$cfg = ['tenant_id' => '11111111-2222-3333-4444-555555555555', 'client_id' => '66666666-7777-8888-9999-000000000000', 'client_secret' => 'not-a-real-secret-for-tests-only', 'mailbox' => 'resume@eshcolglobal.com'];

echo "Connecting\n";
$mi = new MailIntake($app, $http);
$check('a working connection reports the inbox', $mi->test($cfg) === 'Connected to resume@eshcolglobal.com: 12 emails in the Inbox (3 unread).');
$tokenOk = false;
try { (new MailIntake($app, $http))->test($cfg); $msg = 'accepted'; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
$check('a wrong secret is explained [' . $msg . ']', str_contains($msg, 'Microsoft refused the sign-in details') && str_contains($msg, 'Invalid client secret') && !str_contains($msg, 'Trace'));
$tokenOk = true;
try { (new MailIntake($app, $http))->test(['mailbox' => 'nobody@eshcolglobal.com'] + $cfg); $msg = 'accepted'; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
$check('an address that is not a mailbox is explained', str_contains($msg, "can't find the mailbox nobody@eshcolglobal.com"));
$check('not connected: the scheduled run does nothing', (new MailIntake($app, $http))->run() === 'skipped (CV inbox not connected)');

echo "\nBringing CVs in\n";
Secrets::put($app, 'mail_intake', json_encode($cfg));
foreach (['mail_intake_on' => '1', 'mail_intake_ack' => '1', 'mail_intake_since' => gmdate('Y-m-d\TH:i:s\Z', $t0 - 60)] as $k => $v) {
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$check('the role code is read from the subject', $mi->roleFromSubject("Fwd: zzm - $code4 accounts") === $role && $mi->roleFromSubject('Hello') === null);
$calls = [];
$summary = (new MailIntake($app, $http))->run();
echo "    $summary\n";
$rows = $db->all('SELECT * FROM mail_intake WHERE id > ? ORDER BY id', [$maxIntake]);
$by = array_column($rows, null, 'from_email');
foreach ($rows as $r) { foreach (explode(',', (string) $r['candidate_ids']) as $c) { $made[] = (int) $c; } }
$check('4 emails: 3 CVs imported, 1 without a CV', $summary === '4 new email(s): 3 CV(s) imported, 0 without a CV, 0 could not be read' ? false : str_starts_with($summary, '4 new email(s): 3 CV(s) imported, 1 without a CV'));
$anita = (int) ($by["anita.$tag@candidates.test"]['candidate_ids'] ?? 0);
$check('the CV with the code in the subject is on that role', $anita && $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$anita, $role]));
$check('…and its source is "email"', $db->col('SELECT source FROM applications WHERE candidate_id = ? AND role_id = ?', [$anita, $role]) === 'email');
$check('the signature logo was not taken for a CV', count(explode(',', (string) $by["anita.$tag@candidates.test"]['candidate_ids'])) === 1 && !str_contains((string) $by["anita.$tag@candidates.test"]['detail'], 'logo.png'));
$check('the email without an attachment is noted as "no CV"', ($by["bala.$tag@candidates.test"]['outcome'] ?? '') === 'no_cv');
$chitra = (int) ($by["chitra.$tag@candidates.test"]['candidate_ids'] ?? 0);
$check('no code in the subject: still imported, on no client role', $chitra && !$db->col('SELECT COUNT(*) FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND r.is_pool = 0', [$chitra]));
$check('a CV without an email address takes the sender\'s', $db->col('SELECT email FROM candidates WHERE id = ?', [$chitra]) === "chitra.$tag@candidates.test");
$deepak = (int) ($by['noreply@indeed.com']['candidate_ids'] ?? 0);
$check('…but never a job board\'s no-reply address', $deepak && !$db->col('SELECT email FROM candidates WHERE id = ?', [$deepak]));
$check('thank-you emails queued for the new candidates (not for one without an address)', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE id > ? AND kind = 'ack'", [$maxEmail]) === 2);
$patches = array_values(array_filter($calls, fn ($c) => $c[0] === 'PATCH'));
$check('handled emails get the Outlook category, keeping existing ones', count($patches) === 3 && str_contains($patches[1][2], '"Blue"') && str_contains($patches[1][2], MailIntake::CATEGORY));
$check('nothing is moved or deleted in the mailbox', !array_filter($calls, fn ($c) => $c[0] === 'DELETE' || str_contains($c[1], '/move')));
$check('the next run starts from the newest email seen', $app->setting('mail_intake_since') === $msgs[3]['receivedDateTime']);

echo "\nRunning again\n";
$summary = (new MailIntake($app, $http))->run();
$check('the same emails are never imported twice', str_starts_with($summary, '0 new email(s)') && (int) $db->col('SELECT COUNT(*) FROM mail_intake WHERE id > ?', [$maxIntake]) === 4);
$msgs[] = ['id' => 'm5', 'internetMessageId' => "<m5-$tag@x>", 'subject' => "Re: ZZM-$code4", 'from' => ['emailAddress' => ['address' => "anita.$tag@candidates.test", 'name' => 'Anita Rao']],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 600), 'hasAttachments' => true, 'categories' => [],
    'att' => [$att('Anita_Rao_CV.txt', $cv('Anita Rao', "anita.$tag@candidates.test"))]];
$q0 = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE id > ? AND kind = 'ack'", [$maxEmail]);
$summary = (new MailIntake($app, $http))->run();
$check('a CV sent twice is recognised, with no second thank-you', str_starts_with($summary, '1 new email(s): 1 CV(s)') && (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE id > ? AND kind = 'ack'", [$maxEmail]) === $q0
    && (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm = ?', ["anita.$tag@candidates.test"]) === 1);
echo "\nReplies from candidates we already have\n";
$msgs[] = ['id' => 'm7', 'internetMessageId' => "<m7-$tag@x>", 'subject' => 'Re: Next step for your Accounts Associate application', 'from' => ['emailAddress' => ['address' => "anita.$tag@candidates.test", 'name' => 'Anita Rao']],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 700), 'hasAttachments' => false, 'categories' => [],
    'body' => ['contentType' => 'text', 'content' => "Hello Sir,\r\nMy notice period is 30 days but I can join in 15 days if needed.\r\n\r\nRegards\r\nAnita\r\n\r\nOn Sat, 7 Nov 2026 at 10:05 PM Eshcol Global <resume@eshcolglobal.com> wrote:\r\n> Thank you, Anita.\r\n> Your application has reached us."]];
$msgs[] = ['id' => 'm8', 'internetMessageId' => "<m8-$tag@x>", 'subject' => 'RE: Next step for your Accounts Associate application', 'from' => ['emailAddress' => ['address' => "ANITA.$tag@candidates.test", 'name' => 'Anita Rao']],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 720), 'hasAttachments' => false, 'categories' => [],
    'body' => ['contentType' => 'text', 'content' => "Please remove me from your list.\r\n\r\n________________________________\r\nFrom: Eshcol Global <resume@eshcolglobal.com>\r\nSent: 07 November 2026 22:05\r\nSubject: Next step"]];
$msgs[] = ['id' => 'm9', 'internetMessageId' => "<m9-$tag@x>", 'subject' => 'Query', 'from' => ['emailAddress' => ['address' => "stranger.$tag@candidates.test", 'name' => 'Someone']],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 740), 'hasAttachments' => false, 'categories' => [], 'body' => ['content' => 'Do you have any jobs in Chennai?']];
$calls = [];
$summary = (new MailIntake($app, $http))->run();
$cm = $db->all('SELECT * FROM candidate_messages WHERE candidate_id = ? ORDER BY id', [$anita]);
$check('a reply from a known candidate is kept as a message on their record: ' . $summary, count($cm) === 2 && str_contains($summary, '2 message(s) from candidates'));
$check('…only their own words: the quoted email below is taken off', str_starts_with($cm[0]['body'], 'Hello Sir,') && str_contains($cm[0]['body'], 'join in 15 days') && !str_contains($cm[0]['body'], 'wrote:') && !str_contains($cm[0]['body'], 'Thank you, Anita'));
$check('…with the subject, sender and date', $cm[0]['subject'] === 'Re: Next step for your Accounts Associate application' && $cm[0]['from_email'] === "anita.$tag@candidates.test" && $cm[0]['source'] === 'email' && $cm[0]['read_at'] === null);
$check('"Please remove me" is flagged for you, and nothing is done by itself', $cm[1]['removal'] === 'asked' && trim($cm[1]['body']) === 'Please remove me from your list.' && $db->col('SELECT removal_requested_at FROM candidates WHERE id = ?', [$anita]) === null);
$check('an unknown sender without a CV is still only "no CV"', $db->col('SELECT outcome FROM mail_intake WHERE id > ? AND from_email = ?', [$maxIntake, "stranger.$tag@candidates.test"]) === 'no_cv');
$check('the inbox log says "message" and the email gets the Outlook category', $db->col('SELECT outcome FROM mail_intake WHERE id > ? AND message_key = ?', [$maxIntake, hash('sha256', "<m7-$tag@x>")]) === 'message'
    && count(array_filter($calls, fn ($c) => $c[0] === 'PATCH')) === 2);
$check('the text body is asked for (not HTML)', (bool) array_filter($calls, fn ($c) => str_contains($c[1], 'inbox/messages') && str_contains(rawurldecode($c[1]), 'body')));
$svcM = new Eshcol\Ats\Services\CandidateMessages($app);
$svcM->resolveRemoval((int) $cm[1]['id'], true);
$check('you confirm the removal request: they are marked "asked not to be kept"', $db->col('SELECT removal_requested_at FROM candidates WHERE id = ?', [$anita]) !== null
    && $db->col('SELECT removal FROM candidate_messages WHERE id = ?', [$cm[1]['id']]) === 'confirmed');
$check('HTML email bodies are turned into plain text', Eshcol\Ats\Services\CandidateMessages::clean('<html><body><p>Hi,</p><p>I can join &amp; start soon.</p></body></html>') === "Hi,\nI can join & start soon.");

echo "\nTwo-way: you reply from the app, their answer comes back\n";
$db->run('UPDATE candidates SET removal_requested_at = NULL WHERE id = ?', [$anita]);
$check('their message keeps its email ID for threading', $db->col('SELECT message_ref FROM candidate_messages WHERE id = ?', [$cm[0]['id']]) === "<m7-$tag@x>");
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$svcM = new Eshcol\Ats\Services\CandidateMessages($app);
$r = $svcM->send($anita, $uid, '', "Dear Anita,\n\nThank you. 15 days works for our client.\n\nCould you share your current CTC?", (int) $cm[0]['id']);
$out = $db->one("SELECT * FROM candidate_messages WHERE candidate_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 1", [$anita]);
$em = $db->one('SELECT * FROM email_queue WHERE id = ?', [$out['email_id'] ?? 0]);
$check('your reply is sent straight away and kept in the conversation: ' . $r['message'], $r['ok'] && $em && $em['status'] === 'sent' && $em['kind'] === 'message' && $out['read_at'] !== null);
$check('…as "Re:" their subject, to the address they wrote from', $em['subject'] === 'Re: Next step for your Accounts Associate application' && $em['to_email'] === "anita.$tag@candidates.test");
$check('…looks like a personal email: your words, your signature, their message quoted', str_contains($em['html'], 'Thank you. 15 days works') && str_contains($em['text'], 'Regards,')
    && str_contains($em['text'], '> My notice period is 30 days') && str_contains($em['text'], 'wrote:'));
$hd = (new Eshcol\Ats\Services\Mailer($app))->headersFor($em);
$check('…threaded to their email, and not marked automatic', ($hd['In-Reply-To'] ?? '') === "<m7-$tag@x>" && ($hd['References'] ?? '') === "<m7-$tag@x>" && !isset($hd['Auto-Submitted']));
$check('automatic emails still say so', isset((new Eshcol\Ats\Services\Mailer($app))->headersFor(['meta' => null])['Auto-Submitted']));
$noRef = (new Eshcol\Ats\Services\Mailer($app))->headersFor(['meta' => json_encode(['personal' => true, 'headers' => []])]);
$check('a personal message with nothing to thread to sends no "headers" at all (Brevo refuses an empty list)', $noRef === []
    && !str_contains(Eshcol\Ats\Services\Mailer::brevoJson(['subject' => 'x', 'headers' => $noRef ?: null]), 'headers')
    && str_contains(Eshcol\Ats\Services\Mailer::brevoJson(['headers' => ['In-Reply-To' => '<a@b>']]), '"headers":{"In-Reply-To"'));
$check('their messages up to the one you answered count as read', !(int) $db->col("SELECT COUNT(*) FROM candidate_messages WHERE candidate_id = ? AND direction = 'in' AND read_at IS NULL AND id <= ?", [$anita, $cm[0]['id']]));
$check('an empty message is refused', !$svcM->send($anita, $uid, 'Hi', '   ')['ok']);
$check('a fresh message (not a reply) gets a plain subject', $svcM->send($anita, $uid, '', 'Hello again')['ok']
    && $db->col("SELECT subject FROM email_queue WHERE candidate_id = ? AND kind = 'message' ORDER BY id DESC LIMIT 1", [$anita]) === 'A message from Eshcol Global');
$msgs[] = ['id' => 'm10', 'internetMessageId' => "<m10-$tag@x>", 'subject' => 'Re: Next step for your Accounts Associate application', 'from' => ['emailAddress' => ['address' => "anita.$tag@candidates.test", 'name' => 'Anita Rao']],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 30), 'hasAttachments' => false, 'categories' => [],
    'body' => ['content' => "My current CTC is 3.6 LPA.\r\n\r\nOn Sun, 8 Nov 2026 Eshcol Global wrote:\r\n> Could you share your current CTC?"]];
(new MailIntake($app, $http))->run();
$thread = $svcM->thread($anita);
$check('their answer comes back into the same conversation, newest first', $thread[0]['direction'] === 'in' && trim($thread[0]['body']) === 'My current CTC is 3.6 LPA.' && $thread[1]['direction'] === 'out');
$inbox = array_column($svcM->inbox(), null, 'candidate_id');
$check('the Messages page lists them with their newest message, marked new', isset($inbox[$anita]) && (int) $inbox[$anita]['unread'] >= 1 && str_contains($inbox[$anita]['body'], '3.6 LPA'));
echo "\nAttaching the JD or a file to a reply\n";
$jdPath = 'docs/zz-jd-' . $tag . '.pdf';
@mkdir($app->storage('docs'), 0750, true);
file_put_contents($app->storage($jdPath), "%PDF-1.4 fake JD for $tag");
$docId = $db->insert('documents', ['owner_type' => 'role', 'owner_id' => $role, 'kind' => 'jd', 'original_name' => 'Accounts Associate JD.pdf', 'storage_path' => $jdPath, 'mime' => 'application/pdf', 'size_bytes' => 20]);
$offered = $svcM->attachableDocs($anita);
$check('the role\'s JD is offered for a candidate on that role', (bool) array_filter($offered, fn ($d) => (int) $d['id'] === $docId && str_contains($d['label'], 'JD')));
$r = $svcM->send($anita, $uid, 'Here is the JD', 'As requested, the job description is attached.', null, [$docId]);
$em = $db->one("SELECT * FROM email_queue WHERE candidate_id = ? AND kind = 'message' ORDER BY id DESC LIMIT 1", [$anita]);
$sentAtt = json_decode((string) $em['meta'], true)['attachments'] ?? [];
$jdName = "Accounts Associate $tag - Accounts Associate JD.pdf";
$check('the JD is attached, named for the role: ' . $r['message'], $r['ok'] && count($sentAtt) === 1 && $sentAtt[0]['name'] === $jdName && str_contains($r['message'], '1 file'));
$files = (new Eshcol\Ats\Services\Mailer($app))->attachments($em);
$check('the attached file is real and goes with the email', count($files) === 1 && str_contains($files[0]['body'], 'fake JD'));
$svcM->send($anita, $uid, 'No attach', 'Plain message.', null, [999999]);
$emBad = $db->one("SELECT meta FROM email_queue WHERE candidate_id = ? AND kind = 'message' ORDER BY id DESC LIMIT 1", [$anita]);
$check('a document not offered for this candidate is ignored', (json_decode((string) $emBad['meta'], true)['attachments'] ?? []) === []);
$check('the thread shows the file on your message', (bool) array_filter($svcM->thread($anita), fn ($m) => !empty($m['attachments']) && $m['attachments'][0] === $jdName));
$r = $svcM->send($anita, $uid, 'A note', 'Please see the attached note.', null, [], [['path' => $jdPath, 'name' => 'Company Profile.pdf']]);
$check('a one-off file you add is attached too', $r['ok'] && str_contains($r['message'], '1 file'));
@unlink($app->storage($jdPath));

$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$anita]);
$check('nothing can be sent to someone marked do not contact', !$svcM->send($anita, $uid, '', 'Hello')['ok']);
$db->run('UPDATE candidates SET do_not_contact = 0 WHERE id = ?', [$anita]);

echo "\nWebsite application form\n";
$check('the role is found by its title when the subject has no code', $mi->roleFromSubject("Job application — Accounts Associate $tag (Pune) — Esha") === $role);
$msgs[] = ['id' => 'm6', 'internetMessageId' => "<m6-$tag@x>", 'subject' => "Job application — Accounts Associate $tag (Pune) — Esha Patil",
    'from' => ['emailAddress' => ['address' => 'no-reply@eshcolglobal.com', 'name' => 'Eshcol Global Website']],
    'replyTo' => [['emailAddress' => ['address' => "esha.$tag@candidates.test", 'name' => 'Esha Patil']]],
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 900), 'hasAttachments' => true, 'categories' => [],
    'att' => [$att('Esha_Patil.txt', str_replace("esha.$tag@candidates.test | ", '', $cv('Esha Patil', "esha.$tag@candidates.test")))]];
(new MailIntake($app, $http))->run();
$esha = (int) $db->col('SELECT candidate_ids FROM mail_intake WHERE id > ? AND from_email = ?', [$maxIntake, 'no-reply@eshcolglobal.com']);
$made[] = $esha;
$check('the applicant gets their own address from Reply-To, not the website\'s', $esha && $db->col('SELECT email FROM candidates WHERE id = ?', [$esha]) === "esha.$tag@candidates.test");
$check('…and lands on the role named in the subject', (bool) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$esha, $role]));
$mi2 = new MailIntake($app, $http);
$check('the name comes from the website subject when the CV gives none', $mi2->nameFromEmail('Job application — Accounts Officer (Pune) — Rutuja nakte', '', '', 'no-reply@eshcolglobal.com', 'Eshcol Global Website', 'resume@eshcolglobal.com') === 'Rutuja Nakte');
$check('…or from the sender, never a job board or our own website', $mi2->nameFromEmail('Applying for Accounting Manager', '', '', 'vijay.p@candidates.test', 'Vijay Patil', 'resume@eshcolglobal.com') === 'Vijay Patil'
    && $mi2->nameFromEmail('New application', '', '', 'noreply@indeed.com', 'Indeed', 'resume@eshcolglobal.com') === null
    && $mi2->nameFromEmail('Hello', '', '', 'no-reply@eshcolglobal.com', 'Eshcol Global Website', 'resume@eshcolglobal.com') === null);
$check('a sentence scrap is never taken for a name', !Eshcol\Ats\Services\FieldExtractor::looksLikeName('And Knowledge Appropriately.') && Eshcol\Ats\Services\FieldExtractor::looksLikeName('Santhosh K.'));
$db->run("UPDATE settings SET v = '0' WHERE k = 'mail_intake_on'");
$check('paused: nothing is read', (new MailIntake($app, $http))->run() === 'skipped (CV inbox not connected)');
exit(0);
