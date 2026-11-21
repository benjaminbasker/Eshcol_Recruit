<?php
/**
 * 21 Nov 2026 (Trainee Technicians, a hydraulics plant in Thiruvallur): the engineering branch inventory and rule,
 * the experience windows by qualification, and the regions and tiers. Pure parts first, then the routing on a
 * test position. Run: php tests/branches-regions-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\EngineeringBranches as B;
use Eshcol\Ats\Services\FunctionRouting as FR;
use Eshcol\Ats\Services\Regions as R;

$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};

echo "Branches: the same branch however it is written\n";
foreach ([
    'B.E. Computer Science and Engineering' => ['cse'], 'B.Tech CSE' => ['cse'], 'BE (CS&E)' => ['cse'], 'B.E Computer Engineering' => ['cse'],
    'B.E. Mechanical Engineering' => ['mechanical'], 'BE Mech' => ['mechanical'], 'B.E. (Mechanical)' => ['mechanical'],
    'B.E. Electrical and Electronics Engineering' => ['eee'], 'B.E EEE' => ['eee'], 'B.E. Electrical & Electronics' => ['eee'],
    'B.E. Electronics and Communication Engineering' => ['ece'], 'B.Tech ECE' => ['ece'], 'BE E&C' => ['ece'],
    'B.E. Electronics and Instrumentation Engineering' => ['eie'], 'B.E. EIE' => ['eie'],
    'B.Tech Electrical and Computer Engineering' => ['electrical_computer'], 'B.E. Electrical Engineering' => ['electrical'],
    'B.E. Electromechanical Engineering' => ['electromechanical'], 'B.E. Electro-Mechanical' => ['electromechanical'],
    'B.E. Mechatronics Engineering' => ['mechatronics'], 'B.E. Production Engineering' => ['production'],
    'B.E. Industrial and Production Engineering' => ['industrial_production'], 'B.E. Manufacturing Engineering' => ['manufacturing'],
    'B.E. Industrial Engineering' => ['industrial'], 'B.E. Civil Engineering' => ['civil'], 'B.Tech Information Technology' => ['it'], 'B.Tech (IT)' => ['it'],
    'B.E. Automobile Engineering' => ['automobile'], 'B.Tech AI & DS' => ['ai_ds'], 'B.Tech Artificial Intelligence and Data Science' => ['ai_ds'],
    'B.Tech Electronics & Telecommunication' => ['etc'], 'B.E. Aeronautical Engineering' => ['aeronautical'], 'B.Tech Biotechnology' => ['biotech'],
    'B.E. Biomedical Engineering' => ['biomedical'], 'B.Tech Chemical Engineering' => ['chemical'],
] as $text => $want) {
    $got = B::branchesIn($text);
    $check("$text → " . implode(',', $want) . ' (got ' . implode(',', $got) . ')', $got === $want);
}

echo "\nThe rule, for the technician branches\n";
$acc = B::TECHNICIAN_DEFAULT;
foreach ([
    ['B.E. Mechanical Engineering (2024), Anna University', 'pass', 'degree'],
    ['B.Tech EEE 2023; HSC; SSLC', 'pass', 'degree'],
    ['Diploma in Mechanical Engineering (DME), 2019', 'pass', 'diploma'],
    ['DME', 'pass', 'diploma'],
    ['DEEE, 2018', 'pass', 'diploma'],
    ['B.E. Civil Engineering', 'fail', null],
    ['B.Tech CSE', 'fail', null],
    ['B.E. Computer Science and Engineering, 2024', 'fail', null],
    ['Diploma in Civil Engineering', 'fail', null],
    ['B.Sc Physics', 'fail', null],
    ['ITI Fitter', 'fail', null],
    ['B.E.', 'confirm', 'degree'],
    ['Diploma', 'confirm', 'diploma'],
    ['', 'confirm', null],
    ['B.E. Civil Engineering; Diploma in Mechanical Engineering', 'pass', 'diploma'],
    ['Diploma in EEE (2016), B.E. EEE (lateral entry, 2019)', 'pass', 'degree'],
    ['M.E. Manufacturing Engineering; B.E. Mechanical Engineering', 'pass', 'degree'],
    ['B.E. Mechatronics', 'pass', 'degree'],
    ['B.Tech Electronics and Instrumentation', 'pass', 'degree'],
] as [$q, $v, $lvl]) {
    [$gv, $why, $gl] = B::verdict($q, $acc);
    $check("'$q' → $v" . ($lvl ? " ($lvl)" : '') . " (got $gv" . ($gl ? " $gl" : '') . ": $why)", $gv === $v && $gl === $lvl);
}

echo "\nExperience windows by qualification\n";
$role = ['exp_min' => 0, 'exp_max' => 7, 'exp_allow_below' => 1, 'exp_allow_above' => 5, 'exp_by_qual' => json_encode(['degree' => [0, 6], 'diploma' => [2, 7]])];
$check('B.E.: 0 to 6', FR::windowFor($role, 'degree') === [0.0, 6.0]);
$check('diploma: 2 to 7', FR::windowFor($role, 'diploma') === [2.0, 7.0]);
$check('not known: the widest, 0 to 7', FR::windowFor($role, null) === [0.0, 7.0]);
$fr = ['exp_by_qual' => json_encode(['degree' => [0, 1]])] + $role;
$check('freshers position: no diploma window', FR::windowFor($fr, 'diploma') === false);
$w = FR::withWindow($role, 'diploma');
$check('diploma holder with 1.5 yrs is outside 2 to 7 (no allowance)', !\Eshcol\Ats\Services\RoleRouter::inWindow($w, 1.5));
$check('diploma holder with 2 yrs is in', \Eshcol\Ats\Services\RoleRouter::inWindow($w, 2.0));
$check('B.E. with 7.2 yrs is outside 0 to 6', !\Eshcol\Ats\Services\RoleRouter::inWindow(FR::withWindow($role, 'degree'), 7.2));
$check('windows text', FR::windowsText($role) === 'B.E. / B.Tech 0 to 6 years, Diploma 2 to 7 years');

echo "\nRegions and tiers\n";
$places = ['locality|ambattur' => ['district' => 'Chennai', 'state' => 'Tamil Nadu'], 'location|tiruvallur' => ['district' => 'Tiruvallur', 'state' => 'Tamil Nadu'],
    'location|madurai' => ['district' => 'Madurai', 'state' => 'Tamil Nadu'], 'location|bengaluru' => ['district' => 'Bengaluru Urban', 'state' => 'Karnataka'],
    'location|pune' => ['district' => 'Pune', 'state' => 'Maharashtra'], 'location|puducherry' => ['district' => 'Puducherry', 'state' => 'Puducherry'],
    'location|tambaram' => ['district' => 'Chengalpattu', 'state' => 'Tamil Nadu']];
$look = fn (string $c, string $p) => $places[$c . '|' . mb_strtolower($p)] ?? null;
$t = R::DEFAULT_TIERS;
foreach ([
    [['locality' => 'Ambattur', 'location' => 'Chennai'], 1, 'locality'],
    [['location' => 'Tiruvallur'], 1, 'town'],
    [['location' => 'Tambaram, Chennai'], 1, 'town'],
    [['location' => 'Madurai'], 2, 'town'],
    [['location' => 'Puducherry'], 2, 'town'],
    [['location' => 'Bengaluru'], 3, 'town'],
    [['location' => 'Pune'], 0, 'town'],
    [['location' => 'Some Village', 'pin_code' => '602001'], 1, 'pin'],
    [['location' => 'Some Village', 'pin_code' => '625001'], 2, 'pin'],
    [['location' => 'Some Village', 'pin_code' => '682001'], 3, 'pin'],
    [['location' => 'Some Village', 'pin_code' => '411001'], 0, 'pin'],
    [['location' => 'Some Village'], null, null],
    [['location' => 'Pune', 'home_district' => 'Chengalpattu'], 1, 'form'],
    [['home_district' => 'Kerala'], 3, 'form'],
    [['home_district' => R::ANOTHER_STATE], 0, 'form'],
    [['location' => 'Kanchipuram'], 1, 'town'],
    [['location' => 'Kerala'], 3, 'town'],
] as [$cand, $tier, $from]) {
    $reg = R::of($cand, $look);
    $got = R::tierFor($t, $reg);
    $check(json_encode($cand) . " → tier " . var_export($tier, true) . ' from ' . var_export($from, true) . ' (got ' . var_export($got, true) . ' from ' . var_export($reg['from'] ?? null, true) . ')',
        $got === $tier && ($reg['from'] ?? null) === $from);
}
$check('labels', R::label(1, ['district' => 'Tiruvallur', 'state' => 'Tamil Nadu']) === 'Tier 1 · Tiruvallur'
    && R::label(3, ['district' => 'Bengaluru Urban', 'state' => 'Karnataka']) === 'Tier 3 · Bengaluru Urban, Karnataka'
    && R::label(null, null) === 'District not known');
$check('typed list tidied', R::parseList('thiruvallur, Kanchipuram; chengalpet / madras') === ['Tiruvallur', 'Kancheepuram', 'Chengalpattu', 'Chennai']);

echo "\nRouting on a test position (database)\n";
$app = App::boot(cli: true);
$db = $app->db;
$fr = new FR($app);
$pos = ['id' => 0, 'title' => 'Trainee Technicians', 'location' => 'Tiruvallur', 'location_scope' => 'tiers', 'location_tiers' => json_encode(R::DEFAULT_TIERS),
    'eng_branches' => implode(',', B::TECHNICIAN_DEFAULT), 'exp_by_qual' => json_encode(['degree' => [0, 6], 'diploma' => [2, 7]]),
    'exp_min' => 0, 'exp_max' => 7, 'exp_allow_below' => 0, 'exp_allow_above' => 0, 'work_mode' => 'office', 'function_codes' => null];
$cat = ['function_code' => 'X', 'secondary_code' => null, 'preferred_cities' => ''];
$cand = fn (array $c) => $c + ['id' => 0, 'exp_months' => 0, 'exp_as_of' => date('Y-m-d'), 'location' => 'Chennai', 'locality' => null, 'pin_code' => null, 'home_district' => null];
[$res, $why] = $fr->screenFail($cand(['qualifications' => 'B.E. Civil Engineering']), $cat, $pos) ?? ['none', ''];
$check("Civil is screened out ($why)", $res === 'not_qualified');
[$res, $why] = $fr->screenFail($cand(['qualifications' => 'B.E. Mechanical', 'location' => 'Pune']), $cat, $pos) ?? ['none', ''];
$check("Pune is screened out ($why)", $res === 'other_city');
$check('Mechanical from Chennai passes the screen', $fr->screenFail($cand(['qualifications' => 'B.E. Mechanical']), $cat, $pos) === null);
$check('Branch not shown passes the screen (the form asks)', $fr->screenFail($cand(['qualifications' => 'B.E.']), $cat, $pos) === null);

[$res] = $fr->decide($cand(['qualifications' => 'B.E. Mechanical', 'exp_months' => 0]), $cat, ['function_codes' => 'MFG,QHS,SCM'] + $pos);
$check("a fresher's field doesn't keep them off a position with branches ($res)", $res === 'pipeline');
[$res] = $fr->decide($cand(['qualifications' => 'B.E. Mechanical', 'exp_months' => 36]), $cat, ['function_codes' => 'MFG,QHS,SCM'] + $pos);
$check("an experienced candidate in another field is still a different field ($res)", $res === 'other_field');
[$res, $why] = $fr->decide($cand(['qualifications' => 'Diploma in Mechanical Engineering', 'exp_months' => 14]), $cat, $pos);
$check("a diploma holder with 14 months is outside 2 to 7 ($why)", $res === 'outside_band');
[$res, $why] = $fr->decide($cand(['qualifications' => 'Diploma in Mechanical Engineering', 'exp_months' => 30]), $cat, $pos);
$check("a diploma holder with 30 months is in ($why)", $res === 'pipeline');
[$res, $why] = $fr->decide($cand(['qualifications' => 'B.E. EEE', 'exp_months' => 90]), $cat, $pos);
$check("a B.E. with 7.5 years is outside 0 to 6 ($why)", $res === 'outside_band');

echo "\nThe details form\n";
$qs = new \Eshcol\Ats\Services\Questionnaires($app);
$keys = array_column($qs->questionsFor([$pos + ['screening' => null, 'engagement' => 'staffing', 'client_name' => 'X']], $cand(['qualifications' => 'B.E.', 'last_confirmed_at' => null, 'field_sources' => null])), null, 'key');
if (R::ready($app)) {
    $check('asks for the district', isset($keys['district']) && in_array('Tiruvallur', $keys['district']['options'], true) && in_array('Kerala', $keys['district']['options'], true));
    $check('the district is prefilled from the town (Chennai)', ($keys['district']['prefill'] ?? null) === 'Chennai');
} else {
    $check('no district question before update 076', !isset($keys['district']));
}
$check('asks for the branch, required', isset($keys['qualifications']) && $keys['qualifications']['required'] && str_contains($keys['qualifications']['prompt'], 'branch'));
$check('the relocation question mentions relocating', str_contains((string) ($keys['relocate']['prompt'] ?? ''), 'relocating to Tiruvallur'));

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
