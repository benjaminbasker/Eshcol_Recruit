<?php
/**
 * The joining record for the HRMS (plan v2, phase 3, 15 Nov 2026): what it holds for our own team and for staffing,
 * what it never holds, that client recruitment gets none (and our own team and staffing get no placement fee), and the
 * spreadsheet row. Throwaway data, removed afterwards. LOCAL ONLY. Run: php tests/joinings-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Engagement;
use Eshcol\Ats\Services\Joinings;
use Eshcol\Ats\Services\Placements;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Staffing;

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
$client = $db->insert('clients', ['name' => "Zz Joining Client $tag", 'legal_name' => "Zz Joining Client Pvt Ltd $tag", 'gstin' => '33ABCDE1234F1Z5', 'interview_address' => 'Plot 4, SIPCOT, Gummidipoondi, Tiruvallur']);
$termsId = $db->insert('client_terms', ['client_id' => $client, 'billing_model' => 'markup', 'markup_pct' => 10, 'payment_due_days' => 5, 'created_by' => $owner]);
$staffRole = $db->insert('roles', ['client_id' => $client, 'engagement' => 'staffing', 'contract_months' => 12, 'work_kind' => 'factory', 'skill_level' => 'semi_skilled',
    'title' => "Machine Operator ZZ$tag", 'location' => 'Tiruvallur', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$ownRole = $db->insert('roles', ['client_id' => Engagement::selfClientId($app), 'engagement' => 'inhouse', 'hiring_manager_id' => $owner,
    'title' => "Recruiter ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$placeRole = $db->insert('roles', ['client_id' => $client, 'title' => "Accountant ZZ$tag", 'location' => 'Pune', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $staffRole, $ownRole, $placeRole, $routingWas, &$ok, &$bad) {
    $db->run('DELETE FROM joinings WHERE full_name = ?', ['Selvi Zzjoin']);
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run("DELETE FROM placements WHERE role_id IN ($staffRole, $ownRole, $placeRole)");
    $db->run("DELETE FROM roles WHERE id IN ($staffRole, $ownRole, $placeRole)");
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-join-$tag.txt");
file_put_contents($tmp, "Selvi Zzjoin\nselvi.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nTiruvallur\nMachine Operator, 2020 – Present\nCNC.\nITI Fitter");
$r = $imp->import($tmp, 'selvi.txt', $staffRole, $owner);
@unlink($tmp);
$cid = (int) $r['candidate_id'];
$made[] = $cid;
$imp->attach($cid, $ownRole, $owner, 'database');
$imp->attach($cid, $placeRole, $owner, 'database');
$appOf = fn (int $role) => (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $role]);
$calc = Staffing::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'gst_pct' => 18], null, (new Staffing($app))->values(), 12500);
$db->insert('offer_approvals', ['application_id' => $appOf($staffRole), 'designation' => 'Machine Operator', 'ctc_lpa' => $calc['ctc_lpa'], 'gross_month' => 18000,
    'bill_month' => $calc['bill'], 'cost_month' => $calc['cost'], 'margin_month' => $calc['margin'], 'calc' => json_encode($calc), 'client_terms_id' => $termsId,
    'joining_date' => '2026-12-01', 'approved_by' => $owner, 'approved_at' => date('Y-m-d H:i:s')]);
$db->insert('offer_approvals', ['application_id' => $appOf($ownRole), 'designation' => 'Recruiter', 'ctc_lpa' => 3.6, 'joining_date' => '2026-12-15', 'approved_by' => $owner, 'approved_at' => date('Y-m-d H:i:s')]);
foreach (['pan', 'bank', 'consent'] as $it) {
    $db->insert('joining_items', ['application_id' => $appOf($staffRole), 'item' => $it, 'received_on' => '2026-11-20', 'user_id' => $owner]);
}
$db->insert('interview_feedback', ['application_id' => $appOf($staffRole), 'round' => 1, 'user_id' => $owner, 'interviewer' => 'Plant HR', 'rating' => 4, 'recommend' => 'yes']);

$j = new Joinings($app);
echo "Staffing\n";
$sid = $j->record($appOf($staffRole), $owner);
$p = json_decode((string) $db->col('SELECT payload FROM joinings WHERE id = ?', [$sid]), true);
$check('a record is made, ready for the HRMS', $sid && $db->col('SELECT status FROM joinings WHERE id = ?', [$sid]) === 'ready');
$check('format eshcol.joining v1', $p['format'] === 'eshcol.joining' && $p['version'] === 1);
$check('the person', $p['person']['full_name'] === 'Selvi Zzjoin' && str_starts_with((string) $p['person']['email'], 'selvi.'));
$check('the job: staffing, joins 1 Dec 2026, 12-month contract to 30 Nov 2027', $p['job']['type'] === 'staffing' && $p['job']['joining_date'] === '2026-12-01' && $p['job']['contract_end'] === '2027-11-30');
$check('the pay: ₹18,000 gross, ESI applies, bonus eligible', $p['pay']['gross_month'] == 18000 && $p['pay']['esi_applicable'] === true && $p['pay']['bonus_eligible'] === true);
$check('the deployment: the client, its GSTIN and site, the bill and terms', $p['deployment']['client_gstin'] === '33ABCDE1234F1Z5' && str_contains((string) $p['deployment']['client_site'], 'Gummidipoondi')
    && abs($p['deployment']['bill_month'] - $calc['bill']) < 0.01 && $p['deployment']['terms']['version_id'] === $termsId && $p['deployment']['terms']['conversion_fee_pct']['within_6_months'] == 8.33);
$check('the documents received (3), with dates', count($p['documents']) === 3 && $p['documents'][0]['received_on'] === '2026-11-20');
$check('how they were chosen: feedback and the approval', $p['selection']['interview_feedback'][0]['rating'] === 4 && $p['selection']['offer_approved_by'] !== null);
$json = json_encode($p);
$check('never an Aadhaar, PAN or bank number (only that they came in)', !preg_match('/aadhaa?r_?(no|number)|pan_?(no|number)|account_?(no|number)|ifsc/i', $json));
$check('no placement fee for staffing', !$db->col('SELECT id FROM placements WHERE application_id = ?', [$appOf($staffRole)])
    && (new Placements($app))->afterStage($appOf($staffRole), (int) $db->col("SELECT id FROM stages WHERE name = 'Joined'")) === null);
$row = Joinings::csvRow($p);
$check('the spreadsheet row lines up with its headings', count($row) === count(Joinings::CSV_HEAD) && $row[1] === 'Selvi Zzjoin' && $row[17] === '3 of 8');
$safe = Joinings::csvSafe(['=HYPERLINK("https://example.com","Open")', '@SUM(A1)', '-2+3+cmd|x', '+91 98765 43210', '-12.5', 4.2, 'Selvi', '']);
$check('audit S5: formula-like text is made plain text; phone numbers, numbers and names are untouched',
    $safe === ["'=HYPERLINK(\"https://example.com\",\"Open\")", "'@SUM(A1)", "'-2+3+cmd|x", '+91 98765 43210', '-12.5', 4.2, 'Selvi', '']);

echo "\nOur own team\n";
$oid = $j->record($appOf($ownRole), $owner);
$o = json_decode((string) $db->col('SELECT payload FROM joinings WHERE id = ?', [$oid]), true);
$check('a record with no deployment, reporting to the hiring manager', $o['job']['type'] === 'inhouse' && $o['deployment'] === null && $o['job']['reports_to'] !== null && $o['pay']['annual_ctc_lpa'] == 3.6);
$check('no placement fee for our own team', (new Placements($app))->afterStage($appOf($ownRole), (int) $db->col("SELECT id FROM stages WHERE name = 'Joined'")) === null);

echo "\nClient recruitment\n";
$check('no joining record (the client employs them)', $j->record($appOf($placeRole), $owner) === null);

echo "\nUpdating\n";
$db->insert('joining_items', ['application_id' => $appOf($staffRole), 'item' => 'photo', 'received_on' => '2026-11-22', 'user_id' => $owner]);
$check('recording again updates the same record', $j->record($appOf($staffRole), $owner) === $sid && count(json_decode((string) $db->col('SELECT payload FROM joinings WHERE id = ?', [$sid]), true)['documents']) === 4);
$db->run("UPDATE joinings SET status = 'imported' WHERE id = ?", [$sid]);
$db->run('DELETE FROM joining_items WHERE application_id = ?', [$appOf($staffRole)]);
$j->record($appOf($staffRole), $owner);
$check('once in the HRMS it is left alone', count(json_decode((string) $db->col('SELECT payload FROM joinings WHERE id = ?', [$sid]), true)['documents']) === 4);
