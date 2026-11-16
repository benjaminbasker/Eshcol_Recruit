<?php
/**
 * Interviews end to end at the service level: slots, the candidate choosing, Benjamin's two
 * call letters with attachments and calendar invites, the client confirmation, the 4-hour
 * reminder and outcomes. Throwaway data; nothing is emailed (this PC is always in test mode).
 * Cleans up after itself, even if a check crashes. LOCAL ONLY. Run: php tests/interview-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Interviews;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\WhatsApp;

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
$routingWas = $app->setting('routing_enabled', '1');
$db->run("UPDATE settings SET v = '0' WHERE k = 'routing_enabled'");
$made = [];
$docs = [];
$clientId = $db->insert('clients', ['name' => 'AdvantEdge', 'legal_name' => "AdvantEdge Accounting and Taxation LLP $tag", 'website' => 'https://www.advantedgeconsulting.in/',
    'interview_address' => "AdvantEdge Accounting and Taxation LLP, Office 301, 3rd Floor,\nArchway Sopan Baug, Balewadi High Street, Balewadi", 'maps_url' => 'https://maps.app.goo.gl/test',
    'meeting_link' => 'https://meet.google.com/chu-hkzu-ata']);
$db->insert('client_contacts', ['client_id' => $clientId, 'name' => 'Radhika Daga', 'email' => "radhika.$tag@gmail.com"]);
$tmp = $app->storage("uploads-tmp/zz-iv-$tag.txt");
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
register_shutdown_function(function () use ($app, $db, &$made, &$docs, $clientId, $routingWas, $tmp, $tag, $maxEmail, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
    }
    foreach ($docs as $d) {
        (new \Eshcol\Ats\Services\Documents($app))->delete($d);
    }
    $db->run('DELETE FROM email_queue WHERE id > ?', [$maxEmail]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    @unlink($tmp);
    $left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
    echo $left ? "  FAIL clean-up left $left candidate(s)\n" : "  ok   nothing left behind\n";
    echo "\n" . ($ok + ($left ? 0 : 1)) . ' passed, ' . ($bad + ($left ? 1 : 0)) . " failed\n";
});
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Associate', 'location' => 'Pune', 'status' => 'open', 'code' => "ZZI-$tag", 'score_threshold' => 0]);
// The JD (role) and company profile (client), as uploaded files.
$addDoc = function (string $type, int $owner, string $kind, string $name) use ($app, $db, &$docs) {
    @mkdir($app->storage('docs'), 0750, true);
    $rel = 'docs/zz-' . bin2hex(random_bytes(6)) . '.pdf';
    copy(dirname(__DIR__) . '/tests/fixtures/Priya_Ramanathan_CV.pdf', $app->storage($rel));
    $docs[] = $db->insert('documents', ['owner_type' => $type, 'owner_id' => $owner, 'kind' => $kind, 'original_name' => $name, 'storage_path' => $rel, 'mime' => 'application/pdf', 'size_bytes' => 1000]);
};
$addDoc('role', $role, 'jd', 'Job Description_Associate.pdf');
$addDoc('client', $clientId, 'company_profile', 'AdvantEdge brief.pdf');
$imp = new CandidateImporter($app);
$person = function (string $name) use ($imp, $tmp, $uid, $tag, $db, $role, &$made): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 98765 4" . random_int(1000, 9999) . "\nPune\nAccounts executive, 3 years of experience in Tally and GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $role, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $role])];
};
$svc = new Interviews($app);
$mail = fn (int $cid, string $like) => $db->one('SELECT * FROM email_queue WHERE id > ? AND candidate_id = ? AND subject LIKE ? ORDER BY id DESC LIMIT 1', [$maxEmail, $cid, $like]);
$att = fn (?array $e) => $e ? array_column((array) (json_decode((string) $e['meta'], true)['attachments'] ?? []), 'name') : [];
$stageName = fn (int $appId) => (string) $db->col('SELECT s.name FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$appId]);

echo "Time slots\n";
[$p, $pa] = $person('Priya Interview');
[$k, $ka] = $person('Kiran Interview');
$check('no slots yet: inviting says so', !$svc->invite($pa, 1, $uid)[0]);
$t1 = date('Y-m-d 12:15', strtotime('+2 days'));
$t2 = date('Y-m-d 15:30', strtotime('+2 days'));
$n = $svc->addSlots($role, 1, 'virtual', [$t1, $t2, $t1, date('Y-m-d H:i', time() - 3600), ''], 30, 'client');
$check('client times added; duplicate and past times skipped', $n === 2 && count($svc->openSlots($role, 1)) === 2);

echo "\nThe candidate chooses\n";
[$okInv, $msg] = $svc->invite($pa, 1, $uid);
$iv = $db->one("SELECT * FROM interviews WHERE application_id = ? AND status = 'choosing'", [$pa]);
$check('invited: ' . $msg, $okInv && (bool) $iv);
$inv = $mail($p, 'Choose your interview time%');
$link = $svc->link((int) $iv['id']);
$check('the email has the times and a "Choose my time" link', $inv && str_contains($inv['html'], 'Choose my time') && str_contains($inv['text'], $link) && str_contains($inv['text'], date('l j F', strtotime($t1))));
$check('house design (15 Nov 2026): "Good news, Priya." headline; the preview line has no "Good news" (Benjamin)', $inv && str_contains($inv['text'], 'Good news, Priya.')
    && preg_match('/display:none[^>]*>[^<]*would like to meet you\./', $inv['html']) && !preg_match('/display:none[^>]*>Good news/', $inv['html'])
    && str_contains($inv['html'], 'INTERVIEW INVITATION') === false && str_contains($inv['html'], 'Interview invitation') && str_contains($inv['text'], 'Follow us on LinkedIn'));
$wa = (new WhatsApp($app))->messages($db->one('SELECT * FROM candidates WHERE id = ?', [$p]), $db->one('SELECT * FROM applications WHERE id = ?', [$pa]), 'Benjamin Basker');
$check('WhatsApp offers "choose an interview time" with the same link', str_contains($wa['interview_choose']['text'] ?? '', $link));
$slot1 = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role, "$t1:00"]);
[$okPick] = $svc->pick($svc->byToken(substr($link, strrpos($link, '/i/') + 3)), $slot1);
$iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$iv['id']]);
$check('picking a time books it', $okPick && $iv['status'] === 'booked' && $iv['starts_at'] === "$t1:00");
$svc->invite($ka, 1, $uid);
$kiv = $db->one("SELECT * FROM interviews WHERE application_id = ? AND status = 'choosing'", [$ka]);
[$okK, $msgK] = $svc->pick($kiv, $slot1);
$check('the same time can\'t be taken twice: "' . $msgK . '"', !$okK && count($svc->openSlots($role, 1)) === 1);

echo "\nThe virtual call letter (round 1, your first format)\n";
$letter = $mail($p, 'Interview confirmed:%');
$date = date('F j, Y', strtotime($t1));
$check('"As discussed, your interview with our client, … (website), is confirmed for <date>, at 12:15 HRS"', $letter && str_contains($letter['text'], "As discussed, your interview with our client, AdvantEdge Accounting and Taxation LLP $tag (https://www.advantedgeconsulting.in/), is confirmed for $date, at 12:15 HRS."));
$check('"Please join using the Google Meet link" + the link', str_contains($letter['text'], 'Please join using the Google Meet link provided below.') && str_contains($letter['text'], 'https://meet.google.com/chu-hkzu-ata'));
$check('the three preparation points and good wishes', str_contains($letter['text'], 'Join from a laptop or desktop computer') && str_contains($letter['text'], 'Log in at least 5 minutes early') && str_contains($letter['text'], 'Best of luck with your interview!'));
$check('house design (15 Nov 2026): "You\'re booked in, Priya.", the details card, your signature and the LinkedIn button', str_contains($letter['text'], "You're booked in, Priya.")
    && str_contains($letter['text'], 'When: ') && str_contains($letter['text'], 'Join on Google Meet: https://meet.google.com/chu-hkzu-ata') && str_contains($letter['text'], 'Eshcol Global Services (P) Ltd')
    && str_contains($letter['text'], 'Follow us on LinkedIn') && !str_contains($letter['text'], 'Dear Priya,'));
$check('attached: the JD and a calendar invite (no company profile for virtual)', $att($letter) === ['Job description - Job Description_Associate.pdf', 'Interview.ics']);
$ics = $svc->ics($iv, $svc->application($pa), false);
$check('the calendar invite is valid (start in UTC, the Meet link, a 30-minute alarm)', str_contains($ics, "BEGIN:VCALENDAR\r\n") && str_contains($ics, 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($t1))) && str_contains($ics, 'meet.google.com') && str_contains($ics, 'TRIGGER:-PT30M'));
$client = $db->one('SELECT * FROM email_queue WHERE id > ? AND kind = \'client_interview\' ORDER BY id DESC LIMIT 1', [$maxEmail]);
$check('the client gets a confirmation with the invite', $client && $client['to_email'] === "radhika.$tag@gmail.com" && str_contains($client['text'], 'Priya Interview (' . ref_candidate($p) . ')') && $att($client) === ['Interview.ics']);
$check('the candidate moved to "Client interview"', $stageName($pa) === 'Client interview');

echo "\nThe in-person letter (round 2, your second format)\n";
$svc->outcome((int) $iv['id'], 'next_round', 'Good on GST', $uid);
$check('next round after round 1', $svc->nextRound($pa) === 2);
$t3 = date('Y-m-d 15:30', strtotime('+5 days'));
[$okB, $msgB, $iv2id] = $svc->book($pa, 2, 'in_person', str_replace(' ', 'T', $t3), 45, [], $uid);
$l2 = $mail($p, 'Interview confirmed:%');
$check('booked directly: ' . $msgB, $okB && $l2 && (int) $l2['id'] !== (int) $letter['id']);
$check('"…your personal interview with our client, … has been scheduled for:"', str_contains($l2['text'], "Following our conversation, I'm pleased to confirm that your personal interview with our client, AdvantEdge Accounting and Taxation LLP $tag, has been scheduled for:"));
$check('Date & Time, Location (one line) and Map Link', str_contains($l2['text'], 'Date & Time: ' . date('F j, Y', strtotime($t3)) . ', at 15:30 Hrs') && str_contains($l2['text'], 'Location: AdvantEdge Accounting and Taxation LLP, Office 301, 3rd Floor, Archway Sopan Baug') && str_contains($l2['text'], 'Map Link: https://maps.app.goo.gl/test'));
$check('"Please confirm your availability at your earliest convenience."', str_contains($l2['text'], 'Please confirm your availability at your earliest convenience.'));
$check('attached: company profile, JD and calendar invite', $att($l2) === ['Company profile - AdvantEdge brief.pdf', 'Job description - Job Description_Associate.pdf', 'Interview.ics']);
$check('a virtual booking with no meeting link anywhere is refused', (function () use ($db, $clientId, $svc, $ka, $uid) {
    $db->run('UPDATE clients SET meeting_link = NULL WHERE id = ?', [$clientId]);
    $r = $svc->book($ka, 1, 'virtual', date('Y-m-d\T10:00', strtotime('+3 days')), 30, [], $uid);
    $db->run("UPDATE clients SET meeting_link = 'https://meet.google.com/chu-hkzu-ata' WHERE id = ?", [$clientId]);
    return !$r[0];
})());

echo "\nThe reminder 4 hours before\n";
$db->run('UPDATE interviews SET starts_at = NOW() + INTERVAL 5 HOUR WHERE id = ?', [$iv2id]);
$check('not more than 4 hours ahead', $svc->reminders() === '');
$db->run('UPDATE interviews SET starts_at = NOW() + INTERVAL 3 HOUR WHERE id = ?', [$iv2id]);
$log = $svc->reminders();
$rem = $mail($p, 'Reminder: your interview today%');
$check('within 4 hours: one reminder, with the address and map: ' . $log, $rem && str_contains($rem['text'], 'Where: AdvantEdge') && str_contains($rem['text'], 'Open in Maps: https://maps.app.goo.gl/test')
    && str_contains($rem['text'], 'All the best today, Priya.') && str_contains($rem['text'], 'Please reach 10 minutes early'));
$check('…only once', $svc->reminders() === '');

echo "\nOutcomes and changes\n";
$svc->outcome($iv2id, 'selected', 'Offer at 4.2 LPA', $uid);
$check('"Selected" moves them to "Offer made"', $stageName($pa) === 'Offer made');
$svc->outcome((int) $kiv['id'], 'not_selected', '', $uid);
$check('"Not selected" moves them to "Not suitable"', $stageName($ka) === 'Not suitable');
$svc->cancel((int) $iv['id'], $uid);
$check('cancelling frees the time again', (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE id = ? AND interview_id IS NULL', [$slot1]) === 1);
[$c3, $c3a] = $person('Chitra Busy');
$svc->invite($c3a, 1, $uid);
$civ = $db->one("SELECT * FROM interviews WHERE application_id = ? AND status = 'choosing'", [$c3a]);
$svc->noneWork($civ, 'Any weekday after 4 pm');
$check('"none of these times work" tells you, and is noted on the candidate', (bool) $db->col('SELECT 1 FROM email_queue WHERE id > ? AND subject LIKE ?', [$maxEmail, 'Chitra Busy can%'])
    && (bool) $db->col("SELECT 1 FROM notes WHERE entity_type = 'candidate' AND entity_id = ? AND body LIKE '%after 4 pm%'", [$c3]));
exit(0);
