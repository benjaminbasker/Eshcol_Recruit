<?php
/**
 * The WhatsApp messages Benjamin or a stage change starts (update 082, 28 Nov 2026): ask for a call (with the signed
 * "No longer interested" link, which withdraws the application), "we tried to call you", "selected" on Offer made and
 * "not selected" on Client rejected (once, switchable, never to anyone red-listed), and the joining reminder the
 * evening before. Nothing is sent: messages are queued only. LOCAL ONLY: throwaway "ZZ" records.
 * Run: php tests/wamessages-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Migrator;
use Eshcol\Ats\Services\StageMover;
use Eshcol\Ats\Services\Stages;
use Eshcol\Ats\Services\WaMessages;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
(new Migrator($app))->run();
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = bin2hex(random_bytes(3));
$client = $db->insert('clients', ['name' => "ZZ WaMsg $tag", 'legal_name' => "ZZ WaMsg Industries $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Trainee ZZ$tag", 'location' => 'Thiruvallur', 'status' => 'open', 'engagement' => 'staffing']);
$applied = (int) $db->col("SELECT id FROM stages ORDER BY sort LIMIT 1");
$cand = $db->insert('candidates', ['full_name' => "Zz Wamsg Priya", 'email' => "zz.wamsg.$tag@example.test", 'phone' => '98765 12801', 'source' => 'manual']);
$red = $db->insert('candidates', ['full_name' => "Zz Wamsg Red", 'email' => "zz.wamsg.red.$tag@example.test", 'phone' => '98765 12802', 'source' => 'manual', 'red_listed_at' => date('Y-m-d H:i:s')]);
$app1 = $db->insert('applications', ['candidate_id' => $cand, 'role_id' => $role, 'stage_id' => $applied, 'source' => 'upload']);
$app2 = $db->insert('applications', ['candidate_id' => $red, 'role_id' => $role, 'stage_id' => $applied, 'source' => 'upload']);
$M = new WaMessages($app);
$q = fn (string $tpl, int $cid) => $db->one('SELECT * FROM whatsapp_queue WHERE template = ? AND candidate_id = ? ORDER BY id DESC LIMIT 1', [$tpl, $cid]);
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");

try {
    echo "Ask for a call\n";
    $check('a day in the past is refused', $M->callRequest($app1, date('Y-m-d', strtotime('-1 day')), '11:00', '13:00') !== null);
    $check('an end before the start is refused', $M->callRequest($app1, date('Y-m-d'), '13:00', '11:00') !== null);
    $day = date('Y-m-d', strtotime('+1 day'));
    $check('queued', $M->callRequest($app1, $day, '11:00', '13:00') === null);
    $r = $q('call_request', $cand);
    $vars = json_decode((string) $r['variables'], true);
    $btn = json_decode((string) $r['button_vars'], true)[0] ?? '';
    $check('with the name, role, day and window', $vars === ['Zz', "Trainee ZZ$tag", date('l j F', strtotime($day)), '11:00 AM', '1:00 PM']);
    $check("the button carries this application's signed token", $M->appFromToken($btn) === $app1);
    $check('a changed or made-up token is refused', $M->appFromToken($app2 . substr($btn, strlen((string) $app1))) === null && $M->appFromToken('12345') === null);

    echo "No longer interested\n";
    $check('withdrawn through the link', $M->withdraw($app1) === null && Stages::is($app, (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$app1]), Stages::WITHDREW));
    $check('with a note on the candidate', (bool) $db->col("SELECT 1 FROM notes WHERE entity_type = 'candidate' AND entity_id = ? AND body LIKE '%no longer interested%'", [$cand]));

    echo "We tried to call you\n";
    $check('queued with the name and role', $M->missedCall($app1) === null && json_decode((string) $q('missed_call', $cand)['variables'], true) === ['Zz', "Trainee ZZ$tag"]);

    echo "Stage changes\n";
    $offer = Stages::id($app, Stages::OFFER);
    $rej = Stages::id($app, Stages::CLIENT_REJECTED);
    $a1 = $db->one('SELECT * FROM applications WHERE id = ?', [$app1]);
    (new StageMover($app))->move($a1, $offer, null, $owner, true);
    $s = $q('selected', $cand);
    $check('Offer made: "selected", with the client\'s legal name', $s && json_decode((string) $s['variables'], true) === ['Zz', "Trainee ZZ$tag", "ZZ WaMsg Industries $tag"]);
    $M->afterStage($app1, $offer);
    $check('only once per application', (int) $db->col("SELECT COUNT(*) FROM whatsapp_queue WHERE template = 'selected' AND candidate_id = ?", [$cand]) === 1);
    if ($rej) {
        $a2 = $db->one('SELECT * FROM applications WHERE id = ?', [$app2]);
        (new StageMover($app))->move($a2, $rej, 'Zz', $owner, true);
        $check('Client rejected: nothing for someone red-listed', !$q('not_selected', $red));
        $db->run("INSERT INTO settings (k, v) VALUES ('wa_auto_not_selected', '0') ON DUPLICATE KEY UPDATE v = '0'");
        $M->afterStage($app1, $rej);
        $check('switched off: nothing', !$q('not_selected', $cand));
        $db->run("UPDATE settings SET v = '1' WHERE k = 'wa_auto_not_selected'");
        $M->afterStage($app1, $rej);
        $check('switched on: "not selected"', (bool) $q('not_selected', $cand));
    }

    echo "Joining reminder\n";
    $j = $db->insert('joinings', ['application_id' => $app1, 'candidate_id' => $cand, 'full_name' => 'Zz Wamsg Priya', 'engagement' => 'staffing', 'joining_date' => date('Y-m-d', strtotime('+1 day')), 'status' => 'ready',
        'payload' => json_encode(['job' => ['designation' => 'Trainee Technician', 'work_location' => 'Thiruvallur plant']])]);
    $out = $M->joiningReminders();
    $jr = $q('joining_reminder', $cand);
    $check('queued the evening before: ' . $out, $jr && json_decode((string) $jr['variables'], true)[1] === 'Trainee Technician'
        && str_starts_with(json_decode((string) $jr['variables'], true)[2], 'tomorrow, ') && str_contains(json_decode((string) $jr['variables'], true)[3], 'Thiruvallur plant'));
    $check('and only once', $M->joiningReminders() === '');

    echo "Remind about the details form\n";
    $c3 = $db->insert('candidates', ['full_name' => "Zz Wamsg Kavya", 'first_name' => 'Kavya', 'email' => "zz.wamsg.k.$tag@example.test", 'phone' => '98765 12803', 'phone_norm' => '9876512803', 'source' => 'manual']);
    $app3 = $db->insert('applications', ['candidate_id' => $c3, 'role_id' => $role, 'stage_id' => $applied, 'source' => 'upload']);
    $Q = new \Eshcol\Ats\Services\Questionnaires($app);
    $lead = $Q->create($app3, null);
    $db->run("UPDATE questionnaires SET status = 'expired', expires_at = NOW() - INTERVAL 1 DAY WHERE id = ?", [$lead]);
    $w = $M->detailsWaiting([$role]);
    $check('someone who has not answered (link run out) is waiting', count(array_filter($w, fn ($p) => $p['candidate_id'] === $c3)) === 1);
    $check('and someone withdrawn is not', !array_filter($w, fn ($p) => $p['candidate_id'] === $cand));
    $when = date('Y-m-d', strtotime('+1 day')) . ' 10:00:00';
    $check('queued', $M->queueDetailsReminders([$role], $when) >= 1);
    $r3 = $db->one("SELECT * FROM whatsapp_queue WHERE candidate_id = ? AND related_type = 'details_reminder'", [$c3]);
    $check('for tomorrow at 10 am, with the name and position', $r3 && $r3['not_before'] === $when && json_decode((string) $r3['variables'], true) === ['Kavya', "Trainee ZZ$tag"]);
    $btn3 = json_decode((string) $r3['button_vars'], true)[0] ?? '';
    $open = $Q->byToken($btn3);
    $check('the button opens their own form, open again', $open && (int) $open['id'] === $lead && $open['status'] === 'sent' && $open['expires_at'] > $when);
    $check('not queued twice', $M->queueDetailsReminders([$role], $when) === 0);
    $check('shown as queued', $M->detailsRemindersQueued()[0] >= 1);
    $check('cancel: nothing goes', $M->cancelDetailsReminders() >= 1 && $db->col('SELECT status FROM whatsapp_queue WHERE id = ?', [$r3['id']]) === 'cancelled');
} finally {
    $db->run('DELETE FROM whatsapp_queue WHERE candidate_id IN (?, ?)', [$cand, $red]);
    if (isset($c3)) {
        $db->run('DELETE FROM whatsapp_queue WHERE candidate_id = ?', [$c3]);
        $db->run('DELETE FROM questionnaires WHERE candidate_id = ?', [$c3]);
        $db->run('DELETE FROM applications WHERE candidate_id = ?', [$c3]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$c3]);
    }
    $db->run('DELETE FROM joinings WHERE application_id IN (?, ?)', [$app1, $app2]);
    $db->run("DELETE FROM notes WHERE entity_type = 'candidate' AND entity_id IN (?, ?)", [$cand, $red]);
    $db->run('DELETE FROM stage_history WHERE application_id IN (?, ?)', [$app1, $app2]);
    $db->run('DELETE FROM client_rejections WHERE candidate_id IN (?, ?)', [$cand, $red]);
    $db->run('DELETE FROM placements WHERE application_id IN (?, ?)', [$app1, $app2]);
    $db->run('DELETE FROM applications WHERE id IN (?, ?)', [$app1, $app2]);
    $db->run('DELETE FROM candidates WHERE id IN (?, ?)', [$cand, $red]);
    $db->run('DELETE FROM roles WHERE id = ?', [$role]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
}

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
