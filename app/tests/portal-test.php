<?php
/**
 * The client portal (Benjamin, 13 Nov 2026), on two throwaway clients with fictional candidates:
 *  - access is given per contact; the invitation carries a one-time link; opening it doesn't use it up,
 *    pressing "Continue" does; a used or expired link is refused; asking for links is limited;
 *  - a contact sees only their own company's positions and the candidates sent to them (not drafts,
 *    not closed shortlists), never another client's; with the candidates' email and phone (14 Nov 2026);
 *  - decisions and comments are saved and the owners told;
 *  - interview times: a window and an interview length (5–6 pm at 20 minutes is three times);
 *  - a new position asked for arrives as a draft;
 *  - taking access away ends every sign-in at once.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/portal-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\ClientPortal;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Shortlists;

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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop'); // letters only
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$was = [];
foreach (['routing_enabled', 'category_mode', 'ai_read_enabled'] as $k) {
    $was[$k] = $app->setting($k);
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $k === 'category_mode' ? 'off' : '0']);
}
$clientA = $db->insert('clients', ['name' => "ZZ Portal Alpha $tag", 'is_active' => 1]);
$clientB = $db->insert('clients', ['name' => "ZZ Portal Beta $tag", 'is_active' => 1]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientA, $clientB, $was, $tag, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id IN (?, ?)', [$clientA, $clientB]);
    $db->run('DELETE FROM clients WHERE id IN (?, ?)', [$clientA, $clientB]);
    $db->run("DELETE FROM email_queue WHERE to_email LIKE ? OR subject LIKE ?", ["%.$tag@clients.test", "%ZZ Portal % $tag%"]);
    foreach ($was as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    echo "\n$ok passed, $bad failed\n";
});
$mkRole = function (int $client, string $title, string $status = 'open') use ($db, $tag): int {
    $id = $db->insert('roles', ['client_id' => $client, 'title' => "$title ZZ$tag", 'location' => 'Pune', 'exp_min' => 2, 'exp_max' => 5, 'status' => $status,
        'accepts_routed' => 0, 'score_threshold' => 0, 'jd_text' => 'Accounts. Tally, GST.']);
    $db->update('roles', ['code' => 'ZZP-' . $id], $id);
    $db->insert('role_criteria', ['role_id' => $id, 'kind' => 'must', 'value' => 'Tally', 'sort' => 0]);
    return $id;
};
$roleA = $mkRole($clientA, 'Accounts Officer');
$roleB = $mkRole($clientB, 'Accounts Manager');
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-portal-' . getmypid() . '.txt');
$person = function (string $name, int $roleId) use ($imp, $tmp, $uid, $tag, $db, &$made): int {
    $email = strtolower(str_replace(' ', '.', $name)) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccountant, Shah Traders, 2021 – Present\n4 years of experience in accounts: Tally Prime, GST returns, TDS, bank reconciliation.\nB.Com, Pune University");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    $made[] = (int) $r['candidate_id'];
    return (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $roleId]);
};
$a1 = $person('Asha Portaltest', $roleA);
$a2 = $person('Bala Portaltest', $roleA);
$a3 = $person('Chitra Portaltest', $roleA);
$b1 = $person('Dinesh Portaltest', $roleB);
@unlink($tmp);
$sl = new Shortlists($app);
$send = function (int $roleId, array $apps, string $to) use ($sl, $uid): int {
    $id = $sl->create($roleId, array_map(fn ($a) => [$a, 'Steady accountant, good on GST.'], $apps), 'Client Person', $to, '', '', true, $uid, 'profiles');
    $sl->send($id, $uid);
    return $id;
};
$slA = $send($roleA, [$a1, $a2], "hr.alpha.$tag@clients.test");
$slB = $send($roleB, [$b1], "hr.beta.$tag@clients.test");
$slDraft = $sl->create($roleA, [[$a3, 'Not sent yet.']], 'Client Person', "hr.alpha.$tag@clients.test", '', '', true, $uid, 'profiles');

$portal = new ClientPortal($app);

echo "Giving access\n";
$cA = $portal->addContact($clientA, 'Meera Alpha', "meera.alpha.$tag@clients.test", 'HR Manager', null);
$cB = $portal->addContact($clientB, 'Vikram Beta', "vikram.beta.$tag@clients.test", 'Director', null);
$cNo = $portal->addContact($clientA, 'No Email', '', null, null);
$check('contacts are added to their client', is_int($cA) && is_int($cB) && (int) $db->col('SELECT client_id FROM client_contacts WHERE id = ?', [$cA]) === $clientA);
$check('a contact needs a name, and a sound email address', is_string($portal->addContact($clientA, '', 'x@y.test', null, null)) && is_string($portal->addContact($clientA, 'Bad Mail', 'not-an-email', null, null)));
$check('no email address: no invitation, with the reason', str_contains((string) $portal->invite($cNo, $uid), 'email address'));
$check('nobody has access until it is given', $portal->requestLink("meera.alpha.$tag@clients.test", '10.0.0.1') === false);
$check('the invitation is sent', $portal->invite($cA, $uid) === null && $portal->invite($cB, $uid) === null);
$mail = $db->one("SELECT * FROM email_queue WHERE to_email = ? AND kind = 'portal' ORDER BY id DESC LIMIT 1", ["meera.alpha.$tag@clients.test"]);
$check('…as an email with a sign-in link, saying there is no password', $mail && str_contains($mail['text'], '/portal/in/') && str_contains($mail['text'], 'no password') && str_contains($mail['subject'], 'client portal'));
preg_match('#/portal/in/([A-Za-z0-9_-]{20,64})#', (string) $mail['text'], $m);
$tokenA = $m[1] ?? '';
$check('only the hash of the link is stored', $tokenA !== '' && !$db->col('SELECT 1 FROM client_logins WHERE token_hash = ?', [$tokenA]) && (bool) $db->col('SELECT 1 FROM client_logins WHERE token_hash = ?', [hash('sha256', $tokenA)]));
$check('the invitation link lasts 7 days', (int) $db->col("SELECT TIMESTAMPDIFF(HOUR, NOW(), expires_at) FROM client_logins WHERE token_hash = ?", [hash('sha256', $tokenA)]) >= 167);
$check('the same address cannot have access at two clients', is_int($dup = $portal->addContact($clientB, 'Meera Twin', "meera.alpha.$tag@clients.test", null, null)) && str_contains((string) $portal->invite($dup, $uid), 'already has portal access'));

echo "\nSigning in\n";
$check('opening the link shows who it is for, without using it up', ($portal->peek($tokenA)['name'] ?? '') === 'Meera Alpha' && ($portal->peek($tokenA)['name'] ?? '') === 'Meera Alpha');
$sessA = $portal->consume($tokenA, 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0');
$check('pressing Continue signs in', is_string($sessA) && strlen($sessA) >= 40);
$check('the link works once only', $portal->consume($tokenA) === null && $portal->peek($tokenA) === null);
$meA = $portal->contactBySession($sessA);
$check('the session knows the contact and their company', $meA && (int) $meA['id'] === $cA && (int) $meA['client_id'] === $clientA && $meA['client'] === "ZZ Portal Alpha $tag");
$check('a made-up or empty session is nobody', $portal->contactBySession('x') === null && $portal->contactBySession(str_repeat('a', 43)) === null && $portal->contactBySession(null) === null);
$check('asking for a link: known address gets one', $portal->requestLink(strtoupper("meera.alpha.$tag@clients.test"), '10.0.0.1') === true);
$check('…an unknown address gets nothing (and the page says the same either way)', $portal->requestLink("stranger.$tag@clients.test", '10.0.0.1') === false);
$portal->requestLink("meera.alpha.$tag@clients.test", '10.0.0.1');
$check('…and no more than 3 links in 15 minutes', $portal->requestLink("meera.alpha.$tag@clients.test", '10.0.0.1') === false);
$db->run('UPDATE client_logins SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE contact_id = ? AND used_at IS NULL', [$cA]);
$stale = $portal->issue($cA, 'signin', null);
$db->run('UPDATE client_logins SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = ?', [hash('sha256', $stale)]);
$check('an expired link is refused', $portal->peek($stale) === null && $portal->consume($stale) === null);
$meB = $portal->contactBySession((string) $portal->consume($portal->issue($cB, 'signin', null)));

echo "\nWhat a client sees\n";
$rolesA = $portal->roles($meA);
$check('their own positions only', count($rolesA) === 1 && (int) $rolesA[0]['id'] === $roleA);
$check('…with 2 candidates sent, both waiting for a decision', (int) $rolesA[0]['sent'] === 2 && (int) $rolesA[0]['undecided'] === 2);
$cands = $portal->candidates($meA, $roleA);
$names = array_map(fn ($c) => $c['p']['name'] ?? '', $cands);
sort($names);
$check('the candidates sent to them, by name [' . implode(', ', $names) . ']', $names === ['Asha Portaltest', 'Bala Portaltest']);
$check('…not the one still in a draft shortlist', !in_array('Chitra Portaltest', $names, true));
$check('…with their email and phone (Benjamin, 14 Nov 2026)', str_contains(json_encode($cands), '@candidates.test') && !empty($cands[0]['p']['phone']));
$check('…each "Waiting for your decision"', $cands[0]['state'] === ['review', 'Waiting for your decision']);
$check("another client's position is not theirs", $portal->role($meA, $roleB) === null && $portal->candidates($meA, $roleB) === [] && $portal->role($meB, $roleA) === null);
$itemB = (int) $db->col('SELECT id FROM shortlist_items WHERE shortlist_id = ?', [$slB]);
$itemA1 = (int) $db->col('SELECT id FROM shortlist_items WHERE shortlist_id = ? AND application_id = ?', [$slA, $a1]);
$itemA2 = (int) $db->col('SELECT id FROM shortlist_items WHERE shortlist_id = ? AND application_id = ?', [$slA, $a2]);
$check("…nor another client's candidate, even asked for by number", $portal->item($meA, $roleA, $itemB) === null && $portal->item($meA, $roleB, $itemB) === null && $portal->item($meB, $roleB, $itemB) !== null);
$it = $portal->item($meA, $roleA, $itemA1);
$pdf = $sl->pdf($it['shortlist'], [$it]);
$check('a candidate\'s profile opens as a PDF', str_starts_with($pdf, '%PDF') && strlen($pdf) > 2000);
$portal->markViewed($meA, $roleA);
$check('opening the position marks its shortlist as viewed', $db->col('SELECT status FROM shortlists WHERE id = ?', [$slA]) === 'viewed' && $db->col('SELECT status FROM shortlists WHERE id = ?', [$slB]) === 'sent');
$db->run("UPDATE shortlists SET status = 'closed' WHERE id = ?", [$slA]);
$check('a shortlist you closed is no longer shown', $portal->candidates($meA, $roleA) === []);
$db->run("UPDATE shortlists SET status = 'viewed' WHERE id = ?", [$slA]);
$slA2 = $send($roleA, [$a1], "hr.alpha.$tag@clients.test");
$itemA1b = (int) $db->col('SELECT id FROM shortlist_items WHERE shortlist_id = ?', [$slA2]);
$cands = $portal->candidates($meA, $roleA);
$check('sent twice: the person is shown once, in the newer version', count($cands) === 2 && in_array($itemA1b, array_map(fn ($c) => (int) $c['id'], $cands), true) && !in_array($itemA1, array_map(fn ($c) => (int) $c['id'], $cands), true));

echo "\nDecisions\n";
$before = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ?", ["%ZZ Portal Alpha $tag replied%"]);
$n = $portal->saveDecisions($meA, $roleA, ['decision' => [$itemA1b => 'shortlist', $itemA2 => 'reject', $itemB => 'shortlist'], 'comment' => [$itemA1b => 'Please arrange a call.', $itemA2 => '']]);
$row = $db->one('SELECT * FROM shortlist_items WHERE id = ?', [$itemA1b]);
$check('decisions and comments are saved, with who decided', $n === 2 && $row['decision'] === 'shortlist' && $row['comment'] === 'Please arrange a call.' && (int) $row['decided_by_contact_id'] === $cA);
$check("…another client's candidate slipped into the form is ignored", $db->col('SELECT decision FROM shortlist_items WHERE id = ?', [$itemB]) === null);
$check('…the shortlist counts as answered', $db->col('SELECT status FROM shortlists WHERE id = ?', [$slA2]) === 'answered');
$mail = $db->one("SELECT * FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ? ORDER BY id DESC LIMIT 1", ["%ZZ Portal Alpha $tag replied%"]);
$check('…and the owners are told what changed', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ?", ["%ZZ Portal Alpha $tag replied%"]) > $before
    && str_contains($mail['text'], 'Asha Portaltest: Shortlist') && str_contains($mail['text'], 'Please arrange a call.') && str_contains($mail['text'], 'Meera Alpha'));
$check('saving the same answers again changes nothing and tells nobody', $portal->saveDecisions($meA, $roleA, ['decision' => [$itemA1b => 'shortlist', $itemA2 => 'reject'], 'comment' => [$itemA1b => 'Please arrange a call.', $itemA2 => '']]) === 0);
$cands = $portal->candidates($meA, $roleA);
$st = array_column(array_map(fn ($c) => ['id' => (int) $c['id'], 's' => $c['state'][1]], $cands), 's', 'id');
$check("the candidate's status follows [{$st[$itemA1b]}]", $st[$itemA1b] === 'Your decision: Shortlist' && (int) $portal->roles($meA)[0]['undecided'] === 0);

echo "\nWhere a candidate stands (the client's words)\n";
$s = fn (array $r) => ClientPortal::state($r + ['stage' => 'Submitted to client', 'stage_kind' => 'active', 'interview' => null, 'decision' => null])[1];
$check('booked interview: the day and time', $s(['interview' => ['status' => 'booked', 'starts_at' => '2026-11-14 17:00:00', 'outcome' => null]]) === 'Interview on Sat 14 Nov, 5:00 pm');
$check('choosing a time', $s(['interview' => ['status' => 'choosing', 'starts_at' => null, 'outcome' => null]]) === 'Choosing an interview time');
$check('offer, joined, withdrew, closed, on hold', $s(['stage' => 'Offer made']) === 'Offer made' && $s(['stage' => 'Joined', 'stage_kind' => 'success']) === 'Joined'
    && $s(['stage' => 'Withdrew', 'stage_kind' => 'fail']) === 'Withdrew' && $s(['stage' => 'Not suitable', 'stage_kind' => 'fail']) === 'No longer in process' && $s(['stage' => 'Future requirement', 'stage_kind' => 'hold']) === 'On hold');

echo "\nInterview times: a window and an interview length\n";
$w = fn (string $from, string $to, int $min) => array_map(fn ($t) => substr($t, 11), ClientPortal::windowTimes('2026-11-14', $from, $to, $min));
$check('5–6 pm at 20 minutes: 5:00, 5:20, 5:40', $w('17:00', '18:00', 20) === ['17:00', '17:20', '17:40']);
$check('…at 30 minutes: 5:00, 5:30', $w('17:00', '18:00', 30) === ['17:00', '17:30']);
$check('…at 1 hour: 5:00', $w('17:00', '18:00', 60) === ['17:00']);
$check('…at 45 minutes: one interview fits', $w('17:00', '18:00', 45) === ['17:00']);
$check('a window shorter than one interview, or ending before it starts, gives nothing', $w('17:00', '17:20', 30) === [] && $w('18:00', '17:00', 30) === [] && ClientPortal::windowTimes('14/11/2026', '17:00', '18:00', 30) === []);
$check('a whole day is capped at 40 times', count(ClientPortal::windowTimes('2026-11-14', '08:00', '23:00', 15)) === 40);
// Several days at once (16 Nov 2026): "1 hour each on 2 days".
$two = ClientPortal::daysTimes(['date' => ['2026-11-20', '2026-11-18', ''], 'from' => ['16:00', '10:00', ''], 'to' => ['17:00', '11:00', '']], 30);
$check('two days, an hour each at 30 minutes: four times, earliest first, the blank row skipped', $two === ['2026-11-18 10:00', '2026-11-18 10:30', '2026-11-20 16:00', '2026-11-20 16:30']);
$check('the old single-day form still works', ClientPortal::daysTimes(['date' => '2026-11-14', 'from' => '17:00', 'to' => '18:00'], 20) === ['2026-11-14 17:00', '2026-11-14 17:20', '2026-11-14 17:40']);
$check('the same window twice gives each time once', count(ClientPortal::daysTimes(['date' => ['2026-11-18', '2026-11-18'], 'from' => ['10:00', '10:00'], 'to' => ['11:00', '11:00']], 30)) === 2);
$check('your email lists each day: "Wed 18 Nov, 10:00 am to 11:00 am; Fri 20 Nov, 4:00 pm to 5:00 pm"', ClientPortal::timesSummary($two, 30) === 'Wed 18 Nov, 10:00 am to 11:00 am; Fri 20 Nov, 4:00 pm to 5:00 pm');
$day = date('Y-m-d', strtotime('+3 days'));
$before = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ?", ["%ZZ Portal Alpha $tag offered interview times%"]);
[$n, $problem] = $portal->offerTimes($meA, $roleA, ['date' => $day, 'from' => '17:00', 'to' => '18:00', 'length' => '20', 'kind' => 'virtual', 'round' => '1']);
$slots = $db->all('SELECT * FROM interview_slots WHERE role_id = ? ORDER BY starts_at', [$roleA]);
$check('the client offers 5–6 pm at 20 minutes: three times of 20 minutes, marked as theirs', $n === 3 && $problem === '' && count($slots) === 3
    && (int) $slots[0]['duration_min'] === 20 && $slots[0]['source'] === 'client' && substr($slots[1]['starts_at'], 11, 5) === '17:20');
$mail = $db->one("SELECT * FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ? ORDER BY id DESC LIMIT 1", ["%ZZ Portal Alpha $tag offered interview times%"]);
$check('…and the owners are told, to invite the candidates', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ?", ["%ZZ Portal Alpha $tag offered interview times%"]) > $before
    && str_contains($mail['text'], '3 interview times of 20 minutes') && str_contains($mail['text'], '5:00 pm to 6:00 pm'));
[$n2, $p2] = $portal->offerTimes($meA, $roleA, ['date' => $day, 'from' => '17:00', 'to' => '18:00', 'length' => '20']);
$check("the same times are not offered twice [$p2]", $n2 === 0 && $p2 !== '');
[$n3, $p3] = $portal->offerTimes($meA, $roleA, ['date' => date('Y-m-d', strtotime('-1 day')), 'from' => '10:00', 'to' => '11:00', 'length' => '30']);
$check('times in the past are refused', $n3 === 0 && $p3 !== '');
$check("no times on another client's position", $portal->offerTimes($meA, $roleB, ['date' => $day, 'from' => '10:00', 'to' => '11:00', 'length' => '30'])[0] === 0);
$sched = $portal->schedule($meA, $roleA);
$check('the times offered are listed for the client', count($sched['free']) === 3 && $portal->schedule($meA, $roleB)['free'] === []);
$check("another client cannot withdraw them", $portal->removeSlot($meB, (int) $slots[0]['id']) === null);
$check('the client withdraws one of their own', $portal->removeSlot($meA, (int) $slots[0]['id']) === $roleA && count($portal->schedule($meA, $roleA)['free']) === 2);
$db->run('UPDATE interview_slots SET interview_id = 999999 WHERE id = ?', [$slots[1]['id']]);
$check('…but not one a candidate has taken', $portal->removeSlot($meA, (int) $slots[1]['id']) === null);

echo "\nAsking for a new position\n";
$check('a job title is needed', is_string($portal->requestPosition($meA, ['title' => ' '])));
$rid = $portal->requestPosition($meA, ['title' => "Payroll Executive ZZ$tag", 'location' => 'Pune', 'work_mode' => 'hybrid', 'positions' => '2', 'exp_min' => '5', 'exp_max' => '2',
    'ctc_max_lpa' => '4.5', 'notice_max_days' => '30', 'jd_text' => 'Payroll for 300 staff. PF, ESI.', 'note' => 'Replacement, needed by December.']);
$r = is_int($rid) ? $db->one('SELECT * FROM roles WHERE id = ?', [$rid]) : null;
$check('it arrives as a draft for this client, with who asked and their note', $r && $r['status'] === 'draft' && (int) $r['client_id'] === $clientA && (int) $r['requested_by_contact_id'] === $cA
    && $r['request_note'] === 'Replacement, needed by December.' && (int) $r['positions'] === 2 && (float) $r['ctc_max_lpa'] === 4.5 && $r['work_mode'] === 'hybrid');
$check('…experience the wrong way round is put right, and it gets a code', (float) $r['exp_min'] === 2.0 && (float) $r['exp_max'] === 5.0 && preg_match('/^[A-Z]{3}-\d{4,}$/', (string) $r['code']) === 1);
$check('…suggested as "Local candidates only" (a junior post)', $r['location_scope'] === 'local');
$mine = $portal->roles($meA);
$check('the client sees it as requested; it cannot be opened yet', in_array($rid, array_map(fn ($x) => (int) $x['id'], $mine), true) && $portal->role($meA, $rid) === null);
$check('…and the other client does not see it', !in_array($rid, array_map(fn ($x) => (int) $x['id'], $portal->roles($meB)), true));
$check('it waits on My day until you open it', in_array($rid, array_map(fn ($x) => (int) $x['id'], $portal->pendingRequests()), true));
$mail = $db->one("SELECT * FROM email_queue WHERE kind = 'client_reply' AND subject LIKE ? ORDER BY id DESC LIMIT 1", ["%asked for a new position: Payroll Executive ZZ$tag%"]);
$check('…and the owners are emailed the details', $mail && str_contains($mail['text'], 'Replacement, needed by December.') && str_contains($mail['text'], "/roles/$rid/edit"));

echo "\nTaking access away\n";
$portal->revoke($cA);
$check('the sign-in ends at once', $portal->contactBySession($sessA) === null);
$check('…unused links stop working, and no new one is sent', (int) $db->col('SELECT COUNT(*) FROM client_logins WHERE contact_id = ?', [$cA]) === 0 && $portal->requestLink("meera.alpha.$tag@clients.test", '10.0.0.1') === false);
$check('the other client is not affected', (int) $db->col('SELECT portal_access FROM client_contacts WHERE id = ?', [$cB]) === 1);
$db->run('UPDATE clients SET is_active = 0 WHERE id = ?', [$clientB]);
$check('a client you mark inactive cannot sign in', $portal->requestLink("vikram.beta.$tag@clients.test", '10.0.0.1') === false && $portal->peek($portal->issue($cB, 'signin', null)) === null);
