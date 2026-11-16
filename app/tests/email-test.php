<?php
/**
 * Email engine and thank-you emails, on throwaway roles and fictional candidates.
 * Nothing is sent: on this PC the mailer always writes to storage/outbox instead.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/email-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
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
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$tag = bin2hex(random_bytes(3));
$saved = [];
foreach (['routing_enabled' => '0', 'ack_enabled' => '1', 'mail_own_addresses' => "zz.own.$tag@gmail.com", 'mail_daily_cap' => '250'] as $k => $v) {
    $saved[$k] = $app->setting($k);
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$outboxBefore = glob($app->storage('outbox') . '/*') ?: [];

echo "Secrets\n";
Secrets::put($app, "zz_$tag", 'xkeysib-test-value');
$check('a secret reads back', Secrets::get($app, "zz_$tag") === 'xkeysib-test-value');
$check('and is not stored readable', !str_contains((string) $app->setting("secret_zz_$tag"), 'xkeysib'));
$raw = base64_decode((string) $app->setting("secret_zz_$tag"));
$db->run('UPDATE settings SET v = ? WHERE k = ?', [base64_encode(substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1)), "secret_zz_$tag"]);
$check('a tampered secret is refused', Secrets::get($app, "zz_$tag") === null);
Secrets::forget($app, "zz_$tag");

$clientId = $db->insert('clients', ['name' => "ZZ Email Test $tag"]);
$exec = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Executive', 'location' => 'Pune', 'status' => 'open']);
$mgr = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Manager', 'location' => 'Pune', 'status' => 'open']);
$pool = $db->insert('roles', ['client_id' => $clientId, 'title' => "ZZ Pool $tag", 'status' => 'open', 'is_pool' => 1]);

$imp = new CandidateImporter($app);
$ack = new Acknowledger($app);
$ack->dns = fn () => true; // .test domains can't resolve; pretend they can, except where tested
$made = [];
$tmp = $app->storage("uploads-tmp/zz-mail-$tag.txt");
$person = function (string $name, string $extra = '') use ($imp, $tmp, $uid, $tag, &$made): int {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nPune\n$extra\nAccounts executive, 3 years of experience, Tally, GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid);
    $made[] = (int) $r['candidate_id'];
    return (int) $r['candidate_id'];
};
$q = fn (int $id) => $db->one('SELECT * FROM email_queue WHERE id = ?', [$id]);

echo "\nThank-you email\n";
$p = $person('Priya Mailtest');
$r = $ack->acknowledge($p, $exec, $uid);
$e = $q($r['email_id'] ?? 0);
$check('queued for a clean address', $r['status'] === 'queued' && $e && $e['status'] === 'pending');
$check('waits about 10 minutes, so other roles can join it', $e && strtotime($e['not_before']) > time() + 500);
$check('subject names the role and asks for the next step', $e && $e['subject'] === 'Next step for your Accounts Executive application');
$check('greets them by first name', $e && str_contains($e['html'], 'Thank you, Priya.') && str_contains($e['text'], 'Thank you, Priya.'));
$check('names role, place, and that the role is our client\'s', $e && str_contains($e['text'], 'the Accounts Executive role with our client in Pune') && str_contains($e['html'], 'Accounts Executive</b> role with our client in Pune'));
$check('no merge field left unfilled', $e && !str_contains($e['html'] . $e['text'], '{{'));
$check('developer notes removed from the HTML', $e && !str_contains($e['html'], 'Merge fields'));
$check('says five years, as the privacy policy does', $e && str_contains($e['text'], 'five years'));
$check('the same role again sends nothing', $ack->acknowledge($p, $exec, $uid)['status'] === 'skipped');
$r2 = $ack->acknowledge($p, $mgr, $uid);
$e = $q($r['email_id']);
$check('a second role joins the waiting email (one email, not two)', $r2['status'] === 'merged' && (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE candidate_id = ? AND kind = 'ack'", [$p]) === 1);
$check('…which now names both roles', str_contains($e['text'], 'the Accounts Executive and Accounts Manager roles with our client in Pune') && $e['subject'] === 'Next step for your application');
$check('a talent pool is never named to the candidate', $ack->acknowledge($p, $pool, $uid)['status'] === 'skipped');
$n = $person('Nikhil Norole');
$e = $q($ack->acknowledge($n, null, $uid)['email_id'] ?? 0);
$check('a CV with no role: "a role with an Eshcol Global client", and still asks for the details', $e && $e['subject'] === "We've received your CV: one step left"
    && str_contains($e['text'], 'a role with an Eshcol Global client') && str_contains($e['html'], 'Complete my application'));

echo "\nWhen in doubt, it doesn't send\n";
$two = $person('Ravi Twomail', "Alternate: ravi.other.$tag@candidates.test");
$r = $ack->acknowledge($two, $exec, $uid);
// Benjamin, 13 Nov 2026: two addresses in the CV go to the one on record (the first), without asking.
$check('two addresses in the CV: sent to the one on record, not held', $r['status'] === 'queued' && $q($r['email_id'])['to_email'] === "ravi.$tag@candidates.test");
$own = $person('Own Forwarder');
$db->run('UPDATE candidates SET email = ?, email_norm = ? WHERE id = ?', ["zz.own.$tag@gmail.com", "zz.own.$tag@gmail.com", $own]);
$r = $ack->acknowledge($own, $exec, $uid);
$check('your own forwarding address: held, never thanked', $r['status'] === 'held' && str_contains((string) $q($r['email_id'])['hold_reason'], 'your own addresses'));
$ack->dns = fn () => false;
$dead = $person('Dina Deaddomain');
$r = $ack->acknowledge($dead, $exec, $uid);
$check('a domain that cannot receive mail: held', $r['status'] === 'held' && str_contains((string) $q($r['email_id'])['hold_reason'], 'receive email'));
$ack->dns = fn () => true;
$dnc = $person('Deepa Donotcontact');
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$dnc]);
$check('do not contact: nothing queued', $ack->acknowledge($dnc, $exec, $uid)['status'] === 'skipped');
$none = $person('Nomail Person');
$db->run('UPDATE candidates SET email = NULL, email_norm = NULL WHERE id = ?', [$none]);
$check('no address: nothing queued', $ack->acknowledge($none, $exec, $uid)['status'] === 'skipped');
$x = $person('Xss Tester');
$db->run('UPDATE candidates SET first_name = ? WHERE id = ?', ['<script>alert(1)</script>', $x]);
$e = $q($ack->acknowledge($x, $exec, $uid)['email_id'] ?? 0);
$check('names are escaped in the HTML', $e && !str_contains($e['html'], '<script>alert') && str_contains($e['html'], '&lt;Script&gt;'));
$check('real-world address checks work (gmail.com yes, a made-up domain no)',
    Mailer::deliverable('someone@gmail.com') && !Mailer::deliverable("x@zz-no-such-domain-$tag.invalid"));

echo "\nSending (test mode: written to the outbox, never sent)\n";
$m = new Mailer($app);
$check('this PC is never live', !$m->live());
$db->run("UPDATE email_queue SET not_before = NOW() - INTERVAL 1 MINUTE WHERE candidate_id IN (" . implode(',', $made) . ')');
$sentBefore = $m->sentToday();
$summary = $m->processQueue();
$check('queue sends what is due: ' . $summary, str_contains($summary, 'sent') && $m->sentToday() > $sentBefore);
$check('held emails stay held', $db->col("SELECT status FROM email_queue WHERE candidate_id = ? AND kind = 'ack'", [$own]) === 'held');
$check('outbox file written', count(glob($app->storage('outbox') . '/*') ?: []) > count($outboxBefore));
$db->run('UPDATE settings SET v = ? WHERE k = \'mail_daily_cap\'', [(string) $m->sentToday()]);
$late = $person('Lata Lateone');
$id = $m->enqueue(['kind' => 'ack', 'candidate_id' => $late, 'to_email' => "lata.$tag@candidates.test", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'not_before' => date('Y-m-d H:i:s', time() - 60)]);
$check('daily ceiling: the rest wait for tomorrow', str_contains($m->processQueue(), 'daily ceiling reached') && $q($id)['status'] === 'pending');
$db->run('UPDATE settings SET v = \'250\' WHERE k = \'mail_daily_cap\'');
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$late]);
$check('marked do-not-contact after queueing: cancelled at the last moment', !$m->sendRow($q($id)) && $q($id)['status'] === 'cancelled');

echo "\nBrevo refuses the key (switched off, or the server's IP not authorised)\n";
$db->run("DELETE FROM settings WHERE k IN ('mail_key_problem','mail_last_brevo_ok')");
$k1 = $person('Kiran Keytest');
$kid = $m->enqueue(['kind' => 'ack', 'candidate_id' => $k1, 'to_email' => "kiran.$tag@candidates.test", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'not_before' => date('Y-m-d H:i:s', time() - 60)]);
$m->transport = fn () => throw new \Eshcol\Ats\Services\MailError('Brevo said 401: Key not found', false, true);
$summary = $m->processQueue();
$check('the queue pauses and says why: ' . $summary, str_starts_with($summary, 'paused: Brevo refused the API key'));
$check('the email is kept, not failed', $q($kid)['status'] === 'pending' && str_contains((string) $q($kid)['last_error'], '401'));
$check('the problem is recorded for the dashboard', ($m->keyProblem()[1] ?? '') === 'Brevo said 401: Key not found');
$m->transport = fn () => 'brevo-msg-123';
$db->run('UPDATE email_queue SET not_before = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$kid]);
$m->processQueue();
$check('once Brevo accepts again, it sends and the warning clears', $q($kid)['status'] === 'sent' && $m->keyProblem() === null);
$check('and the last successful use is recorded (for the 90-day reminder)', (bool) $app->setting('mail_last_brevo_ok'));
$m->transport = fn () => throw new \Eshcol\Ats\Services\MailError('Brevo said 400: invalid email', true);
$bid = $m->enqueue(['kind' => 'ack', 'candidate_id' => $k1, 'to_email' => "kiran2.$tag@candidates.test", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'not_before' => date('Y-m-d H:i:s', time() - 60)]);
$m->processQueue();
$check('a bad address fails that one email only, without pausing the rest', $q($bid)['status'] === 'failed' && $m->keyProblem() === null);
$m->transport = null;
$db->run("DELETE FROM settings WHERE k IN ('mail_key_problem','mail_last_brevo_ok')");

echo "\nDeleting a candidate\n";
(new Retention($app))->delete($p, 'manual', false);
$check('their queued and sent emails go with them', !(int) $db->col('SELECT COUNT(*) FROM email_queue WHERE candidate_id = ?', [$p]) && !(int) $db->col('SELECT COUNT(*) FROM acknowledgements WHERE candidate_id = ?', [$p]));

// Clean up
$ret = new Retention($app);
foreach (array_unique($made) as $id) {
    $ret->delete($id, 'manual', false);
}
$db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
$db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
foreach ($saved as $k => $v) {
    $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
}
foreach (array_diff(glob($app->storage('outbox') . '/*') ?: [], $outboxBefore) as $f) {
    @unlink($f);
}
@unlink($tmp);
$check('nothing left behind', !(int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]));
echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
