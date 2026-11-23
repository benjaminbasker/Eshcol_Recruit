<?php
/**
 * 23 Nov 2026: the client's free times are ticked (days x times) instead of typed on the clock wheel, and a wrong
 * interview round is corrected without emailing anyone. LOCAL ONLY. Run: php tests/time-picker-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Interviews;

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

echo "Ticked days and times\n";
$t = Interviews::pickedTimes(['date' => ['2027-03-02', '2027-03-03'], 'clock' => ['18:00', '9:30']]);
$check('two days x two times = four times, in order', $t === ['2027-03-02 09:30', '2027-03-02 18:00', '2027-03-03 09:30', '2027-03-03 18:00']);
$t = Interviews::pickedTimes(['date' => ['2027-03-02'], 'clock' => ['18:00'], 'other_date' => '2027-03-09', 'other_time' => '16:45']);
$check('"another day" and "another time" are added to the ticked ones', $t === ['2027-03-02 16:45', '2027-03-02 18:00', '2027-03-09 16:45', '2027-03-09 18:00']);
$check('no day ticked: nothing', Interviews::pickedTimes(['clock' => ['18:00']]) === []);
$check('no time ticked: nothing', Interviews::pickedTimes(['date' => ['2027-03-02']]) === []);
$check('rubbish is ignored', Interviews::pickedTimes(['date' => ['2027-13-45', 'x', ['a']], 'clock' => ['25:00', '18:00', '<b>']]) === []);
$check('the same day twice counts once', count(Interviews::pickedTimes(['date' => ['2027-03-02', '2027-03-02'], 'other_date' => '2027-03-02', 'clock' => ['18:00']])) === 1);

echo "\nThe picker on the page\n";
ob_start();
$tpId = 'zz';
include dirname(__DIR__) . '/views/partials/time_picker.php';
$html = (string) ob_get_clean();
$check('twelve days to tick, no Sundays', substr_count($html, 'name="date[]"') === 12 && !str_contains($html, '<b>Sun</b>'));
$check('9:00 AM to 7:30 PM in half hours (22 times)', substr_count($html, 'name="clock[]"') === 22 && str_contains($html, '9:00 AM') && str_contains($html, '7:30 PM') && !str_contains($html, '8:00 PM'));
$check('another day and another time boxes', str_contains($html, 'name="other_date"') && str_contains($html, 'name="other_time"'));

echo "\nCorrecting the round\n";
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$client = $db->insert('clients', ['name' => "Zz Round Client $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Zz Accounting Manager $tag", 'location' => 'Pune', 'status' => 'open', 'score_threshold' => 0, 'accepts_routed' => 0]);
$cand = $db->insert('candidates', ['full_name' => "Zz Mangesh $tag", 'email' => "m.$tag@candidates.test", 'email_norm' => "m.$tag@candidates.test"]);
$stage = (int) $db->col('SELECT id FROM stages ORDER BY sort, id LIMIT 1');
$appId = $db->insert('applications', ['candidate_id' => $cand, 'role_id' => $role, 'stage_id' => $stage]);
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
register_shutdown_function(function () use ($db, $cand, $role, $client, $maxEmail, &$ok, &$bad) {
    $db->run('DELETE FROM notes WHERE entity_type = ? AND entity_id = ?', ['candidate', $cand]);
    $db->run('DELETE FROM interviews WHERE application_id IN (SELECT id FROM applications WHERE candidate_id = ?)', [$cand]);
    $db->run('DELETE FROM applications WHERE candidate_id = ?', [$cand]);
    $db->run('DELETE FROM candidates WHERE id = ?', [$cand]);
    $db->run('DELETE FROM roles WHERE id = ?', [$role]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$iv = $db->insert('interviews', ['application_id' => $appId, 'round' => 1, 'kind' => 'in_person', 'status' => 'booked',
    'starts_at' => date('Y-m-d 16:30:00', strtotime('+1 day')), 'duration_min' => 60]);
$svc = new Interviews($app);
[$okr, $msg] = $svc->correctRound($iv, 2, $uid);
$check('round 1 corrected to round 2', $okr && (int) $db->col('SELECT round FROM interviews WHERE id = ?', [$iv]) === 2);
$check('time, type and status unchanged', $db->col('SELECT CONCAT(status, kind, starts_at) FROM interviews WHERE id = ?', [$iv]) === 'bookedin_person' . date('Y-m-d 16:30:00', strtotime('+1 day')));
$check('round 1 recorded as held, through to the next round', (int) $db->col("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND round = 1 AND status = 'done' AND outcome = 'next_round'", [$appId]) === 1);
$check('nobody emailed', (int) $db->col('SELECT COUNT(*) FROM email_queue WHERE id > ?', [$maxEmail]) === 0);
$check('a note says so', (bool) $db->col("SELECT 1 FROM notes WHERE entity_type = 'candidate' AND entity_id = ? AND body LIKE '%corrected from round 1 to round 2%'", [$cand]));
$check('the next round to book is 2 (round 1 held)', $svc->nextRound($appId) === 2);
$roundNow = (int) $db->col('SELECT ' . str_replace('a.id', '?', \Eshcol\Ats\Services\KeyFacts::ROUND_SQL), [$appId]);
$check('the stage reads round 2', $roundNow === 2);
[$again] = $svc->correctRound($iv, 2, $uid);
$check('correcting to the same round is harmless', $again && (int) $db->col("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND status = 'done'", [$appId]) === 1);
$iv3 = $db->insert('interviews', ['application_id' => $appId, 'round' => 3, 'kind' => 'virtual', 'status' => 'choosing']);
[$clash] = $svc->correctRound($iv3, 2, $uid);
$check('cannot make two round 2 interviews', !$clash);
$db->run("UPDATE interviews SET status = 'done' WHERE id = ?", [$iv]);
[$done] = $svc->correctRound($iv, 3, $uid);
$check('a held interview is not changed here', !$done);
