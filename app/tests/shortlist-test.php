<?php
/**
 * Client shortlists, end to end at the service level, on a throwaway role and fictional
 * candidates. Nothing is emailed (this PC is always in test mode).
 * Cleans up after itself, even if a check crashes. LOCAL ONLY. Run: php tests/shortlist-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Questionnaires;
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
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$tag = bin2hex(random_bytes(3));
$routingWas = $app->setting('routing_enabled', '1');
$db->run("UPDATE settings SET v = '0' WHERE k = 'routing_enabled'");
$made = [];
$clientId = $db->insert('clients', ['name' => "ZZ Client $tag"]);
$tmp = $app->storage("uploads-tmp/zz-sl-$tag.txt");
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $tmp, $tag, $maxEmail, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
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

$role = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Officer', 'location' => 'Pune', 'status' => 'open', 'code' => "ZZS-$tag", 'score_threshold' => 0, 'ctc_max_lpa' => 6]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'Tally']);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'GST']);
$gst = $db->insert('role_questions', ['role_id' => $role, 'sort' => 0, 'kind' => 'yesno', 'prompt' => 'Have you filed GST returns yourself?']);
$imp = new CandidateImporter($app);
$person = function (string $name) use ($imp, $tmp, $uid, $tag, $db, $role, &$made): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 98765 4" . random_int(1000, 9999) . "\nPune\nAccounts Executive, Lotus Foods, 2021 – Present\n4 years of experience in Tally and GST returns.\nExpected CTC: 5.5 LPA\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $role, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $role])];
};
$stage = fn (string $name) => (int) $db->col('SELECT id FROM stages WHERE name = ?', [$name]);
[$a, $aa] = $person('Anand Shortlist');
[$b, $ba] = $person('Bina Shortlist');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$stage('Shortlisted'), $aa]);
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$stage('Client interview'), $ba]); // already further along
// Anand answers the screening questions
$qs = new Questionnaires($app);
$qid = $qs->create($aa, $uid);
$qs->submit($qs->byToken((string) \Eshcol\Ats\Secrets::open($app, (string) $db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$qid]), 'questionnaire')),
    ['full_name' => (string) $db->col('SELECT c.full_name FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$aa]), 'experience' => '3', 'ctc_current' => '4.2', 'ctc_expected' => '5.5', 'ctc_min' => '5', 'notice' => '4 weeks', 'location' => 'Pune', 'locality' => 'Baner', 'relocate' => 'Yes', 'permission' => 'Yes', "q$gst" => 'Yes', 'keep' => '1']);
$svc = new Shortlists($app);

echo "Profiles\n";
$sum = $svc->autoSummary($aa);
$check('the draft line mentions experience, current job and matched skills: "' . $sum . '"', str_contains($sum, 'experience') && str_contains($sum, 'Accounts Executive') && str_contains($sum, 'Tally'));
$p = $svc->profile($aa, true);
$json = json_encode($p);
// Benjamin, 14 Nov 2026: clients always get the contact details.
$check('the client sees their email and phone', str_contains($json, '@candidates.test') && !empty($p['phone']));
$check('salary shown when you choose (current, expected and lowest)', ($p['ctc_current'] ?? '') === '4.2 LPA' && ($p['ctc_expected'] ?? '') === '5.5 LPA' && ($p['ctc_min'] ?? '') === '5 LPA');
$check('…and hidden when you don\'t', !isset($svc->profile($aa, false)['ctc_expected']) && !isset($svc->profile($aa, false)['ctc_min']));
$check('their own screening answers are included (not salary or "keep my details")', in_array('Have you filed GST returns yourself?', array_column($p['answers'] ?? [], 'q'), true) && !str_contains($json, 'Keep my'));
$check('requirements they meet are listed', in_array('Tally', $p['matched'] ?? [], true) && in_array('GST', $p['matched'] ?? [], true));

echo "\nSending\n";
$id = $svc->create($role, [[$aa, 'Strong on GST. Can join in 30 days.'], [$ba, '']], 'Rekha Client', "rekha.$tag@gmail.com", "hr.$tag@gmail.com", 'As discussed, two strong options.', true, $uid, 'profiles');
$check('it starts as a draft: nothing sent, nobody moved', $db->col('SELECT status FROM shortlists WHERE id = ?', [$id]) === 'draft' && !$db->col('SELECT email_id FROM shortlists WHERE id = ?', [$id])
    && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aa]) === $stage('Shortlisted'));
$check('the client\'s link does not open a draft', ($svc->byToken(substr($svc->link($id), strrpos($svc->link($id), '/s/') + 3))['status'] ?? '') === 'draft');
$check('sending the draft works', $svc->send($id, $uid) === null && $svc->send($id, $uid) !== null);
$s = $db->one('SELECT * FROM shortlists WHERE id = ?', [$id]);
$check('shortlist created with both candidates', (int) $db->col('SELECT COUNT(*) FROM shortlist_items WHERE shortlist_id = ?', [$id]) === 2);
$link = $svc->link($id);
$token = substr($link, strrpos($link, '/s/') + 3);
$check('the private link opens it; the code is only stored hashed and encrypted', ($svc->byToken($token)['id'] ?? 0) == $id && $s['token_hash'] === hash('sha256', $token) && !str_contains($s['token_enc'], $token));
$e = $db->one('SELECT * FROM email_queue WHERE id = ?', [$s['email_id']]);
$check('email to the client: subject, greeting, note, feedback button, link', $e['subject'] === "Candidates for Accounts Officer (ZZS-$tag)" && str_contains($e['html'], 'Rekha,')
    && str_contains($e['html'], 'As discussed, two strong options.') && str_contains($e['html'], 'Give your feedback') && str_contains($e['html'], "/s/$token"));
$check('…no merge field left, and CC carried along', !str_contains($e['html'] . $e['text'], '{{') && Mailer::ccList($e) === ["hr.$tag@gmail.com", 'benjamin@eshcolglobal.com']);
$check('a shortlisted candidate moves to "Submitted to client"', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aa]) === $stage('Submitted to client')
    && (bool) $db->col('SELECT 1 FROM stage_history WHERE application_id = ? AND to_stage_id = ?', [$aa, $stage('Submitted to client')]));
$check('one already further along (client interview) is not moved back', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$ba]) === $stage('Client interview'));
$check('the client\'s copy is a snapshot: later edits don\'t change it', (function () use ($db, $a, $svc, $id) {
    $db->run('UPDATE candidates SET full_name = ? WHERE id = ?', ['Renamed Later', $a]);
    $names = array_map(fn ($i) => $i['p']['name'], $svc->items($id));
    $db->run('UPDATE candidates SET full_name = ? WHERE id = ?', ['Anand Shortlist', $a]);
    return in_array('Anand Shortlist', $names, true);
})());

echo "\nThe PDF\n";
$pdf = $svc->pdf($s + ['title' => 'Accounts Officer', 'code' => "ZZS-$tag"], $svc->items($id));
$text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
$check('a real PDF, one page per candidate', str_starts_with($pdf, '%PDF') && substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages') === 2);
$check('it has the names, your view and the salary (₹ figures readable)', str_contains($text, 'Anand Shortlist') && str_contains($text, 'Strong on GST') && str_contains($text, '5.5 LPA'));
$check('with their email and phone', str_contains($text, '@candidates.test') && str_contains($text, 'Phone'));

echo "\nThe client replies\n";
$items = $svc->items($id);
$byName = array_column($items, null, 'application_id');
$s = $svc->byToken($token);
$svc->markViewed($s);
$check('opening the link is recorded', $db->col('SELECT status FROM shortlists WHERE id = ?', [$id]) === 'viewed');
$before = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND id > ?", [$maxEmail]);
$n = $svc->saveFeedback($s, ['decision' => [$byName[$aa]['id'] => 'shortlist', $byName[$ba]['id'] => 'nonsense'], 'comment' => [$byName[$aa]['id'] => 'Please arrange a call on Thursday']]);
$check('decisions saved; a made-up one is ignored', $n === 1 && $db->col('SELECT decision FROM shortlist_items WHERE id = ?', [$byName[$aa]['id']]) === 'shortlist'
    && $db->col('SELECT decision FROM shortlist_items WHERE id = ?', [$byName[$ba]['id']]) === null);
$check('the shortlist is marked as replied', $db->col('SELECT status FROM shortlists WHERE id = ?', [$id]) === 'answered');
$note = $db->one("SELECT * FROM email_queue WHERE kind = 'client_reply' AND id > ? ORDER BY id DESC LIMIT 1", [$maxEmail]);
$check('you get an email naming the candidate, decision and comment', $note && str_contains($note['subject'], "ZZ Client $tag replied about Accounts Officer") && str_contains($note['html'], 'Anand Shortlist')
    && str_contains($note['html'], 'Shortlist') && str_contains($note['html'], 'Please arrange a call on Thursday'));
$check('nobody is moved automatically by the client\'s decision', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$aa]) === $stage('Submitted to client'));
$svc->saveFeedback($s, ['decision' => [$byName[$aa]['id'] => 'shortlist'], 'comment' => [$byName[$aa]['id'] => 'Please arrange a call on Thursday']]);
$check('saving again with no change sends no second email', (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'client_reply' AND id > ?", [$maxEmail]) === $before + (int) $db->col("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role = 'owner'"));

echo "\nBranded CVs, in Benjamin's email format\n";
[$c3, $c3a] = $person('Chitra Cvmode');
$db->run('UPDATE candidates SET ctc_current_lpa = 3.24, ctc_expected_lpa = 4, notice_days = 0 WHERE id = ?', [$c3]);
$cid = $svc->create($role, [[$c3a, 'Recently moved to Pune']], 'Radhika Daga', "radhika.$tag@gmail.com", '', '', true, $uid);
$check('a CV shortlist can\'t be sent until its CVs are ready', str_contains((string) $svc->send($cid, $uid), 'still being prepared'));
$item = $svc->items($cid)[0];
$sl = $db->one('SELECT s.*, r.title, r.code FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$cid]);
$cover = $svc->coverPdf($sl, $item);
$ctext = (new \Smalot\PdfParser\Parser())->parseContent($cover)->getText();
$check('cover page: reference, remarks, CTC in rupees, your contact', str_contains($ctext, ref_candidate($c3)) && str_contains($ctext, 'Recently moved to Pune') && str_contains($ctext, '3,24,000') && str_contains($ctext, 'YOUR CONTACT AT ESHCOL GLOBAL'));
$built = $svc->brandedFromContent($sl, $item, '<h1>Chitra</h1><p>Accounts executive</p><script>alert(1)</script>');
$btext = (new \Smalot\PdfParser\Parser())->parseContent($built)->getText();
$check('a Word CV becomes cover + CV, with our reference line on its pages', str_contains($btext, 'Accounts executive') && str_contains($btext, 'Ref ' . ref_candidate($c3)) && !str_contains($btext, 'alert'));
$svc->storeBranded((int) $item['id'], $built);
$check('once ready, it sends', $svc->send($cid, $uid) === null);
// 16 Nov 2026: the client sees how far each candidate lives from their office.
$check('distance for the client: "About 7 km from your Balewadi office"', Shortlists::distanceLine(['level' => 'moderate', 'km' => 7], 'Balewadi') === 'About 7 km from your Balewadi office');
$check('…"Under 2 km" when very close, and words when no km is known', Shortlists::distanceLine(['level' => 'near', 'km' => 1], 'Balewadi') === 'Under 2 km from your Balewadi office'
    && Shortlists::distanceLine(['level' => 'far'], 'Balewadi') === 'Far from your Balewadi office');
$check('…nothing when it can\'t be said (another city, no locality, no office)', Shortlists::distanceLine(['level' => 'other_city', 'label' => 'Lives in Mumbai'], 'Balewadi') === null
    && Shortlists::distanceLine(['level' => 'same_city'], 'Balewadi') === null && Shortlists::distanceLine(['level' => 'near', 'km' => 3], null) === null);
// 16 Nov 2026: benjamin@eshcolglobal.com is copied on every client email (not on the portal sign-in link).
$sent = $db->one("SELECT * FROM email_queue WHERE kind = 'shortlist' AND to_email = ? ORDER BY id DESC LIMIT 1", ["radhika.$tag@gmail.com"]);
$check('the client\'s shortlist email is copied to benjamin@eshcolglobal.com', in_array('benjamin@eshcolglobal.com', \Eshcol\Ats\Services\Mailer::ccList($sent ?: []), true));
$mq = new \Eshcol\Ats\Services\Mailer($app);
$ivMail = $mq->enqueue(['kind' => 'client_interview', 'to_email' => "client.$tag@gmail.com", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'status' => 'held', 'hold_reason' => 'test']);
$portalMail = $mq->enqueue(['kind' => 'portal', 'to_email' => "client.$tag@gmail.com", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'status' => 'held', 'hold_reason' => 'test']);
$candMail = $mq->enqueue(['kind' => 'thanks', 'to_email' => "cand.$tag@gmail.com", 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'status' => 'held', 'hold_reason' => 'test']);
$ccOf = fn (int $id) => \Eshcol\Ats\Services\Mailer::ccList($db->one('SELECT * FROM email_queue WHERE id = ?', [$id]) ?: []);
$check('interview emails to the client are copied too', $ccOf($ivMail) === ['benjamin@eshcolglobal.com']);
$check('…but not the portal sign-in link, nor candidate emails', $ccOf($portalMail) === [] && $ccOf($candMail) === []);
$db->run('DELETE FROM email_queue WHERE id IN (?, ?, ?)', [$ivMail, $portalMail, $candMail]);
[$subject, $html, $text, $att] = $svc->emailContent($cid);
$check('subject like yours: "Accounts Officer Profiles (code)"', $subject === "Accounts Officer Profiles (ZZS-$tag)");
$check('opening line like yours', str_contains($html, 'Radhika,') && str_contains($html, 'I am attaching one profile for the Accounts Officer position. Kindly review the same and let me know your feedback.'));
$check('the table: S.No, name, CTC as 3,24,000 / 4,00,000, notice, remarks', str_contains($html, 'S.No') && str_contains($html, '3,24,000') && str_contains($html, '4,00,000') && str_contains($html, 'Immediate') && str_contains($html, 'Recently moved to Pune'));
$check('the CV is attached and linked, with a "download all" link', count($att) === 1 && str_ends_with($att[0]['name'], 'Chitra Cvmode - Eshcol Global.pdf') && str_contains($html, '/cv/' . $item['id']) && str_contains($html, '/zip'));
$check('signed with your name, title and mobile from your profile', str_contains($html, 'Regards,') && str_contains($html, 'Website: www.eshcolglobal.com'));
// 16 Nov 2026: a mail program turned the client's link into "/s/<code>&routed=1" and it showed "Page not found".
$keepUri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/s/M3hPkSyVT6ueUPXVKQth4KV5gy9dOF2i&routed=1';
$p1 = \Eshcol\Ats\Http::path();
$_SERVER['REQUEST_URI'] = '/s/M3hPkSyVT6ueUPXVKQth4KV5gy9dOF2i?x=1&routed=1';
$p2 = \Eshcol\Ats\Http::path();
$_SERVER['REQUEST_URI'] = $keepUri;
$check('a link with "&routed=1" stuck on the end still opens the shortlist', $p1 === '/s/M3hPkSyVT6ueUPXVKQth4KV5gy9dOF2i' && $p2 === '/s/M3hPkSyVT6ueUPXVKQth4KV5gy9dOF2i');

echo "\nDeleting a candidate\n";
(new Retention($app))->delete($b, 'manual', false);
$check('they disappear from the client\'s shortlist too', count($svc->items($id)) === 1);
$bp = $app->storage((string) $db->col('SELECT branded_path FROM shortlist_items WHERE id = ?', [$item['id']]));
$wasThere = is_file($bp);
(new Retention($app))->delete($c3, 'manual', false);
$check('their branded CV file is deleted with them', $wasThere && !is_file($bp));
exit(0);
