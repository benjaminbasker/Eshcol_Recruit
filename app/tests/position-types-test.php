<?php
/**
 * Position types (own-hiring and staffing plan v2, phase 1, 15 Nov 2026): client recruitment, staffing and Eshcol
 * Global's own team. What candidates read for each (the wording Benjamin approved), the staffing question, who may see
 * our own team's positions, and the offer approval Eshcol's own offers need. Throwaway data, removed afterwards.
 * LOCAL ONLY. Run: php tests/position-types-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Access;
use Eshcol\Ats\App;
use Eshcol\Ats\Controllers\DecisionController;
use Eshcol\Ats\Controllers\RoleController;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Engagement;
use Eshcol\Ats\Services\Interviews;
use Eshcol\Ats\Services\Questionnaires;
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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$self = Engagement::selfClientId($app);
$client = $db->insert('clients', ['name' => "Zz Acme Industries $tag"]);
$mk = fn (string $title, int $cl, string $eng, string $loc, array $extra = []) => $db->insert('roles', ['client_id' => $cl, 'engagement' => $eng, 'title' => "$title ZZ$tag",
    'location' => $loc, 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0] + $extra);
$place = $mk('Accounts Executive', $client, 'placement', 'Pune');
$staff = $mk('Machine Operator', $client, 'staffing', 'Tiruvallur', ['contract_months' => 12]);
$own = $mk('Recruiter', (int) $self, 'inhouse', 'Chennai');
$made = [];
$users = [];
register_shutdown_function(function () use ($app, $db, &$made, &$users, $client, $place, $staff, $own, $routingWas, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run("DELETE FROM roles WHERE id IN ($place, $staff, $own)");
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    foreach ($users as $u) {
        $db->run('DELETE FROM role_access WHERE user_id = ?', [$u]);
        $db->run('DELETE FROM users WHERE id = ?', [$u]);
    }
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-types-$tag.txt");
file_put_contents($tmp, "Kavya Zztypes\nkavya.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nChennai\nAccountant, 2021 – Present\nTally, GST.\nB.Com");
$r = $imp->import($tmp, 'kavya.txt', $place, $owner);
@unlink($tmp);
$cid = (int) $r['candidate_id'];
$made[] = $cid;
$imp->attach($cid, $staff, $owner, 'database');
$imp->attach($cid, $own, $owner, 'database');
$appOf = fn (int $role) => (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $role]);
$cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);

echo "Set up\n";
$check('Eshcol Global exists as our own client record', $self !== null && (int) $db->col('SELECT is_self FROM clients WHERE id = ?', [$self]) === 1
    && $db->col('SELECT name FROM clients WHERE id = ?', [$self]) === 'Eshcol Global');
$check('existing positions are client recruitment', Engagement::of(['engagement' => null]) === 'placement' && Engagement::of([]) === 'placement');
$check('labels read as approved', Engagement::clientLabel(['name' => 'Eshcol Global', 'is_self' => 1]) === 'Eshcol Global (our own team)');

echo "\nThe thank-you email, by type (approved wording)\n";
$ack = new Acknowledger($app);
[, , $tPlace] = $ack->render($cand, [$place]);
[, , $tStaff] = $ack->render($cand, [$staff]);
[, , $tOwn] = $ack->render($cand, [$own]);
$check('client recruitment unchanged: "with our client in Pune"', str_contains($tPlace, "role with our client in Pune") && str_contains($tPlace, 'other suitable roles with our clients'));
$check('own team: "at Eshcol Global, Chennai", never "our client"', str_contains($tOwn, "role at Eshcol Global, Chennai") && !str_contains($tOwn, 'our client\'s')
    && !str_contains($tOwn, 'with our client ') && str_contains($tOwn, 'at Eshcol or with our clients') && str_contains($tOwn, 'a role at Eshcol Global Services'));
$check('staffing: on Eshcol\'s payroll at the named client\'s site in Tiruvallur', str_contains($tStaff, "on Eshcol's payroll, working at Zz Acme Industries $tag's site in Tiruvallur")
    && str_contains($tStaff, "on Eshcol Global's payroll with one of our clients"));
$check('every one still carries the LinkedIn button and never mentions Claude', !str_contains($tPlace . $tStaff . $tOwn, 'Claude')
    && substr_count(strtolower($tPlace . $tStaff . $tOwn), 'linkedin') >= 3);
[, , $tMixed] = $ack->render($cand, [$place, $own]);
$check('a mix of types reads as client recruitment (the safe wording)', str_contains($tMixed, 'with our client'));

echo "\nThe details form and request\n";
$qs = new Questionnaires($app);
$keys = fn (array $roles) => array_column($qs->questionsFor($roles, $cand), 'prompt', 'key');
$rowOf = fn (int $id) => $db->one('SELECT r.*, cl.name AS client_name FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ?', [$id]);
$kPlace = $keys([$rowOf($place)]);
$kStaff = $keys([$rowOf($staff)]);
$kOwn = $keys([$rowOf($own)]);
$check('client recruitment asks permission to put them forward, no contract question', isset($kPlace['permission']) && !isset($kPlace['contract']));
$check('own team: no "put you forward to our client" question', !isset($kOwn['permission']));
$check('staffing asks the approved contract question', ($kStaff['contract'] ?? '') === "This is a contract role on Eshcol's payroll, working at Zz Acme Industries $tag's site in Tiruvallur for 12 months. Are you open to it?");
$opts = array_column($qs->questionsFor([$rowOf($staff)], $cand), 'options', 'key')['contract'] ?? [];
$check('with the three answers: Yes / No / I\'d like to know more', $opts === ['Yes', 'No', 'I\'d like to know more']);
$lead = $qs->create($appOf($own), $owner);
$eid = $qs->email($lead, 'invite', $owner);
$mail = (string) $db->col('SELECT text FROM email_queue WHERE id = ?', [$eid]);
$check('own team\'s request: "the role\'s requirements", not "our client\'s"', str_contains($mail, 'the role\'s requirements') && !str_contains($mail, 'our client'));
$eid2 = $qs->email($lead, 'reminder', $owner);
$check('own team\'s reminder: "we need to know"', str_contains((string) $db->col('SELECT text FROM email_queue WHERE id = ?', [$eid2]), 'we need to know your salary'));
$db->run('DELETE FROM email_queue WHERE id IN (?, ?)', [$eid, $eid2]);

echo "\nThe interview letter\n";
$iv = new Interviews($app);
$ivRow = ['kind' => 'virtual', 'starts_at' => date('Y-m-d 11:00:00', strtotime('+3 days')), 'meeting_link' => 'https://meet.google.com/abc-defg-hij', 'address' => '', 'maps_url' => '', 'round' => 1];
[$sOwn, $bOwn] = $iv->letter($ivRow, $iv->application($appOf($own)));
[$sStaff, $bStaff] = $iv->letter($ivRow, $iv->application($appOf($staff)));
[, $bPlace] = $iv->letter($ivRow, $iv->application($appOf($place)));
$check('own team: "your interview with Eshcol Global", not "our client"', str_contains($bOwn, 'interview with Eshcol Global') && !str_contains($bOwn, 'our client') && str_contains($sOwn, 'with Eshcol Global'));
$check('staffing: "...for a role on Eshcol\'s payroll"', str_contains($bStaff, "for a role on Eshcol's payroll"));
$check('client recruitment: unchanged ("with our client")', str_contains($bPlace, 'with our client'));

echo "\nWho sees our own team's positions\n";
$rec = $db->insert('users', ['name' => "Zz Rec $tag", 'email' => "rec.$tag@types.test", 'role' => 'recruiter', 'password_hash' => 'x', 'is_active' => 1]);
$hm = $db->insert('users', ['name' => "Zz HM $tag", 'email' => "hm.$tag@types.test", 'role' => 'recruiter', 'password_hash' => 'x', 'is_active' => 1]);
$users = [$rec, $hm];
$db->update('roles', ['hiring_manager_id' => $hm], $own);
$R = ['id' => $rec, 'role' => 'recruiter'];
$H = ['id' => $hm, 'role' => 'recruiter'];
$O = ['id' => $owner, 'role' => 'owner'];
$check('another recruiter cannot open it', !Access::allowsRoute($R, RoleController::class, 'show', [$own]) && !Access::allowsRoute($R, RoleController::class, 'edit', [$own]));
$check('nor its candidates\' application pages', !Access::allowsRoute($R, 'Eshcol\\Ats\\Controllers\\CandidateController', 'stage', [$appOf($own)]));
$check('it is left out of their lists', str_contains(Access::roleFilter($R, 'r.id'), (string) $own));
$check('they still see client and staffing positions', Access::allowsRoute($R, RoleController::class, 'show', [$place]) && Access::allowsRoute($R, RoleController::class, 'show', [$staff]));
$check('the candidate stays visible (the shared database)', Access::allowsRoute($R, 'Eshcol\\Ats\\Controllers\\CandidateController', 'show', [$cid]));
$check('the hiring manager can open it, and it is in their lists', Access::allowsRoute($H, RoleController::class, 'show', [$own]) && !preg_match('/\b' . $own . '\b/', Access::roleFilter($H, 'r.id')));
$check('the owner always can', Access::allowsRoute($O, RoleController::class, 'show', [$own]) && Access::roleFilter($O, 'r.id') === '1=1');
// Audit S2 (16 Nov 2026): the buttons that used to slip through.
$C = 'Eshcol\\Ats\\Controllers\\';
$check('nor override, offer, joining or "assess anyway" on its applications', !Access::allowsRoute($R, $C . 'CandidateController', 'override', [$appOf($own)])
    && !Access::allowsRoute($R, $C . 'DecisionController', 'offer', [$appOf($own)]) && !Access::allowsRoute($R, $C . 'DecisionController', 'joining', [$appOf($own)])
    && !Access::allowsRoute($R, $C . 'FitController', 'force', [$appOf($own)]));
$check('"assess anyway" reads the number as an application, so a client position\'s application is fine', Access::allowsRoute($R, $C . 'FitController', 'force', [$appOf($place)]));
$_POST['role_id'] = $own;
$check('a form that names the private position is refused', !Access::allowsRoute($R, $C . 'CandidateController', 'addToRole', [$cid]) && !Access::allowsRoute($R, $C . 'UploadController', 'startBatch', []));
$check('...but the hiring manager may use it', Access::allowsRoute($H, $C . 'CandidateController', 'addToRole', [$cid]));
$_POST = [];
// Audit S2 follow-up (17 Nov 2026): other fields that name a position.
$_POST = ['owner_type' => 'role', 'owner_id' => $own, 'kind' => 'jd'];
$check('S2: uploading a document onto the private position (owner_type=role, owner_id) is refused', !Access::allowsRoute($R, $C . 'DocumentController', 'upload', []));
$check('...a document on a client is not a position, so it isn\'t blocked by this rule', (function () use ($R, $C, $own) { $_POST = ['owner_type' => 'client', 'owner_id' => $own]; return Access::allowsRoute($R, $C . 'DocumentController', 'upload', []); })());
$_POST = ['from' => $own];
$check('S2: copying requirements FROM the private position into a visible one is refused', !Access::allowsRoute($R, $C . 'FitController', 'copy', [$place]));
$check('...the hiring manager and the owner may', Access::allowsRoute($H, $C . 'FitController', 'copy', [$place]) && Access::allowsRoute($O, $C . 'FitController', 'copy', [$place]));
$_POST = ['from' => $staff];
$check('...copying from a position they can see is fine', Access::allowsRoute($R, $C . 'FitController', 'copy', [$place]));
$check('postedRoleIds reads role_id, owner_id (role documents) and "from" (copy only)', Access::postedRoleIds('FitController::copy', ['from' => 7, 'role_id' => 3]) === [3, 7]
    && Access::postedRoleIds('DocumentController::upload', ['owner_type' => 'role', 'owner_id' => 9]) === [9]
    && Access::postedRoleIds('DocumentController::upload', ['owner_type' => 'client', 'owner_id' => 9]) === []
    && Access::postedRoleIds('OtherController::x', ['from' => 7]) === []);
$_POST = [];
// Audit S4: the Candidates list's positions column leaves out the private position for that recruiter.
$_SESSION['uid'] = $rec;
$kfRec = (new \Eshcol\Ats\Services\KeyFacts($app))->forCandidates([$cid]);
$_SESSION['uid'] = $owner;
$kfOwn = (new \Eshcol\Ats\Services\KeyFacts($app))->forCandidates([$cid]);
unset($_SESSION['uid']);
$hasOwn = fn ($kf) => in_array($own, array_map('intval', array_column($kf[$cid]['positions'] ?? [], 'role_id')), true);
$check('candidate list: the recruiter does not see the private position; the owner does', !$hasOwn($kfRec) && $hasOwn($kfOwn));
// Emails about a private position (16 Nov 2026, found during the audit work).
$qMail = (int) $qs->email($lead, 'invite', $owner);
$seesMail = fn (array $u, int $id) => (bool) $db->col('SELECT 1 FROM email_queue e WHERE e.id = ? AND ' . Access::emailFilter($u, 'e'), [$id]);
$alertOwner = $db->insert('email_queue', ['kind' => 'alert', 'to_email' => "owner.$tag@types.test", 'subject' => 'Zz alert', 'html' => 'x', 'text' => 'x', 'status' => 'sent']);
$alertRec = $db->insert('email_queue', ['kind' => 'alert', 'to_email' => "rec.$tag@types.test", 'subject' => 'Zz alert', 'html' => 'x', 'text' => 'x', 'status' => 'sent']);
$check('emails: the details request for the private position is hidden from the recruiter, not from its hiring manager or the owner',
    $qMail > 0 && !$seesMail($R, $qMail) && $seesMail($H, $qMail) && $seesMail($O, $qMail));
$check('emails: internal mail addressed to someone else is hidden from that recruiter; their own is not', !$seesMail($R, $alertOwner) && $seesMail($R, $alertRec));
$db->run('DELETE FROM email_queue WHERE id IN (?, ?, ?)', [$alertOwner, $alertRec, $qMail]);
$check('a page missing from the list is refused when positions are hidden', !Access::allowsRoute($R, $C . 'MadeUpController', 'thing', [$appOf($place)]));
$check('...and allowed for the owner', Access::allowsRoute($O, $C . 'MadeUpController', 'thing', [$appOf($place)]));

echo "\nOffers Eshcol makes need approval\n";
$offerStage = (int) $db->col("SELECT id FROM stages WHERE name = 'Offer made'");
$check('own team: "Offer made" is blocked until approved', DecisionController::offerBlocked($app, $appOf($own), $offerStage) !== null);
$check('staffing: blocked too', DecisionController::offerBlocked($app, $appOf($staff), $offerStage) !== null);
$check('client recruitment: never blocked (the client makes the offer)', DecisionController::offerBlocked($app, $appOf($place), $offerStage) === null);
$db->insert('offer_approvals', ['application_id' => $appOf($own), 'designation' => 'Recruiter', 'ctc_lpa' => 3.6, 'joining_date' => date('Y-m-d', strtotime('+20 days')), 'approved_by' => $owner, 'approved_at' => date('Y-m-d H:i:s')]);
$check('approved: the move is allowed', DecisionController::offerBlocked($app, $appOf($own), $offerStage) === null);
// Audit S3 (16 Nov 2026): an interview marked Selected goes through the same approval.
$ivStaff = $db->insert('interviews', ['application_id' => $appOf($staff), 'round' => 1, 'status' => 'booked']);
$stageBefore = (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$appOf($staff)]);
$note = $iv->outcome($ivStaff, 'selected', '', $owner, true);
$check('interview "Selected" on staffing without an approved offer: recorded, stage not moved, and it says why',
    $note !== null && str_contains($note, 'approval') && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$appOf($staff)]) === $stageBefore
    && $db->col('SELECT outcome FROM interviews WHERE id = ?', [$ivStaff]) === 'selected');
$ivPlace = $db->insert('interviews', ['application_id' => $appOf($place), 'round' => 1, 'status' => 'booked']);
$check('client recruitment: "Selected" moves to Offer made and opens the placement', $iv->outcome($ivPlace, 'selected', '', $owner, true) === null
    && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$appOf($place)]) === $offerStage
    && (bool) $db->col('SELECT 1 FROM placements WHERE application_id = ?', [$appOf($place)]));
// Audit Q1: renaming the stage in Settings keeps the rule.
$db->run("UPDATE stages SET name = 'Offer issued' WHERE id = ?", [$offerStage]);
\Eshcol\Ats\Services\Stages::forget();
$check('renamed "Offer made" to "Offer issued": still needs approval', DecisionController::offerBlocked($app, $appOf($staff), $offerStage) !== null);
$db->run("UPDATE stages SET name = 'Offer made' WHERE id = ?", [$offerStage]);
$db->run('DELETE FROM placements WHERE application_id = ?', [$appOf($place)]);
$db->run('DELETE FROM interviews WHERE id IN (?, ?)', [$ivStaff, $ivPlace]);
$check('other stages are never blocked', DecisionController::offerBlocked($app, $appOf($staff), (int) $db->col("SELECT id FROM stages WHERE name IN ('Under review', 'Screening') ORDER BY name = 'Under review' DESC LIMIT 1")) === null);

echo "\nThe staffing answer is kept on the candidate\n";
$lead2 = $qs->create($appOf($staff), $owner);
$q2 = $db->one('SELECT * FROM questionnaires WHERE id = ?', [$lead2]);
$post = [];
foreach ($qs->questionsFor([$rowOf($staff)], $cand) as $qq) {
    $post[$qq['key']] = match (true) {
        $qq['key'] === 'contract' => 'I\'d like to know more',
        !empty($qq['options']) => $qq['options'][0],
        default => (string) ($qq['prefill'] ?? ''),
    };
}
$post = array_merge($post, ['ctc_current' => '3', 'ctc_min' => '3.5', 'ctc_expected' => '4', 'locality' => 'Anna Nagar', 'keep' => '1']);
$errs = $qs->submit($q2, $post);
$check('their answer is saved: "more_info"', !$errs && $db->col('SELECT open_to_contract FROM candidates WHERE id = ?', [$cid]) === 'more_info');
if ($errs) {
    echo '       (form said: ' . json_encode($errs) . ")\n";
}
