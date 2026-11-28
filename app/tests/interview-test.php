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
$waq = $db->one("SELECT * FROM whatsapp_queue WHERE template = 'interview_choose_time' AND related_id = ?", [$iv['id']]);
$check('WhatsApp too: interview_choose_time, with their own booking link as the button value', $waq && (int) $waq['candidate_id'] === $p
    && str_ends_with($link, '/i/' . (json_decode((string) $waq['button_vars'], true)[0] ?? 'none')) && (json_decode((string) $waq['variables'], true)[0] ?? '') === 'Priya');
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
// 17 Nov 2026: the interview is in two days, so the client hears in the 6 pm email, not straight away.
$check('the client is not emailed straight away for an interview two days ahead (it waits for 6 pm)', !$client
    && $db->col('SELECT client_notified_at FROM interviews WHERE id = ?', [$iv['id']]) === null);
$digestLog = $svc->clientDigest();
$client = $db->one('SELECT * FROM email_queue WHERE id > ? AND kind = \'client_interview\' ORDER BY id DESC LIMIT 1', [$maxEmail]);
$check('the 6 pm email: to the client, naming the candidate, with the calendar invite (' . $digestLog . ')', $client && $client['to_email'] === "radhika.$tag@gmail.com"
    && str_contains($client['text'], 'Priya Interview (' . ref_candidate($p) . ')') && str_contains($client['subject'], 'Interviews confirmed: 1 arranged today')
    && count($att($client)) === 1 && str_ends_with($att($client)[0], '.ics'));
$check('…marked as told, so the next 6 pm email doesn\'t repeat it', $db->col('SELECT client_notified_at FROM interviews WHERE id = ?', [$iv['id']]) !== null && $svc->clientDigest() === '');

echo "\nWhen the client hears straight away (17 Nov 2026)\n";
$check('an interview today: straight away', Interviews::clientNow('2026-11-17 18:30:00', '2026-11-17 10:00:00'));
$check('tomorrow, booked before 6 pm: waits for the 6 pm email', !Interviews::clientNow('2026-11-18 16:00:00', '2026-11-17 14:20:00'));
$check('tomorrow, booked after 6 pm (the 6 pm email has gone): straight away', Interviews::clientNow('2026-11-18 10:00:00', '2026-11-17 18:40:00'));
$check('in two days, booked at night: waits for tomorrow\'s 6 pm email', !Interviews::clientNow('2026-11-19 10:00:00', '2026-11-17 21:00:00'));
$check('the daily email switched off: always straight away', Interviews::clientNow('2026-11-25 10:00:00', '2026-11-17 09:00:00', false));
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

echo "\nAudit R2 + C5 (16 Nov 2026)\n";
$t4 = date('Y-m-d 11:00', strtotime('+3 days'));
$svc->addSlots($role, 1, 'in_person', [$t4], 30, 'client');
$slotIn = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role, "$t4:00"]);
$slot2 = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role, "$t2:00"]);
$stale = $civ; // both tabs opened while still "choosing"
[$okA] = $svc->pick($stale, $slotIn);
[$okB, $msgB2] = $svc->pick($stale, $slot2);
$check('two tabs pick two times: only the first is booked ("' . $msgB2 . '"), the other time stays free', $okA && !$okB
    && (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE interview_id = ?', [$civ['id']]) === 1
    && (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE id = ? AND interview_id IS NULL', [$slot2]) === 1);
$booked = $db->one('SELECT * FROM interviews WHERE id = ?', [$civ['id']]);
$check('invited with a video time first, chose in person: the address is filled and the meeting link cleared', $booked['kind'] === 'in_person'
    && str_contains((string) $booked['address'], 'Balewadi') && $booked['meeting_link'] === null && $booked['maps_url'] === 'https://maps.app.goo.gl/test');
$check('one call letter for that booking', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE id > ? AND to_email = (SELECT email FROM candidates WHERE id = ?) AND subject LIKE 'Interview confirmed:%'", [$maxEmail, $c3]) === 1);
echo "\nOne time, one interviewer: across a client's positions (17 Nov 2026)\n";
// AdvantEdge gave the same times on Associate and Manager; Kiran (Manager) and Baliram (Associate) were both booked 18 Nov 4 pm.
$role2 = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Manager', 'location' => 'Pune', 'status' => 'open', 'code' => "ZZJ-$tag", 'score_threshold' => 0]);
$t5 = date('Y-m-d 16:00', strtotime('+4 days'));
$t6 = date('Y-m-d 16:30', strtotime('+4 days'));
$svc->addSlots($role, 1, 'virtual', [$t5, $t6], 30, 'client');
$svc->addSlots($role2, 1, 'virtual', [$t5, $t6], 30, 'client');
[$m1, $m1a] = $person('Mohan Clashtest');
$imp->attach($m1, $role2, $uid);
$m1a2 = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$m1, $role2]);
[$m2, $m2a] = $person('Nila Clashtest');
$svc->invite($m1a2, 1, $uid);
$svc->invite($m2a, 1, $uid);
$ivM = $db->one("SELECT * FROM interviews WHERE application_id = ? AND status = 'choosing'", [$m1a2]);
$ivN = $db->one("SELECT * FROM interviews WHERE application_id = ? AND status = 'choosing'", [$m2a]);
$s5Mgr = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role2, "$t5:00"]);
$s5Asc = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role, "$t5:00"]);
[$okM] = $svc->pick($ivM, $s5Mgr);
$check('Manager: 4 pm booked', $okM);
$check('…4 pm is no longer offered on the other position (Associate)', !in_array("$t5:00", array_column($svc->openSlots($role, 1), 'starts_at'), true)
    && in_array("$t6:00", array_column($svc->openSlots($role, 1), 'starts_at'), true));
[$okN, $msgN] = $svc->pick($ivN, $s5Asc);
$check('…and an Associate candidate with an old link can\'t take it: "' . $msgN . '"', !$okN && (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE id = ? AND interview_id IS NULL', [$s5Asc]) === 1);
[$okD, $msgD] = $svc->book($m2a, 1, 'virtual', $t5, 30, ['meeting_link' => 'https://meet.google.com/abc-defg-hij'], $uid);
$check('booking 4 pm directly for the Associate is refused, naming who has it: "' . $msgD . '"', !$okD && str_contains($msgD, 'Mohan Clashtest') && str_contains($msgD, 'Accounts Manager'));
[$okD2] = $svc->book($m2a, 1, 'virtual', $t6, 30, ['meeting_link' => 'https://meet.google.com/abc-defg-hij'], $uid);
$check('4:30 is free: booked', $okD2);
$check('a 45-minute booking at 4:15 overlaps both: refused', $svc->clashAt($clientId, date('Y-m-d 16:15:00', strtotime('+4 days')), 45) !== null);
$check('another client\'s interviews never block this one', $svc->clashAt($clientId + 999999, "$t5:00", 30) === null);

echo "\nThe calendar (17 Nov 2026): client times once, allot from a slot\n";
$t7 = date('Y-m-d 11:00', strtotime('+5 days'));
$t8 = date('Y-m-d 11:30', strtotime('+5 days'));
$added = $svc->addSlots($role, 1, 'virtual', [$t7, $t8], 30, 'client');
$check('times added on one position are offered on all the client\'s positions (counted once each)', $added === 2
    && (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE role_id = ? AND starts_at = ?', [$role2, "$t7:00"]) === 1);
$wk = $svc->week(date('Y-m-d', strtotime('monday this week', strtotime($t7))), $clientId, null);
$open7 = array_values(array_filter($wk['open'], fn ($o) => $o['starts_at'] === "$t7:00"));
$check('the week shows one free 11:00 for the client, naming both positions', count($open7) === 1 && count($open7[0]['positions']) === 2);
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [(int) \Eshcol\Ats\Services\Stages::id($app, \Eshcol\Ats\Services\Stages::SHORTLISTED), $m1a2]);
$db->run("UPDATE interviews SET status = 'cancelled' WHERE application_id = ?", [$m1a2]);
$who = $svc->allottable($clientId, null);
$check('who can be allotted: shortlisted or later, nothing booked ahead', in_array('Mohan Clashtest', array_map(fn ($p) => preg_replace('/ \w+$/', ' Clashtest', $p['full_name']), array_merge(...array_values($who))), true) || (bool) array_filter(array_merge(...array_values($who)), fn ($p) => str_starts_with($p['full_name'], 'Mohan')));
[$okA2, $msgA2] = $svc->allot((int) $open7[0]['slot_id'], $m1a2, $uid);
$check('allot books it and sends the call letter: "' . $msgA2 . '"', $okA2 && (bool) $db->col("SELECT 1 FROM interviews WHERE application_id = ? AND status = 'booked' AND starts_at = ?", [$m1a2, "$t7:00"]));
$check('…the slot on his own position is the one taken', (int) $db->col('SELECT COUNT(*) FROM interview_slots WHERE role_id = ? AND starts_at = ? AND interview_id IS NOT NULL', [$role2, "$t7:00"]) === 1);
$wk2 = $svc->week(date('Y-m-d', strtotime('monday this week', strtotime($t7))), $clientId, null);
$check('…and 11:00 is no longer free for anyone', !array_filter($wk2['open'], fn ($o) => $o['starts_at'] === "$t7:00"));
$open8 = array_values(array_filter($wk2['open'], fn ($o) => $o['starts_at'] === "$t8:00"));
$check('removing a free time takes it off every position', $svc->removeClientTime((int) $open8[0]['slot_id']) === 2 && !$db->col('SELECT 1 FROM interview_slots s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ? AND s.starts_at = ?', [$clientId, "$t8:00"]));
$check('the week starts on Monday', \Eshcol\Ats\Controllers\CalendarController::monday('2026-11-19') === '2026-11-16' && \Eshcol\Ats\Controllers\CalendarController::monday('2026-11-16') === '2026-11-16');

echo "\nLast-minute changes (20 Nov 2026: Deepa Rani was late; her new time went to the client as round 2)\n";
[$d, $da] = $person('Deepa Latetest');
$td1 = date('Y-m-d 17:30', strtotime('+6 days'));
[$okD1, , $dIv] = $svc->book($da, 1, 'virtual', str_replace(' ', 'T', $td1), 30, [], $uid);
$check('a booked round 1 that hasn\'t happened is not "done": the next round is still 1', $okD1 && $svc->nextRound($da) === 1);
$td2 = date('Y-m-d 17:30', strtotime('+7 days'));
$beforeClient = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
[$okR, $msgR] = $svc->reschedule($dIv, str_replace(' ', 'T', $td2), 30, 'virtual', [], null, $uid);
$moved = $db->one('SELECT * FROM interviews WHERE id = ?', [$dIv]);
$check('"Change the time" moves the same interview, same round: ' . $msgR, $okR && $moved['starts_at'] === "$td2:00" && (int) $moved['round'] === 1 && (int) $moved['reschedules'] === 1
    && (int) $db->col("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND status IN ('booked','choosing')", [$da]) === 1);
$rl = $mail($d, 'Interview rescheduled:%');
$check('the candidate gets the new call letter, saying it moved (old and new time), with the JD and calendar invite', $rl && str_contains($rl['text'], 'Your interview has moved, Deepa.')
    && str_contains($rl['text'], date('l j F', strtotime($td2))) && str_contains($rl['text'], 'it was ' . date('l j F', strtotime($td1))) && in_array('Interview.ics', $att($rl), true));
$cr = $db->one("SELECT * FROM email_queue WHERE id > ? AND kind = 'client_interview' AND subject LIKE 'Interview rescheduled:%' ORDER BY id DESC LIMIT 1", [$beforeClient]);
$check('the client is told straight away: "round 1 … rescheduled", Was and Now', $cr && str_contains($cr['text'], 'round 1 interview with Deepa Latetest') && str_contains($cr['text'], 'Was: ') && str_contains($cr['text'], 'Now: ') && !str_contains($cr['text'], 'round 2'));
$check('the calendar invite replaces the old entry (same UID, SEQUENCE 1)', str_contains($svc->ics($moved, $svc->application($da), true), 'SEQUENCE:1') && str_contains($svc->ics($moved, $svc->application($da), true), 'UID:iv-' . $dIv . '-c@'));
$td3 = date('Y-m-d 16:00', strtotime('+8 days'));
[$okB2, , $sameId] = $svc->book($da, 1, 'virtual', str_replace(' ', 'T', $td3), 30, [], $uid);
$check('"Book round 1" again while round 1 is booked moves it instead of adding a second interview', $okB2 && $sameId === $dIv
    && (int) $db->col("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND status = 'booked'", [$da]) === 1);
$db->run('UPDATE interviews SET round = 2 WHERE id = ?', [$dIv]); // the mistake as it happened
$svc->reschedule($dIv, str_replace(' ', 'T', $td3), 30, 'virtual', [], 1, $uid);
$fix = $db->one("SELECT * FROM email_queue WHERE id > ? AND kind = 'client_interview' ORDER BY id DESC LIMIT 1", [$beforeClient]);
$check('a wrongly numbered round can be corrected, and the client is told it is round 1, not round 2', (int) $db->col('SELECT round FROM interviews WHERE id = ?', [$dIv]) === 1
    && str_contains((string) $fix['text'], 'this is round 1, not round 2'));
[$okClash, $msgClash] = $svc->reschedule($dIv, str_replace(' ', 'T', "$t7"), 30, 'virtual', [], null, $uid);
$check('moving onto a time already booked with this client is refused: "' . $msgClash . '"', !$okClash);
$beforeCancel = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
$told = $svc->cancel($dIv, $uid, true, true, 'the client is travelling');
$check('cancelling can email the candidate and the client, with the reason: "' . $told . '"', str_contains($told, 'Deepa Latetest') && str_contains($told, 'Radhika Daga')
    && (bool) $db->col("SELECT 1 FROM email_queue WHERE id > ? AND candidate_id = ? AND subject LIKE 'Interview cancelled:%' AND text LIKE '%the client is travelling%'", [$beforeCancel, $d])
    && (bool) $db->col("SELECT 1 FROM email_queue WHERE id > ? AND kind = 'client_interview' AND subject LIKE 'Interview cancelled:%'", [$beforeCancel]));
$check('…and without the ticks, nobody is emailed', (function () use ($svc, $db, $da, $uid, $d) {
    [, , $x] = $svc->book($da, 1, 'virtual', date('Y-m-d\T10:00', strtotime('+9 days')), 30, [], $uid);
    $before = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
    return $svc->cancel($x, $uid) === '' && !$db->col('SELECT 1 FROM email_queue WHERE id > ?', [$before]);
})());

echo "\nRounds 1 to 3, and the one-click invite for each (20 Nov 2026)\n";
$sl = $db->insert('shortlists', ['role_id' => $role, 'created_by' => $uid, 'status' => 'answered', 'token_hash' => hash('sha256', "zz$tag"), 'token_enc' => 'zz', 'to_email' => "radhika.$tag@gmail.com", 'expires_at' => date('Y-m-d H:i:s', strtotime('+10 days'))]);
[$e1, $e1a] = $person('Esha Readytest');
[$e2, $e2a] = $person('Farid Readytest');
foreach ([$e1a, $e2a] as $ea) {
    $db->insert('shortlist_items', ['shortlist_id' => $sl, 'application_id' => $ea, 'decision' => 'shortlist', 'profile' => '{}']);
}
$ready1 = array_column($svc->readyFor($role, 1), 'application_id');
$check('ready for round 1: everyone the client marked Shortlist and not yet invited', in_array($e1a, $ready1, true) && in_array($e2a, $ready1, true));
[$okE, , $eIv] = $svc->book($e1a, 1, 'virtual', date('Y-m-d\T11:00', strtotime('+10 days')), 30, [], $uid);
$check('…someone booked for round 1 drops off that list', $okE && !in_array($e1a, array_column($svc->readyFor($role, 1), 'application_id'), true));
$svc->outcome($eIv, 'next_round', 'Strong', $uid);
$check('through round 1: next is round 2, and ready for round 2', $svc->nextRound($e1a) === 2 && in_array($e1a, array_column($svc->readyFor($role, 2), 'application_id'), true)
    && !in_array($e2a, array_column($svc->readyFor($role, 2), 'application_id'), true));
\Eshcol\Ats\Services\KeyFacts::forgetStages();
$check('every status tag says the round: through round 1 reads "Client interview · Round 2"', str_contains(stage_tags($e1), 'Client interview · Round 2'));
$svc->book($e1a, 2, 'in_person', date('Y-m-d\T15:00', strtotime('+11 days')), 45, [], $uid);
\Eshcol\Ats\Services\KeyFacts::forgetStages();
$check('…and still "Round 2" once round 2 is booked', str_contains(stage_tags($e1), 'Client interview · Round 2'));

echo "\n\"Client interview · Round 2\" in the stage drop-down (20 Nov 2026)\n";
[$okF, , $fIv] = $svc->book($e2a, 1, 'virtual', date('Y-m-d\T12:00', strtotime('+10 days')), 30, [], $uid);
[$okM, $msgM, $bookM] = $svc->moveToRound($e2a, 2, $uid, true);
$check('round 1 still booked: it is recorded "Through to the next round", and with no round 2 times the booking form is asked for: "' . $msgM . '"',
    $okF && $okM && $bookM && $db->col('SELECT outcome FROM interviews WHERE id = ?', [$fIv]) === 'next_round' && $svc->nextRound($e2a) === 2);
\Eshcol\Ats\Services\KeyFacts::forgetStages();
$check('…the tag says Round 2 straight away', str_contains(stage_tags($e2), 'Client interview · Round 2'));
$svc->addSlots($role, 2, 'in_person', [date('Y-m-d 15:00', strtotime('+12 days'))], 45, 'client');
[$g, $ga] = $person('Gauri Roundtest');
[, , $gIv] = $svc->book($ga, 1, 'virtual', date('Y-m-d\T09:30', strtotime('+10 days')), 30, [], $uid);
[$okG, $msgG, $bookG] = $svc->moveToRound($ga, 2, $uid, true);
$check('with round 2 times from the client, one pick invites them to choose: "' . $msgG . '"', $okG && !$bookG
    && (bool) $db->col("SELECT 1 FROM interviews WHERE application_id = ? AND round = 2 AND status = 'choosing'", [$ga]));
$opts = stage_options($db->all('SELECT * FROM stages WHERE is_active = 1 ORDER BY sort'), (int) \Eshcol\Ats\Services\Stages::id($app, \Eshcol\Ats\Services\Stages::INTERVIEW), 2);
$sidIv = (int) \Eshcol\Ats\Services\Stages::id($app, \Eshcol\Ats\Services\Stages::INTERVIEW);
$check('the drop-down offers Client interview · Round 1, 2 and 3, with Round 2 selected', str_contains($opts, '>Client interview · Round 1<') && str_contains($opts, 'value="' . $sidIv . ':3"')
    && str_contains($opts, 'value="' . $sidIv . ':2" selected'));
$check('the round on the tags only shows at Client interview', \Eshcol\Ats\Services\KeyFacts::stageName($app, 'Shortlisted', (int) \Eshcol\Ats\Services\Stages::id($app, \Eshcol\Ats\Services\Stages::SHORTLISTED), 2) === 'Shortlisted');
$db->run('DELETE FROM shortlist_items WHERE shortlist_id = ?', [$sl]);
$db->run('DELETE FROM shortlists WHERE id = ?', [$sl]);

echo "\n\"How did it go?\" on My day (24 Nov 2026: Benjamin moved four people to Rejected by client and they stayed on the list)\n";
$past = fn (int $appId, int $round, string $when) => $db->insert('interviews', ['application_id' => $appId, 'round' => $round, 'status' => 'booked', 'starts_at' => date('Y-m-d H:i:s', strtotime($when)), 'duration_min' => 30]);
$roleOnly = "r.id = $role";
$waiting = fn () => array_map('intval', array_column(Interviews::awaitingOutcome($db, $roleOnly, 50), 'id'));
[, $ha] = $person('Hari Outcometest');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$sidIv, $ha]);
$hIv = $past($ha, 1, '-2 days');
$check('a round that took place with no result yet is on the list', in_array($hIv, $waiting(), true));
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [(int) $db->col("SELECT id FROM stages WHERE name = 'Rejected by client'"), $ha]);
$check('moved to "Rejected by client" by their stage: it leaves the list', !in_array($hIv, $waiting(), true));
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [(int) \Eshcol\Ats\Services\Stages::id($app, \Eshcol\Ats\Services\Stages::OFFER), $ha]);
$check('moved to "Offer made" by their stage: not asked either', !in_array($hIv, $waiting(), true));
[, $ia] = $person('Ira Outcometest');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$sidIv, $ia]);
$i1 = $past($ia, 1, '-6 days');
$i2 = $past($ia, 2, '-5 days');
$w = $waiting();
$check('round 2 has happened: only round 2 is asked about, not round 1 as well (Deepa Rani was listed twice)', !in_array($i1, $w, true) && in_array($i2, $w, true));
$svc->outcome($i2, 'not_selected', '', $uid);
$check('recording the result clears it', !in_array($i2, $waiting(), true));
exit(0);
