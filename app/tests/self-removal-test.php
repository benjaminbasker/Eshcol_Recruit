<?php
/**
 * Candidates deleting their own details from "Remove my details" (24 Nov 2026), and "Delete the ticked" on the
 * removal list: the live-process guard, the marker kept, the confirmation email sent once (address wiped after),
 * a used link showing a kind page, held people on your list. Throwaway data, cleaned up. LOCAL ONLY.
 * Run: php tests/self-removal-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvRequests;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\SelfRemoval;

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
$savedRouting = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = VALUES(v)");
$client = $db->insert('clients', ['name' => "ZZ SelfRm $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Trainee ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
$emails = [];
$tokens = [];
register_shutdown_function(function () use ($app, $db, &$made, &$emails, &$tokens, $client, $savedRouting, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    foreach ($emails as $e) {
        $db->run("DELETE FROM email_queue WHERE kind = 'removal_done' AND (to_email = ? OR to_email = '(deleted)')", [$e]);
        $db->run('DELETE FROM suppression WHERE hash = ?', [hash('sha256', 'eshcol-suppression|' . $e)]);
    }
    foreach ($tokens as $t) {
        $db->run('DELETE FROM removed_links WHERE token_hash = ?', [hash('sha256', $t)]);
    }
    $db->run('DELETE FROM interviews WHERE application_id IN (SELECT id FROM applications WHERE role_id IN (SELECT id FROM roles WHERE client_id = ?))', [$client]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $savedRouting === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'")
        : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$savedRouting]);
    echo "\n$ok passed, $bad failed\n";
});

$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-selfrm-' . getmypid() . '.txt');
$person = function (string $name, string $stageName) use ($db, $imp, $tmp, $uid, $tag, $role, &$made, &$emails): array {
    $mail = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    $emails[] = $mail;
    file_put_contents($tmp, "$name\n$mail | +91 9" . random_int(100000000, 999999999) . "\nChennai\nTrainee, 2025 – Present\nDiploma in Mechanical Engineering");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid, 'upload', 'website');
    $cid = (int) $r['candidate_id'];
    $made[] = $cid;
    $db->run("UPDATE cv_ai_reads SET status = 'done' WHERE candidate_id = ? AND status IN ('pending','reading')", [$cid]);
    $stage = (int) $db->col('SELECT id FROM stages WHERE name = ?', [$stageName]);
    $app = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $role])
        ?: $db->insert('applications', ['role_id' => $role, 'candidate_id' => $cid, 'stage_id' => $stage]);
    $db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$stage, $app]);
    return [$cid, $app, $mail];
};
$request = function (int $cid) use ($db, &$tokens): array {
    $t = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $tokens[] = $t;
    $id = $db->insert('cv_requests', ['candidate_id' => $cid, 'grp' => 2, 'token_hash' => hash('sha256', $t), 'token_enc' => 'x', 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')]);
    return [$db->one('SELECT * FROM cv_requests WHERE id = ?', [$id]), $t];
};
$sr = new SelfRemoval($app);
$cvr = new CvRequests($app);
$ret = new Retention($app);

echo "Who counts as in a live process\n";
[$free] = $person('Arun Applied', 'Applied');
[$fut] = $person('Bhavna Future', 'Future requirement');
[$ns] = $person('Charan Notsuitable', 'Not suitable');
[$short, $shortApp] = $person('Divya Shortlisted', 'Shortlisted');
[$offer] = $person('Ezhil Offer', 'Offer made');
[$iv, $ivApp] = $person('Farhan Interview', 'Applied');
$db->insert('interviews', ['application_id' => $ivApp, 'status' => 'booked', 'starts_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
@unlink($tmp);
$check('Applied, Future requirement and Not suitable: not live', !$sr->liveProcess($free) && !$sr->liveProcess($fut) && !$sr->liveProcess($ns));
$check('Shortlisted: live, and says which position', ($w = $sr->liveProcess($short)) && str_contains($w[0], 'Shortlisted for Trainee ZZ'));
$check('Offer made: live', (bool) $sr->liveProcess($offer));
$check('an interview booked, even at Applied: live', in_array('An interview is being arranged or booked', $sr->liveProcess($iv), true));

echo "\nNot in a process: deleted at once\n";
[$r1, $t1] = $request($free);
$mail1 = $db->col('SELECT email FROM candidates WHERE id = ?', [$free]);
$check('their link works before', $cvr->byToken($t1) !== null && $sr->linkUsed($t1) === null);
$out = $sr->fromLink($r1, $t1);
$check('the answer is "deleted"', $out === 'deleted');
$check('the record and their applications are gone', !$db->col('SELECT id FROM candidates WHERE id = ?', [$free]) && !$db->col('SELECT id FROM applications WHERE candidate_id = ?', [$free]));
$check('the "once asked to be removed" marker stays', $ret->isSuppressed((string) $mail1, null));
$check('logged as deleted by request', (bool) $db->col("SELECT 1 FROM audit_log WHERE action = 'candidate_self_deleted' AND entity_id = ?", [$free]));
$conf = $db->all("SELECT * FROM email_queue WHERE kind = 'removal_done' AND to_email = ?", [$mail1]);
$check('one confirmation email queued, not tied to the deleted record', count($conf) === 1 && $conf[0]['candidate_id'] === null && $conf[0]['status'] === 'pending');
$check('a second press does nothing more: no second email', $sr->fromLink($r1, $t1) === 'already'
    && (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'removal_done' AND to_email = ?", [$mail1]) === 1);
$check('the used link: not a working link, but known as used (a kind page, not an error)', $cvr->byToken($t1) === null && $sr->linkUsed($t1) !== null);
$check('a made-up link is not "used"', $sr->linkUsed(str_repeat('y', 32)) === null);

echo "\nThe confirmation email\n";
$e = $conf[0];
$check('subject: "Your details have been deleted"', $e['subject'] === 'Your details have been deleted');
$check('Follow us on LinkedIn button and the signature icons', str_contains($e['html'], 'Follow us on LinkedIn') && str_contains($e['html'], '/assets/sig/'));
$check('signed as Director, not Managing Director', str_contains($e['text'], "Director\n") && !str_contains($e['text'], 'Managing Director'));
$check('greets them by first name', str_contains($e['text'], 'Dear Arun,'));
$check('no em dashes, never names Claude or AI', !str_contains($e['html'] . $e['text'], '—') && !preg_match('/\bClaude\b|\bAI\b/', $e['text']));
$m = new Mailer($app);
$m->transport = fn (array $row) => 'test:' . $row['id'];
$check('it sends (no candidate record needed)', $m->sendRow($e));
$after = $db->one('SELECT * FROM email_queue WHERE id = ?', [$e['id']]);
$check('once sent, their address and the text are wiped', $after['status'] === 'sent' && $after['to_email'] === '(deleted)' && $after['html'] === null && $after['text'] === null);

echo "\nIn a process: kept, all contact stopped\n";
[$r2, $t2] = $request($short);
$db->insert('email_queue', ['kind' => 'interview', 'candidate_id' => $short, 'to_email' => 'divya.x@candidates.test', 'subject' => 'x', 'status' => 'pending']);
$out = $sr->fromLink($r2, $t2);
$c = $db->one('SELECT removal_requested_at FROM candidates WHERE id = ?', [$short]);
$check('the answer is "held"; they are still on file', $out === 'held' && $c !== null);
$check('marked "asked not to keep", consent withdrawn, waiting emails cancelled', $c['removal_requested_at'] !== null
    && $db->col('SELECT status FROM consents WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$short]) === 'withdrawn'
    && !$db->col("SELECT 1 FROM email_queue WHERE candidate_id = ? AND status = 'pending'", [$short]));
$check('no "deleted" email for them', !$db->col("SELECT 1 FROM email_queue WHERE kind = 'removal_done' AND to_email LIKE 'divya.%'"));
$check('a note on their record says to close within 30 days', (bool) $db->col("SELECT 1 FROM notes WHERE entity_type = 'candidate' AND entity_id = ? AND body LIKE '%within 30 days%'", [$short]));
$held = array_column($sr->heldList(), 'close_by', 'id');
$check('on your list to close, by 30 days from the request', isset($held[$short]) && $held[$short] === date('Y-m-d', strtotime('+30 days')));
$check('the link was not marked used (they still exist)', $sr->linkUsed($t2) === null);
$check('a second press: nothing more', $sr->fromLink($r2, $t2) === 'already');

echo "\nDelete the ticked (your removal list)\n";
$db->run('UPDATE candidates SET removal_requested_at = NOW() WHERE id IN (?, ?)', [$fut, $ns]);
$res = $sr->bulk([$fut, $short, $offer, 999999999], $uid);
$check('deletes those who asked and are free; skips the one in a process, with why', $res['deleted'] === 1 && count($res['skipped']) === 1
    && $res['skipped'][0]['id'] === $short && str_contains($res['skipped'][0]['why'][0], 'Shortlisted'));
$check('the deleted one is gone, with the marker kept', !$db->col('SELECT id FROM candidates WHERE id = ?', [$fut]) && $ret->isSuppressed((string) $emails[1], null));
$check('someone who never asked is not touched (Offer made, not flagged)', (bool) $db->col('SELECT id FROM candidates WHERE id = ?', [$offer]));
$check('the held person is kept', (bool) $db->col('SELECT id FROM candidates WHERE id = ?', [$short]));
$check('no confirmation email from your bulk delete', !$db->col("SELECT 1 FROM email_queue WHERE kind = 'removal_done' AND to_email = ?", [$emails[1]]));
$res = $sr->bulk([$ns], $uid);
$check('Not suitable + asked: deleted', $res['deleted'] === 1 && !$db->col('SELECT id FROM candidates WHERE id = ?', [$ns]));
