<?php
/**
 * 17 Nov 2026 (Mayur Wani: Jalgaon, BBM + MBA, on Accounting Associate): the screening rules.
 * B.Com required (M.Com, CA, CMA count; a known other degree is out; MBA/PGDM only is confirmed by you) and
 * "local candidates only". Run: php tests/screening-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\FunctionRouting;
use Eshcol\Ats\Services\QualificationGate as Q;
use Eshcol\Ats\Services\Stages;

$app = App::boot(cli: true);
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$clientId = $db->insert('clients', ['name' => "ZZ Screening $tag"]);
$made = [];
register_shutdown_function(function () use ($db, &$made, $clientId, &$ok, &$bad) {
    foreach ($made as $cid) {
        $db->run('DELETE FROM stage_history WHERE application_id IN (SELECT id FROM applications WHERE candidate_id = ?)', [$cid]);
        $db->run('DELETE FROM applications WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$cid]);
    }
    $db->run('DELETE FROM role_criteria WHERE role_id IN (SELECT id FROM roles WHERE client_id = ?)', [$clientId]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});

echo "The B.Com rule\n";
$v = fn (string $q) => Q::verdict($q)[0];
$check('B.Com passes; so do M.Com, CA and CMA (B.Com assumed)', $v('B.Com') === 'pass' && $v('M.Com') === 'pass' && $v('CA Final') === 'pass' && $v('CMA (Qualified)') === 'pass' && $v('CA Inter (Group 1 cleared)') === 'pass');
$check('…and B.Com listed after a higher degree', $v('MBA Finance, B.Com') === 'pass');
$check('Mayur: MBA Finance, BBM is out', $v('MBA Finance, BBM (BS)') === 'fail');
$check('BBA, BMS, BCA (with dots too), B.Sc, B.A., B.E. are out', $v('BBA') === 'fail' && $v('BMS') === 'fail' && $v('B.C.A.') === 'fail' && $v('B.Sc') === 'fail' && $v('B.A. Economics') === 'fail' && $v('B.E. Mechanical') === 'fail');
$check('MBA or PGDM with no graduation shown: you confirm (not rejected)', $v('MBA Finance') === 'confirm' && $v('PGDM') === 'confirm' && $v('') === 'confirm');
$check('US CMA and CA Foundation don\'t imply B.Com: you confirm', $v('US CMA (pursuing)') === 'confirm' && $v('CA Foundation') === 'confirm');
$check('BAF counts as B.Com: "Bachelor of Accounts and Finance", "Bachelor\'s in Accounting and Finance" (Pratiksha, Yash)',
    $v('MBA Finance & Marketing, Bachelor of Accounts and Finance') === 'pass' && $v("Bachelor's in Accounting and Finance") === 'pass' && $v('BAF') === 'pass');
$check('"Bachelor\'s" or "Bachelor\'s degree" with no subject: you confirm (Sathish, Pramila)', $v("Bachelor's") === 'confirm' && $v("MBA/PGDM Accounting and Finance, Bachelor's degree") === 'confirm');
$check('…but a named other subject is out: "Bachelor\'s degree in Biology", "Bachelor of Computer Applications", "Bachelor in Management Studies"',
    $v("Bachelor's degree in Biology") === 'fail' && $v('Bachelor of Computer Applications (pursuing)') === 'fail' && $v('Bachelor in Management Studies') === 'fail');
$check('a position asks for B.Com when its qualification says so', Q::requiresBcom(['BCom']) && Q::requiresBcom(['B.Com or M.Com']) && !Q::requiresBcom(['MBA']) && !Q::requiresBcom([]));

echo "\nOn a position: B.Com, local candidates only (Pune)\n";
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Zz Associate $tag", 'location' => 'Pune', 'location_scope' => 'local', 'status' => 'open', 'exp_min' => 2, 'exp_max' => 3, 'score_threshold' => 0]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'qualification', 'value' => 'BCom', 'sort' => 0]);
$st = fn (string $code) => (int) Stages::id($app, $code);
$applied = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
$person = function (string $name, string $city, string $quals, int $stage) use ($db, $tag, $role, &$made): array {
    $cid = $db->insert('candidates', ['full_name' => "$name $tag", 'email' => strtolower($name) . ".$tag@candidates.test", 'location' => $city, 'qualifications' => $quals, 'exp_months' => 30, 'exp_as_of' => date('Y-m-d')]);
    $made[] = $cid;
    $aid = $db->insert('applications', ['candidate_id' => $cid, 'role_id' => $role, 'stage_id' => $stage, 'source' => 'upload']);
    return [$cid, $aid];
};
[, $mayur] = $person('Mayur', 'Jalgaon', 'MBA Finance, BBM (BS)', $applied);
[, $bba] = $person('Bhavna', 'Pune', 'BBA', $st(Stages::SHORTLISTED) ? $applied : $applied);
[, $away] = $person('Kunal', 'Kolhapur', 'B.Com', $applied);
[, $mba] = $person('Meena', 'Pune', 'MBA Finance', $applied);
[, $good] = $person('Ganesh', 'Pune', 'M.Com, B.Com', $applied);
[, $shorted] = $person('Sana', 'Nagpur', 'BBA', $st(Stages::SHORTLISTED));
$fr = new FunctionRouting($app);
$list = $fr->screenPosition($role, false, $uid);
$names = array_map(fn ($r) => explode(' ', $r['name'])[0], $list);
sort($names);
$check('the list: Mayur and Bhavna (not B.Com), Kunal (Kolhapur)', $names === ['Bhavna', 'Kunal', 'Mayur']);
$why = array_column($list, 'reason', 'app');
$check('Mayur\'s reason names his degree', str_contains((string) ($why[$mayur] ?? ''), 'Not a B.Com (MBA Finance, BBM (BS))') && str_contains((string) $why[$mayur], 'needs B.Com'));
$check('Kunal\'s reason names his city', ($why[$away] ?? '') === 'Lives in Kolhapur; this position takes local candidates only');
$check('MBA only (Meena) stays, for you to confirm; M.Com + B.Com in Pune (Ganesh) stays', !isset($why[$mba]) && !isset($why[$good]));
$check('someone already Shortlisted (Sana, Nagpur, BBA) is never touched', !isset($why[$shorted]));
$check('listing moves nobody', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$mayur]) === $applied);
$fr->screenPosition($role, true, $uid);
$ns = $st(Stages::NOT_SUITABLE);
$row = $db->one('SELECT * FROM applications WHERE id = ?', [$mayur]);
$check('Apply: Mayur is Not suitable, with the reason kept', (int) $row['stage_id'] === $ns && str_contains((string) $row['stage_reason'], 'Not a B.Com'));
$check('…Bhavna and Kunal too; Meena, Ganesh and Sana unchanged', (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$bba]) === $ns && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$away]) === $ns
    && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$mba]) === $applied && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$good]) === $applied
    && (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$shorted]) === $st(Stages::SHORTLISTED));
$check('the move is in the stage history with the reason', str_contains((string) $db->col('SELECT note FROM stage_history WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$mayur]), 'B.Com'));
$check('nobody was emailed', !(int) $db->col('SELECT COUNT(*) FROM email_queue WHERE candidate_id IN (SELECT candidate_id FROM applications WHERE role_id = ?) AND created_at >= NOW() - INTERVAL 1 MINUTE', [$role]));
$check('running it again finds nobody', $fr->screenPosition($role, false, $uid) === []);

echo "\nNew CVs: the routing decision\n";
$roleRow = $db->one('SELECT * FROM roles WHERE id = ?', [$role]);
$cat = ['function_code' => 'ACC', 'secondary_code' => null, 'preferred_cities' => ''];
[$res1] = $fr->decide(['id' => 0, 'qualifications' => 'BBM', 'location' => 'Pune', 'exp_months' => 30, 'exp_as_of' => date('Y-m-d')], $cat, $roleRow);
[$res2, $note2] = $fr->decide(['id' => 0, 'qualifications' => 'MBA Finance', 'location' => 'Pune', 'exp_months' => 30, 'exp_as_of' => date('Y-m-d')], $cat, $roleRow);
[$res3] = $fr->decide(['id' => 0, 'qualifications' => 'B.Com', 'location' => 'Jalgaon', 'exp_months' => 30, 'exp_as_of' => date('Y-m-d')], $cat, $roleRow);
$check('a BBM in Pune: not qualified', $res1 === 'not_qualified');
$check('an MBA only in Pune: on the pipeline, noted "confirm B.Com"', $res2 === 'pipeline' && str_contains($note2, 'confirm B.Com'));
$check('a B.Com in Jalgaon: another city', $res3 === 'other_city');
