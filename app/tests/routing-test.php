<?php
/**
 * Routing by experience, end to end, on throwaway roles and fictional candidates:
 *  - a 10-year accountant applying for a 2-5 yr Executive post stays there, marked
 *    "above this role's band", and is also added to the 8-12 yr Manager post;
 *  - a 3-year accountant applying for Executive stays put, routed nowhere;
 *  - a 10-year AutoCAD draftsman is never routed into accounts;
 *  - a CV with no role is sorted into the role it fits;
 *  - experience grows with time, and the level and search maths agree.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/routing-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
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

$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$maxRole = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM roles');
// Keep other roles out of the way while testing, so results don't depend on what else is in the database.
$others = array_column($db->all('SELECT id FROM roles WHERE accepts_routed = 1'), 'id');
$db->run('UPDATE roles SET accepts_routed = 0 WHERE accepts_routed = 1');
$poolClientBefore = $db->col('SELECT id FROM clients WHERE name = ?', [\Eshcol\Ats\Services\RoleRouter::POOL_CLIENT]);
$tag = bin2hex(random_bytes(3));
$saved = [];
foreach (['routing_enabled', 'auto_sort_unassigned'] as $k) {
    $saved[$k] = $app->setting($k, '1');
    $db->run('INSERT INTO settings (k, v) VALUES (?, \'1\') ON DUPLICATE KEY UPDATE v = \'1\'', [$k]);
}

// Throwaway client and two roles, like the real Accounts Executive / Accounts Manager pair.
$clientId = $db->insert('clients', ['name' => "ZZ Routing Test $tag"]);
$mkRole = function (string $title, float $min, float $max) use ($db, $clientId, $tag): int {
    $id = $db->insert('roles', ['client_id' => $clientId, 'title' => "$title ZZ$tag", 'exp_min' => $min, 'exp_max' => $max,
        'status' => 'open', 'accepts_routed' => 1, 'score_threshold' => 50, 'jd_text' => "$title. Tally, GST, B.Com."]);
    foreach (['Tally', 'GST'] as $i => $v) {
        $db->insert('role_criteria', ['role_id' => $id, 'kind' => 'must', 'value' => $v, 'sort' => $i]);
    }
    $db->insert('role_criteria', ['role_id' => $id, 'kind' => 'qualification', 'value' => 'B.Com', 'sort' => 9]);
    return $id;
};
$exec = $mkRole('Accounts Executive', 2, 5);
$mgr = $mkRole('Accounts Manager', 8, 12);

$imp = new CandidateImporter($app);
$made = [];
$tmp = $app->storage('uploads-tmp/zz-route-' . getmypid() . '.txt');
$apply = function (string $name, string $body, ?int $roleId) use ($imp, $tmp, $uid, $tag, &$made): array {
    $email = strtolower(str_replace(' ', '.', $name)) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 9" . random_int(100000000, 999999999) . "\nChennai\n$body");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    $made[] = (int) $r['candidate_id'];
    return $r;
};
$app_ = fn (int $cid, int $rid) => $db->one('SELECT * FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $rid]);

echo "Senior person applies for the junior role\n";
$r = $apply('Ravi Seniortest', "Accounts Manager, Sundaram Traders, Jan 2016 – Present\n10 years of experience in accounts, Tally ERP 9, GST returns, TDS and audits.\nB.Com, Madras University", $exec);
$cid = (int) $r['candidate_id'];
$check('imported', $r['outcome'] === 'new' && $cid > 0);
$a = $app_($cid, $exec);
$check('kept on the role they applied for', (bool) $a);
$check('marked "above this role\'s band" there', str_contains((string) ($a['fit_note'] ?? ''), "above this role's band"));
$m = $app_($cid, $mgr);
$check('also added to Accounts Manager', (bool) $m);
$check('…as a routed application', ($m['source'] ?? '') === 'routed' && (int) ($m['routed_from_role_id'] ?? 0) === $exec);
$check('…with a note saying why', str_contains((string) ($m['fit_note'] ?? ''), 'Routed from Accounts Executive'));
$check('upload message tells you', str_contains($r['message'], 'Also added to Accounts Manager'));
$check('current position read from the CV', $db->col('SELECT current_title FROM candidates WHERE id = ?', [$cid]) === 'Accounts Manager');
$check('position stored for search', (bool) $db->col('SELECT 1 FROM candidate_titles WHERE candidate_id = ? AND title = ?', [$cid, 'Accounts Manager']));

echo "\nRight person for the role they applied for\n";
$r = $apply('Kavya Fittest', "Accounts Executive, Lotus Foods, Mar 2023 – Present\n3 years of experience with Tally Prime and GST filing.\nB.Com", $exec);
$k = (int) $r['candidate_id'];
$check('on Accounts Executive with no warning', ($a2 = $app_($k, $exec)) && $a2['fit_note'] === null);
$check('NOT added to Accounts Manager', !$app_($k, $mgr));

echo "\nWrong field altogether\n";
$r = $apply('Suresh Drafttest', "Piping Draftsman, Petrofac, 2016 – Present\n10 years of experience in AutoCAD, isometrics and Plant 3D.\nDiploma in Mechanical Engineering", $exec);
$s = (int) $r['candidate_id'];
$check('AutoCAD CV is NOT routed into accounts', !$app_($s, $mgr));
$check('…its own application says "Different field"', str_contains((string) ($app_($s, $exec)['fit_note'] ?? ''), 'Different field: matches 0 of 2'));

echo "\nCV with no role\n";
$r = $apply('Anita Notest', "Senior Accountant, Ganesh Mills, 2016 – Present\n9 years of experience: Tally, GST, MIS reports.\nB.Com", null);
$n = (int) $r['candidate_id'];
$check('sorted into Accounts Manager by itself', ($an = $app_($n, $mgr)) && str_contains((string) $an['fit_note'], 'Sorted in automatically'));
$check('not into Accounts Executive', !$app_($n, $exec));

echo "\nHR CV sent for an accounts role (talent pools)\n";
$r = $apply('Meera Hrtest', "HR Executive, Brightway Logistics, Feb 2023 – Present\n3 years of experience in recruitment, onboarding, payroll inputs and employee engagement.\nMBA (HR)", $exec);
$h = (int) $r['candidate_id'];
$hrPool = (int) $db->col("SELECT id FROM roles WHERE is_pool = 1 AND title = 'HR Executive'");
$check('an "HR Executive" talent pool exists', $hrPool > 0);
$check('the HR CV is filed in it', ($hp = $app_($h, $hrPool)) && str_contains((string) $hp['fit_note'], 'Filed by position: applied for Accounts Executive'));
$check('NOT added to Accounts Manager', !$app_($h, $mgr));
$check('accounts application marked "Different field"', str_contains((string) ($app_($h, $exec)['fit_note'] ?? ''), 'Different field'));
$check('upload message names the pool', str_contains($r['message'], 'Filed in the HR Executive talent pool'));
$r = $apply('Arun Hrmgrtest', "HR Manager, Coastal Exports, 2014 – Present\n12 years of experience leading HR: recruitment, payroll, PMS, labour law compliance.\nMBA (HR)", $exec);
$check('a 12-yr HR Manager goes to an "HR Manager" pool', (bool) $app_((int) $r['candidate_id'], (int) $db->col("SELECT id FROM roles WHERE is_pool = 1 AND title = 'HR Manager'")));
$r = $apply('Sneha Hrtwotest', "HR Executive, Lotus Foods, 2022 – Present\n4 years of experience in recruitment and HR operations.\nMBA", null);
$check('a second HR Executive joins the same pool (no duplicate pool)', (bool) $app_((int) $r['candidate_id'], $hrPool) && (int) $db->col("SELECT COUNT(*) FROM roles WHERE is_pool = 1 AND title = 'HR Executive'") === 1);
$check('pools are not counted as open roles', !(int) $db->col("SELECT COUNT(*) FROM roles WHERE status = 'open' AND is_pool = 0 AND title = 'HR Executive'"));
$sn = (int) $r['candidate_id']; // Sneha: in a pool only, on no client role
$db->run('UPDATE candidates SET purge_after = CURDATE() - INTERVAL 1 DAY WHERE id = ?', [$sn]);
$check('being in a pool does not stop the five-year clock', in_array($sn, array_column((new Retention($app))->due(), 'id'), true));

echo "\nAllowance (default 1 below, 5 above; changeable per role)\n";
$r = $apply('Vijay Stretchtest', "Senior Accountant, Apex Foods, 2019 – Present\n6.5 years of experience with Tally and GST.\nB.Com", $exec);
$v = (int) $r['candidate_id'];
$check('6.5 yrs on a 2–5 role: within the +5 allowance, kept and flagged', str_contains((string) ($app_($v, $exec)['fit_note'] ?? ''), 'within its allowance'));
$check('…and not sent to a pool', !(int) $db->col('SELECT COUNT(*) FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND r.is_pool = 1', [$v]));
$db->run('UPDATE roles SET exp_allow_above = 0 WHERE id = ?', [$exec]);
$r = $apply('Gopal Tighttest', "Senior Accountant, Ganesh Mills, 2019 – Present\n6.5 years of experience with Tally and GST.\nB.Com", $exec);
$g = (int) $r['candidate_id'];
$check('same CV, allowance set to 0 on the role: outside it', str_contains((string) ($app_($g, $exec)['fit_note'] ?? ''), 'outside its allowance'));
$check('…too junior for Manager (7–17), so filed in the Senior Accountant pool', (bool) $db->col("SELECT 1 FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND r.is_pool = 1 AND r.title = 'Senior Accountant'", [$g]));
$db->run('UPDATE roles SET exp_allow_above = 5 WHERE id = ?', [$exec]);
$sc = (new CandidateImporter($app))->scorer();
$role = $db->one('SELECT * FROM roles WHERE id = ?', [$exec]);
$crit = $db->all('SELECT kind, value FROM role_criteria WHERE role_id = ?', [$exec]);
$score = fn (int $months) => $sc->score($role, $crit, ['exp_months' => $months, 'exp_as_of' => null] + array_fill_keys(['qualifications', 'location', 'notice_days', 'ctc_expected_lpa'], null), 'Tally GST')['breakdown']['parts']['experience'];
$check('score: 8 yrs on a 2–5 role is "within allowance"', str_contains($score(96)['note'], 'within allowance'));
$check('score: 14 yrs is "well above band" and scores lower', str_contains($score(168)['note'], 'well above band') && $score(168)['points'] < $score(96)['points']);

echo "\nSwitches\n";
$db->run('UPDATE settings SET v = \'0\' WHERE k = \'routing_enabled\'');
$r = $apply('Mohan Offtest', "Accounts Manager, 2015 – Present\n11 years of experience in Tally and GST.\nB.Com", $exec);
$check('routing off: stays only where they applied', !$app_((int) $r['candidate_id'], $mgr));
$db->run('UPDATE settings SET v = \'1\' WHERE k = \'routing_enabled\'');
$db->run('UPDATE roles SET accepts_routed = 0 WHERE id = ?', [$mgr]);
$r = $apply('Lata Closedtest', "Finance Manager, 2016 – Present\n10 years of experience with Tally and GST.\nB.Com", $exec);
$check('role switched to "don\'t accept routed CVs": not added', !$app_((int) $r['candidate_id'], $mgr));

echo "\nExperience grows with time\n";
$db->run('UPDATE candidates SET exp_months = 72, exp_as_of = CURDATE() - INTERVAL 24 MONTH WHERE id = ?', [$k]);
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$k]);
$check('6 yrs two years ago counts as 8 today', exp_now($c) === 96);
$check('and is marked as an estimate', exp_is_estimate($c));
$sqlExp = (int) $db->col('SELECT (c.exp_months + IF(c.exp_as_of IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(MONTH, c.exp_as_of, CURDATE())))) FROM candidates c WHERE id = ?', [$k]);
$check('search uses the same figure as the screen', $sqlExp === 96);
$check('levels: 0 Fresher, 30 mos Junior, 5 yrs Mid-level, 10 yrs Senior, 15 yrs Leadership',
    level_name(0) === 'Fresher' && level_name(30) === 'Junior' && level_name(60) === 'Mid-level' && level_name(120) === 'Senior' && level_name(180) === 'Leadership');

// Clean up: candidates (with files), roles, client, settings.
$ret = new Retention($app);
foreach (array_unique($made) as $id) {
    $ret->delete($id, 'manual', false);
}
$db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
$db->run('DELETE FROM roles WHERE is_pool = 1 AND id > ? AND id NOT IN (SELECT role_id FROM applications)', [$maxRole]);
if (!$poolClientBefore) {
    $db->run('DELETE FROM clients WHERE name = ? AND id NOT IN (SELECT client_id FROM roles)', [\Eshcol\Ats\Services\RoleRouter::POOL_CLIENT]);
}
$db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
foreach ($saved as $k => $v) {
    $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
}
if ($others) {
    $db->run('UPDATE roles SET accepts_routed = 1 WHERE id IN (' . implode(',', array_map('intval', $others)) . ')');
}
@unlink($tmp);
$left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
$check('nothing left behind', $left === 0 && !$db->col('SELECT COUNT(*) FROM roles WHERE title LIKE ?', ["%ZZ$tag"]));
echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
