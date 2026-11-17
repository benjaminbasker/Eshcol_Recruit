<?php
/**
 * Places, localities, distance to the client's office, job titles, and Re-read all CVs.
 * LOCAL ONLY. Usage: php tests/places-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Commute;
use Eshcol\Ats\Services\CvReport;
use Eshcol\Ats\Services\FieldExtractor;
use Eshcol\Ats\Services\Rereader;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Skills;

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
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$routingWas = $app->setting('routing_enabled', '1');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$paused = array_map('intval', array_column($db->all("SELECT id FROM roles WHERE status = 'open'"), 'id'));
if ($paused) {
    $db->run("UPDATE roles SET status = 'on_hold' WHERE id IN (" . implode(',', $paused) . ')');
}
$clientId = $db->insert('clients', ['name' => "ZZ Places $tag", 'interview_address' => 'Office 301, Archway, Sopan Baug, Balewadi High Street, Balewadi, Pune 411045']);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $paused, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    if ($paused) {
        $db->run("UPDATE roles SET status = 'open' WHERE id IN (" . implode(',', $paused) . ')');
    }
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$skills = new Skills($db);
$x = new FieldExtractor($skills);

echo "Reading places, names, positions\n";
$r = $x->extract("SANYUKTA KADAM\nWakad, Pune 411057\nAccounts Executive, ABC Ltd, Aug 2021 - Present", 'ResumeSANYUKTAKADAM.pdf')['fields'];
$check('an ALL-CAPS file name finds the name in the CV', ($r['full_name'] ?? '') === 'Sanyukta Kadam');
$check('the locality gives the city: Wakad, Pune', ($r['locality'] ?? '') === 'Wakad' && ($r['location'] ?? '') === 'Pune');
$r = $x->extract("Rahul Sharma\nMumbai University, B.Com\nCurrent location: Kharadi", 'x.pdf')['fields'];
$check('"Current location" beats a university name higher up', ($r['locality'] ?? '') === 'Kharadi' && ($r['location'] ?? '') === 'Pune');
$r = $x->extract("Sagar Patil\nAccountant\nNashik 422001", 'y.pdf')['fields'];
$check('a first name like Sagar is not taken for the town', ($r['location'] ?? '') === 'Nashik');
$r = $x->extract("Meera Joshi\nAddress: Hiranandani, Powai\nGST Executive at XYZ", 'z.pdf')['fields'];
$check('a Mumbai locality and a new job title are read', ($r['locality'] ?? '') === 'Powai' && ($r['location'] ?? '') === 'Mumbai' && ($r['current_title'] ?? '') === 'GST Executive');
$r = $x->extract("Arjun Rao\nPhone 9876543210\nPIN 560034", 'w.pdf')['fields'];
$check('a PIN code alone gives the city', ($r['location'] ?? '') === 'Bengaluru');
$r = $x->extract("Neha Kulkarni\nJunior Accountant, Shah & Co, 2019 - 2021\nSenior Accountant, Mehta Traders, 2021 - 2024", 'v.pdf');
$check('experience is estimated from year-only job dates', ($r['fields']['exp_months'] ?? 0) >= 60 && ($r['sources']['exp_months'] ?? '') === 'estimated');

echo "\nExperience, cross-checked\n";
$old = $x->extract("Anita Rao\nAccounts Executive with 3 years of experience.\nAccounts Executive, Shah & Co, Jun 2019 - Jun 2022\nB.Com 2016 - 2019\nDate: 15/06/2022  Place: Pune", 'old.pdf');
$check('an old CV is dated from its "Date:" line', ($old['fields']['cv_dated'] ?? '') === '2022-06-01' && ($old['fields']['exp_as_of'] ?? '') === '2022-06-01');
$cOld = $old['fields'] + ['field_sources' => json_encode($old['sources'])];
$xo = Eshcol\Ats\Services\ExperienceCheck::assess($cOld);
$check('…its "3 years" grows from 2022, and it is marked as an old CV', exp_now($cOld) >= 36 + 40 && $xo['old'] && $xo['label'] === 'Consistent');
$mis = $x->extract("Vikram Shah\n8 years of experience in accounts.\nAccountant, Mehta Traders, Jan 2019 - Dec 2023", 'mis.pdf');
$xm = Eshcol\Ats\Services\ExperienceCheck::assess($mis['fields'] + ['field_sources' => json_encode($mis['sources'])]);
$check('a stated total far from the job dates is marked "Check", with both figures', $xm['label'] === 'Check' && $xm['check'] && str_contains($xm['note'], '8 yrs') && str_contains($xm['note'], '5 yrs'));
$ok2 = $x->extract("Neha Patil\n5 years of experience.\nAccountant, ABC Ltd, Jan 2021 - Present", 'ok.pdf');
$xk = Eshcol\Ats\Services\ExperienceCheck::assess($ok2['fields'] + ['field_sources' => json_encode($ok2['sources'])]);
$check('stated total and dates that agree are "Consistent"; a current job is not an old CV', $xk['label'] === 'Consistent' && !$xk['old']);
$aged = $x->extract("Ravi Kumar\n5 years of experience.\nAccountant, ABC Ltd, Jan 2019 - Present", 'aged.pdf');
$xa = Eshcol\Ats\Services\ExperienceCheck::assess($aged['fields'] + ['field_sources' => json_encode($aged['sources'])]);
$check('"5 years" with a job since 2019 still "Present": consistent, and the CV is about two years old', $xa['label'] === 'Consistent' && $xa['old'] && str_contains($xa['note'], 'written around'));
$long = ['exp_stated_months' => 168, 'exp_dates_months' => 189, 'cv_dated' => null, 'cv_has_current_job' => 0, 'exp_months' => 168, 'exp_as_of' => date('Y-m-d'), 'field_sources' => json_encode(['exp_months' => 'cv'])];
$check('14 years against 15¾ is within tolerance for a long career', Eshcol\Ats\Services\ExperienceCheck::assess($long)['label'] === 'Consistent');
$xc = Eshcol\Ats\Services\ExperienceCheck::assess($mis['fields'] + ['field_sources' => json_encode(['exp_months' => 'candidate'])]);
$check('the candidate\'s own answer wins, but the mismatch is still mentioned', $xc['label'] === 'From candidate' && str_contains($xc['note'], 'job dates'));

$brk = $x->extract("Lata Menon\nAccountant | ABC Ltd\nJan 2023 - Dec 2025: Accountant | ABC Ltd\nJan 2016 - Dec 2022: Career Break – Family Responsibilities\nJan 2012 - Dec 2015: Accounts Assistant | XYZ Traders", 'brk.pdf')['fields'];
$check('a stated career break is not counted as experience', ($brk['exp_dates_months'] ?? 0) <= 7 * 12 + 2 && ($brk['exp_dates_months'] ?? 0) >= 7 * 12 - 2);
$age = $x->extract("Rohit Pawar\nAge: 22 years\nExperience\nSr. Accountant since April 2024 - till date", 'age.pdf')['fields'];
$check('"Age: 22 years" is never read as 22 years of experience', ($age['exp_stated_months'] ?? null) === null);
$dob = $x->extract("Santosh Patil\nDate of Birth : 12/05/1990 (35 years)\nWorking as Accountant since April 2024 - till date", 'dob.pdf')['fields'];
$check('nor is a date of birth with the age after it', ($dob['exp_stated_months'] ?? null) === null);
$deg = $x->extract("Pallavi K\nSenior Accountant with 14 years of experience in accounts and GST.\n2008 - 2010: Master in Computer Management (MCM) Year\n2003 - 2006: Bachelor of Commerce Year", 'deg.pdf')['fields'];
$check('"Bachelor of Commerce" and "Master in …" are courses, not 7 years of work', ($deg['exp_dates_months'] ?? null) === null);
$check('…and a degree year does not make the CV look old', ($deg['cv_dated'] ?? null) === null);

echo "\nDistance to the client's office\n";
$cm = new Commute($skills);
$office = $cm->localityIn('Office 301, Archway, Sopan Baug, Balewadi High Street, Balewadi, Pune 411045');
$check('the office locality is read from the interview address: the wider area named last (Balewadi, not Sopan Baug)', ($office['locality'] ?? '') === 'Balewadi' && ($office['zone'] ?? '') === 'West');
$check('Wakad is near, Kothrud moderate, Hadapsar far', $cm->between('Wakad', 'Pune', $office)['level'] === 'near'
    && $cm->between('Kothrud', 'Pune', $office)['level'] === 'moderate' && $cm->between('Hadapsar', 'Pune', $office)['level'] === 'far');
$check('another city is said plainly', $cm->between(null, 'Mumbai', $office)['level'] === 'other_city');

echo "
Kilometres (16 Nov)
";
$check('map points from a Google Maps link: @lat,lng', Commute::pointFromMapsUrl("https://www.google.com/maps/place/x/@18.574688,73.7725158,17z") === ['lat' => 18.574688, 'lng' => 73.7725158]);
$check('…and the !3d…!4d form and ?q=lat,lng', Commute::pointFromMapsUrl('https://maps.google.com/?q=18.57,73.77') === ['lat' => 18.57, 'lng' => 73.77]
    && Commute::pointFromMapsUrl('https://www.google.com/maps/place/x/data=!3d18.5746!4d73.7725') === ['lat' => 18.5746, 'lng' => 73.7725]);
$check('a short link without coordinates gives none', Commute::pointFromMapsUrl('https://maps.app.goo.gl/AbCdEf') === null && Commute::pointFromMapsUrl(null) === null);
$check('straight-line km: Balewadi office to Pune railway station is about 11 km', abs(Commute::km(['lat' => 18.574688, 'lng' => 73.7725158], ['lat' => 18.5289, 'lng' => 73.8744]) - 11.8) < 1.0);
$check('labels: "about 8 km", "under 2 km"', Commute::kmLabel(8) === 'about 8 km' && Commute::kmLabel(1) === 'under 2 km');
$kmOffice = $cm->localityIn('Office 301, Archway, Sopan Baug, Balewadi High Street, Balewadi, Pune 411045', 'https://www.google.com/maps/place/x/@18.574688,73.7725158,17z');
$gk = $cm->between('Gokhalenagar', 'Pune', $kmOffice);
$check('Gokhalenagar to the Balewadi office: Moderate, with about 5 to 9 km (after update 056)', ($gk['level'] ?? '') === 'moderate' && isset($gk['km']) && $gk['km'] >= 5 && $gk['km'] <= 9
    && str_contains($gk['label'], 'Moderate: Central to West Pune · about '));
$check('a different city: "Lives in Mumbai", with the straight-line km from its centre (17 Nov 2026)', ($cm->between(null, 'Mumbai', $kmOffice)['km'] ?? 0) > 100 && str_starts_with($cm->between(null, 'Mumbai', $kmOffice)['label'], 'Lives in Mumbai'));

echo "\nCV report and Re-read all CVs\n";
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounting Associate $tag", 'location' => 'Pune', 'status' => 'open', 'exp_min' => 2, 'exp_max' => 3, 'code' => 'ZZP-' . random_int(1000, 9999)]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'GST']);
$tmp = $app->storage("uploads-tmp/zz-pl-$tag.txt");
file_put_contents($tmp, "Komal Tuplondhe\nkomal.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nAddress: Sai Residency, Hadapsar, Pune\nAccounts Executive with 3 years of experience in GST and TDS returns and bank reconciliation.\nB.Com");
$imp = (new CandidateImporter($app))->import($tmp, 'ResumeKOMALTUPLONDHE.txt', $role, $uid);
@unlink($tmp);
$cid = (int) $imp['candidate_id'];
$made[] = $cid;
$rep = (new CvReport($app))->build($cid);
$mine = array_values(array_filter($rep['roles'], fn ($x) => $x['id'] === $role))[0] ?? [];
$check('the CV report shows the commute for the role', ($mine['commute']['level'] ?? '') === 'far' && ($mine['office'] ?? '') === 'Balewadi, Pune');
$check('…flags it, and suggests a question about travel', (bool) array_filter($rep['flags'], fn ($f) => $f['kind'] === 'commute') && (bool) array_filter($rep['questions'], fn ($q) => str_contains($q, 'travel')));
// As if read by the old reader: no locality, no city; and a position the recruiter confirmed.
$db->run("UPDATE candidates SET locality = NULL, location = NULL, current_title = 'Accounts Officer', field_sources = JSON_SET(COALESCE(field_sources, '{}'), '$.current_title', 'you') WHERE id = ?", [$cid]);
$res = (new Rereader($app))->run();
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('Re-read fills the missing locality and city', $c['locality'] === 'Hadapsar' && $c['location'] === 'Pune' && ($res['filled']['locality'] ?? 0) >= 1);
$check('…and never changes what you confirmed', $c['current_title'] === 'Accounts Officer');
$check('…and re-scores their roles', (bool) $db->col('SELECT scored_at FROM applications WHERE candidate_id = ? AND role_id = ? AND scored_at >= NOW() - INTERVAL 1 MINUTE', [$cid, $role]));

echo "\nThe locality list follows the city (17 Nov 2026: no list for Chennai)\n";
$lm = locality_map();
$check('Chennai has its localities, well over a hundred (update 068)', count($lm['cities']['Chennai'] ?? []) >= 150 && in_array('Velachery', $lm['cities']['Chennai'], true) && in_array('Tambaram', $lm['cities']['Chennai'], true));
$check('the city is found however it is written: "Chennai", "chennai, tamil nadu", "Madras"', locality_city('Chennai', $lm) === 'Chennai'
    && locality_city('chennai, tamil nadu', $lm) === 'Chennai' && locality_city('Madras', $lm) === 'Chennai');
$check('...and other cities: "Bangalore" is Bengaluru; a town with no list gives none (no wrong list)', locality_city('Bangalore', $lm) === 'Bengaluru' && locality_city('Pimprala, Jalgaon', $lm) === '');
$check('Chennai localities have their zones and common spellings ("R A Puram", "Chrompet")', (new \Eshcol\Ats\Services\Skills($db))->localityOf('r a puram')['zone'] === 'Central'
    && (new \Eshcol\Ats\Services\Skills($db))->canonical('chrompet', 'locality') === 'Chromepet');
echo "\nOther towns and cities (17 Nov 2026: Satara for a Pune role)\n";
$skD = new \Eshcol\Ats\Services\Skills($db);
$cmD = new \Eshcol\Ats\Services\Commute($skD);
$offD = $cmD->localityIn('Office 301, Archway, Sopan Baug, Balewadi High Street, Pune', null);
$sat = $cmD->between(null, 'Satara', $offD);
$check('Satara: another town, with the distance to the Balewadi office (about 100 km)', ($sat['level'] ?? '') === 'other_city' && ($sat['km'] ?? 0) >= 90 && ($sat['km'] ?? 0) <= 115
    && str_starts_with($sat['label'], 'Lives in Satara · about'));
$check('...and the client sees it: "About N km from your Balewadi office"', str_ends_with((string) \Eshcol\Ats\Services\Shortlists::distanceLine($sat, 'Balewadi'), 'km from your Balewadi office'));
$check('Karnataka too: Belgaum, Hubli, Gulbarga, Shimoga, Coorg are known towns with points', $skD->canonical('belgaum', 'location') === 'Belagavi' && $skD->canonical('gulbarga', 'location') === 'Kalaburagi'
    && \Eshcol\Ats\Services\Commute::pointOf($skD->canonical('hubli', 'location')) !== null && \Eshcol\Ats\Services\Commute::pointOf($skD->canonical('coorg', 'location')) !== null
    && \Eshcol\Ats\Services\Commute::pointOf($skD->canonical('shimoga', 'location')) !== null);
$check('district names find their headquarters: Ahmednagar, Osmanabad, Nilgiris, Kanyakumari, Trichy', $skD->canonical('ahmednagar', 'location') === 'Ahilyanagar'
    && $skD->canonical('osmanabad', 'location') === 'Dharashiv' && $skD->canonical('nilgiris', 'location') === 'Ooty'
    && $skD->canonical('kanyakumari', 'location') === 'Nagercoil' && $skD->canonical('trichy', 'location') === 'Tiruchirappalli');
$check('every district headquarters of Maharashtra and Tamil Nadu has a map point (e.g. Sindhudurg / Oros, Tenkasi, Ranipet)', \Eshcol\Ats\Services\Commute::pointOf($skD->canonical('oros', 'location')) !== null
    && \Eshcol\Ats\Services\Commute::pointOf('Tenkasi') !== null && \Eshcol\Ats\Services\Commute::pointOf('Ranipet') !== null && \Eshcol\Ats\Services\Commute::pointOf('Yavatmal') !== null);
$che = $cmD->between(null, 'Chennai', $offD);
$check('a big city given without a locality still gets a distance (its centre point)', ($che['km'] ?? 0) > 800);
$check('the major cities have full lists: Bengaluru, Delhi, Hyderabad, Kolkata over 100; Mumbai, Ahmedabad well over 60', count($lm['cities']['Bengaluru'] ?? []) >= 0
    && count(locality_map()['cities']['Bengaluru']) >= 140 && count(locality_map()['cities']['Delhi']) >= 100 && count(locality_map()['cities']['Hyderabad']) >= 100
    && count(locality_map()['cities']['Kolkata']) >= 100 && count(locality_map()['cities']['Mumbai']) >= 90 && count(locality_map()['cities']['Ahmedabad']) >= 60);
$check('cities that had none now have lists (Coimbatore, Kochi, Nagpur, Jaipur, Madurai...)', count(locality_map()['cities']['Coimbatore'] ?? []) >= 30 && count(locality_map()['cities']['Madurai'] ?? []) >= 20
    && count(locality_map()['cities']['Jaipur'] ?? []) >= 30);
$check('a name two cities share is kept for both: "Civil Lines (Nagpur)" beside Delhi\'s "Civil Lines"', in_array('Civil Lines (Nagpur)', locality_map()['cities']['Nagpur'] ?? [], true)
    && in_array('Civil Lines', locality_map()['cities']['Delhi'] ?? [], true));
$editSrc = (string) file_get_contents(dirname(__DIR__) . '/views/candidates/edit.php');
$check('the Edit page carries every city\'s list, and the script refills it when the City box changes', str_contains($editSrc, 'locality_map()') && str_contains($editSrc, 'id="locality-map"')
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js'), "getElementById('locality-map')"));
exit(0);
