<?php
/**
 * Analyser improvements 4 and 5a (approved 15 Nov 2026), on a throwaway position:
 *  - 5a: Claude's bands against your decisions (the stage each application is at) and the client's answers; the
 *    agreement figure; where you and Claude differ, with your reason;
 *  - 4: career direction asked of Claude, and scored inside the 10 points for stability and fit (rising +1, falling
 *    -2); assessments made before it score exactly as before.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/analyser-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Agreement as A;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FitAssessor as F;
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

echo "Your decision, from the stage\n";
$check('Applied / Screening: not decided yet', A::decision('active', 10, 'Applied') === 'open' && A::decision('active', 20, 'Screening') === 'open');
$check('Shortlisted, Submitted, Interview, Offer: taken forward', A::decision('active', 30, 'Shortlisted') === 'forward' && A::decision('active', 60, 'Offer made') === 'forward');
$check('Joined: taken forward', A::decision('success', 70, 'Joined') === 'forward');
$check('Future requirement: kept for later', A::decision('hold', 85, 'Future requirement') === 'later');
$check('Not suitable and "Rejected: insufficient information": turned down', A::decision('fail', 80, 'Not suitable') === 'down' && A::decision('fail', 82, 'Rejected: insufficient information') === 'down');
$check('Withdrew: their choice, counted apart', A::decision('fail', 90, 'Withdrew') === 'withdrew');
$check('No response from candidate: their doing too, counted apart (16 Nov)', A::decision('fail', 92, 'No response from candidate') === 'withdrew');
// Audit Q1 follow-up (17 Nov 2026): by the fixed code, not the name.
$check('Q1: "Withdrew" renamed "Candidate declined" is still their choice (by its code)', A::decision('fail', 90, 'Candidate declined', 30, 'withdrew') === 'withdrew'
    && A::decision('fail', 92, 'Went silent', 30, 'no_response') === 'withdrew');
$check('Q1: "Not suitable" renamed "Withdrawn by us" is still turned down (by its code)', A::decision('fail', 80, 'Withdrawn by us', 30, 'not_suitable') === 'down');
$appQ = \Eshcol\Ats\App::boot(cli: true);
$check('Q1: Under review, No response and Awaiting reply have fixed codes (update 067)', \Eshcol\Ats\Services\Stages::id($appQ, 'review') !== null
    && \Eshcol\Ats\Services\Stages::id($appQ, 'no_response') !== null && \Eshcol\Ats\Services\Stages::id($appQ, 'awaiting_reply') !== null);
$check('Q1: every stage the rules need has its code (nothing to warn about)', \Eshcol\Ats\Services\Stages::missing($appQ) === []);
$srcQ = (string) file_get_contents(dirname(__DIR__) . '/src/Services/Agreement.php') . file_get_contents(dirname(__DIR__) . '/src/Controllers/DashboardController.php');
$check('Q1: no rule finds Shortlisted or Applied by its name any more', !str_contains($srcQ, "name LIKE 'Shortlist%'") && !str_contains($srcQ, "s.name = 'Applied'"));
$check('Awaiting candidate\'s reply: not decided yet, with its own pink tag', A::decision('active', 25, "Awaiting candidate's reply") === 'open'
    && \Eshcol\Ats\Services\KeyFacts::stageTone("Awaiting candidate's reply", 'active') === 'st-await'
    && \Eshcol\Ats\Services\KeyFacts::stageTone('No response from candidate', 'fail') === 'st-withdrew');

echo "\nThe report on a position\n";
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "Zz Analyser $tag"]);
$roleId = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $roleId, $routingWas, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM shortlists WHERE role_id = ?', [$roleId]);
    $db->run('DELETE FROM roles WHERE id = ?', [$roleId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$stage = fn (string $name) => (int) $db->col('SELECT id FROM stages WHERE name = ?', [$name]);
$person = function (string $name, string $band, int $score, string $stageName, ?string $reason = null) use ($app, $db, $roleId, $uid, $tag, &$made, $stage): int {
    $tmp = $app->storage("uploads-tmp/zz-an-$tag-" . md5($name) . '.txt');
    file_put_contents($tmp, "$name\n" . strtolower(str_replace(' ', '.', $name)) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nChennai\nAccountant, 2022 – Present\nTally, GST.");
    $r = (new CandidateImporter($app))->import($tmp, 'cv.txt', $roleId, $uid);
    @unlink($tmp);
    $made[] = (int) $r['candidate_id'];
    $appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [(int) $r['candidate_id'], $roleId]);
    $db->update('applications', ['stage_id' => $stage($stageName), 'stage_reason' => $reason], $appId);
    $db->run('DELETE FROM fit_assessments WHERE application_id = ?', [$appId]);
    $db->insert('fit_assessments', ['application_id' => $appId, 'candidate_id' => (int) $r['candidate_id'], 'role_id' => $roleId, 'status' => 'done',
        'result' => '{}', 'score' => $score, 'band' => $band, 'breakdown' => '{}', 'model' => F::MODEL]);
    return $appId;
};
$s1 = $person("Anu Zz$tag", 'Strong', 82, 'Shortlisted');
$s2 = $person("Bala Zz$tag", 'Strong', 78, 'Submitted to client');
$s3 = $person("Chitra Zz$tag", 'Strong', 75, 'Not suitable', 'BBM and MBA; minimum is B.Com');
$person("Deepa Zz$tag", 'Possible', 60, 'Future requirement');
$person("Ezhil Zz$tag", 'Possible', 55, 'Applied');
$w1 = $person("Fathima Zz$tag", 'Weak', 30, 'Not suitable', 'No GST');
$person("Gopi Zz$tag", 'Weak', 25, 'Withdrew');
$w3 = $person("Hari Zz$tag", 'Weak', 40, 'Shortlisted');
$sl = $db->insert('shortlists', ['role_id' => $roleId, 'token_hash' => hash('sha256', "zz$tag"), 'token_enc' => 'x', 'to_email' => 'client@candidates.test', 'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
$db->insert('shortlist_items', ['shortlist_id' => $sl, 'application_id' => $s2, 'profile' => '{}', 'decision' => 'shortlist', 'decided_at' => date('Y-m-d H:i:s')]);
$db->insert('shortlist_items', ['shortlist_id' => $sl, 'application_id' => $s1, 'profile' => '{}', 'decision' => 'reject', 'decided_at' => date('Y-m-d H:i:s')]);
$r = (new A($app))->forRole($roleId);
$check('8 assessed', $r['assessed'] === 8);
$check('Strong: 2 taken forward, 1 turned down', $r['grid']['Strong']['forward'] === 2 && $r['grid']['Strong']['down'] === 1);
$check('Possible: 1 kept for later, 1 not decided', $r['grid']['Possible']['later'] === 1 && $r['grid']['Possible']['open'] === 1);
$check('Weak: 1 turned down, 1 withdrew, 1 taken forward', $r['grid']['Weak']['down'] === 1 && $r['grid']['Weak']['withdrew'] === 1 && $r['grid']['Weak']['forward'] === 1);
$check('agreement: 3 of 5 = 60% (Strong forward 2 + Weak down 1, out of 5 decided)', $r['agree'] === 3 && $r['decided'] === 5 && $r['pct'] === 60);
$check('…marked too early (fewer than 20)', $r['enough'] === false);
$check('the client\'s answers by band: Strong 1 yes, 1 no', $r['grid']['Strong']['client_shortlist'] === 1 && $r['grid']['Strong']['client_reject'] === 1);
$names = array_column($r['differ'], 'name');
$check('where you differ: the Strong you turned down (with your reason) and the Weak you took forward', count($r['differ']) === 2
    && $r['differ'][0]['name'] === "Chitra Zz$tag" && $r['differ'][0]['reason'] === 'BBM and MBA; minimum is B.Com' && $r['differ'][1]['name'] === "Hari Zz$tag");
$rid = $roleId;
ob_start();
include dirname(__DIR__) . '/views/roles/_agreement.php';
$html = (string) ob_get_clean();
$check('the card shows the agreement, the table and the reasons', str_contains($html, '60% agreement') && str_contains($html, 'too early to judge') && str_contains($html, 'BBM and MBA; minimum is B.Com')
    && str_contains($html, 'Client: yes'));

echo "\nCareer direction (analyser 4)\n";
$sch = F::schema();
$check('Claude is asked for it (rising, flat, falling, unclear) with a note', ($sch['properties']['career_direction']['enum'] ?? []) === ['rising', 'flat', 'falling', 'unclear']
    && in_array('career_direction', $sch['required'], true) && in_array('career_note', $sch['required'], true));
$base = ['requirements' => [], 'relevant_experience_months' => 30, 'seniority' => 'right', 'qualification_level' => 'base', 'stability' => 'mixed', 'fit' => 'good'];
$role = ['exp_min' => 2, 'exp_max' => 3, 'exp_allow_below' => 1, 'exp_allow_above' => 3];
$ladder = ['base' => 'B.Com', 'higher' => '', 'top' => ''];
$pts = fn (array $res) => F::score($res, [], $role, $ladder)['parts']['stability']['points'];
$check('an assessment from before (no direction): 7.5 of 10, as before', $pts($base) == 7.5);
$check('rising: one point more (8.5)', $pts($base + ['career_direction' => 'rising']) == 8.5);
$check('flat or unclear: no change', $pts($base + ['career_direction' => 'flat']) == 7.5 && $pts($base + ['career_direction' => 'unclear']) == 7.5);
$check('falling: two points less (5.5)', $pts($base + ['career_direction' => 'falling']) == 5.5);
$check('never above 10 (good, good, rising)', $pts(['stability' => 'good', 'fit' => 'good', 'career_direction' => 'rising'] + $base) == 10.0);
$check('never below 0 (poor, poor, falling)', $pts(['stability' => 'poor', 'fit' => 'poor', 'career_direction' => 'falling'] + $base) == 0.0);
$check('the note says it', str_contains(F::score($base + ['career_direction' => 'rising'], [], $role, $ladder)['parts']['stability']['note'], 'career rising'));
$f = ['result' => $base + ['career_direction' => 'rising', 'career_note' => 'Accountant, then Senior Accountant.', 'summary' => 's', 'stability_note' => '', 'fit_note' => '',
    'relevant_experience_note' => '', 'seniority_note' => '', 'qualification_note' => '', 'transferable' => [], 'trainable' => [], 'questions' => []],
    'breakdown' => ['parts' => []], 'model' => F::MODEL, 'created_at' => '2026-11-16 10:00:00'];
$reqs = [];
ob_start();
include dirname(__DIR__) . '/views/partials/fit_detail.php';
$view = (string) ob_get_clean();
$check('shown on the assessment: "Career direction: Rising: Accountant, then Senior Accountant."', str_contains($view, 'Career direction:</b> Rising: Accountant, then Senior Accountant.'));
