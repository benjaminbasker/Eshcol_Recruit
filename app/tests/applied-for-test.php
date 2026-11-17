<?php
/**
 * 17 Nov 2026 (Palani K, audit Eshcol_Recruit_Website_Applicant_Audit_17Nov2026.md): a website applicant whose job
 * isn't a position in the app keeps the job's name, is filed in that job's talent pool, is thanked for that job,
 * and the CV report compares them only with positions in their field. Run: php tests/applied-for-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CvReport;
use Eshcol\Ats\Services\FunctionRouting;
use Eshcol\Ats\Services\MailIntake;

$app = App::boot(cli: true);
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$keys = ['category_mode', 'routing_enabled', 'talent_pools_enabled', 'auto_sort_unassigned'];
$was = [];
foreach ($keys as $k) {
    $was[$k] = $app->setting($k);
}
$set = fn (string $k, ?string $v) => $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
foreach (['category_mode' => 'on', 'routing_enabled' => '1', 'talent_pools_enabled' => '1', 'auto_sort_unassigned' => '1'] as $k => $v) {
    $set($k, $v);
}
$maxRole = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM roles');
$clientId = $db->insert('clients', ['name' => "ZZ Applied $tag"]);
$made = [];
register_shutdown_function(function () use ($db, &$made, $clientId, $was, $set, $maxRole, &$ok, &$bad) {
    foreach ($made as $cid) {
        $db->run('DELETE FROM applications WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM candidate_categories WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM email_queue WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM acknowledgements WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$cid]);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM roles WHERE id > ? AND is_pool = 1 AND id NOT IN (SELECT role_id FROM applications)', [$maxRole]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($was as $k => $v) {
        $set($k, $v);
    }
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});

echo "The job applied for, from the subject\n";
$check('the website form: "Job application — Document Controller, EPC Projects (Chennai) — Palani K"',
    MailIntake::appliedFromSubject('Job application — Document Controller, EPC Projects (Chennai) — Palani K') === 'Document Controller, EPC Projects (Chennai)');
$check('…with a plain hyphen and a Fwd:', MailIntake::appliedFromSubject('Fwd: Job application - Accounts Officer (Pune) - Meena R') === 'Accounts Officer (Pune)');
$check('"Application for the post of Piping Designer"', MailIntake::appliedFromSubject('Application for the post of Piping Designer') === 'Piping Designer');
$check('"Applying for HR Executive position, 5 yrs" (the word "position" dropped)', MailIntake::appliedFromSubject('Applying for HR Executive position, 5 yrs') === 'HR Executive');
$check('a subject that names no job: nothing', MailIntake::appliedFromSubject('My CV') === null && MailIntake::appliedFromSubject('Re: Next step for your application') === null
    && MailIntake::appliedFromSubject('CV for any accounts opening') === null && MailIntake::appliedFromSubject('Application for any suitable role') === null);
$check('the city in brackets', MailIntake::cityInTitle('Document Controller, EPC Projects (Chennai)') === 'Chennai' && MailIntake::cityInTitle('Piping Designer') === '');

echo "\nFiled in the pool of the job applied for\n";
$mk = function (string $name, ?string $applied, string $fn, ?string $sec, string $title) use ($db, $tag, &$made): int {
    $cid = $db->insert('candidates', ['full_name' => "$name $tag", 'email' => strtolower($name) . ".$tag@candidates.test", 'location' => 'Chennai', 'current_title' => $title,
        'applied_for' => $applied, 'exp_months' => 180, 'exp_as_of' => date('Y-m-d'), 'channel' => 'website', 'source' => 'email']);
    $db->insert('candidate_categories', ['candidate_id' => $cid, 'function_code' => $fn, 'secondary_code' => $sec, 'seniority' => 'senior', 'source' => 'ai', 'is_current' => 1]);
    $made[] = $cid;
    return $cid;
};
$fr = new FunctionRouting($app);
$palani = $mk('Palani', 'Document Controller, EPC Projects (Chennai)', 'ITS', 'EPC', 'Senior Consultant (Power BI Analyst)');
$pc = $db->one('SELECT * FROM candidates WHERE id = ?', [$palani]);
$cat = ['function_code' => 'ITS', 'secondary_code' => 'EPC'];
[$code, $city, $note] = $fr->poolFor($pc, $cat);
$check('the job applied for decides the field: Document Controller → Engineering Projects, Site & Document Control', $code === 'EPC');
$check('…and the city on the posting', $city === 'Chennai' && $note === 'Filed by the job applied for: Document Controller, EPC Projects (Chennai)');
$added = $fr->route($palani, null, $uid);
$pool = $added ? $db->one('SELECT * FROM roles WHERE id = ?', [$added[0]['role_id']]) : null;
$check('routed: filed in the "Engineering Projects, Site & Document Control · Chennai" pool, not the IT one', $pool && $pool['is_pool'] && $pool['title'] === 'Engineering Projects, Site & Document Control · Chennai');
$check('…the note on the application says why', $db->col('SELECT fit_note FROM applications WHERE candidate_id = ? AND role_id = ?', [$palani, $pool['id'] ?? 0]) === 'Filed by the job applied for: Document Controller, EPC Projects (Chennai)');
$noJob = $mk('Ravi', null, 'ITS', null, 'Software Developer');
$rc = $db->one('SELECT * FROM candidates WHERE id = ?', [$noJob]);
[$code2, $city2, $note2] = $fr->poolFor($rc, ['function_code' => 'ITS', 'secondary_code' => null]);
$check('no job in the subject: their own field (from their latest job) and the city they live in', $code2 === 'ITS' && $city2 === 'Chennai' && $note2 === 'Filed by field and city');
$odd = $mk('Uma', 'Something Nobody Can Place', 'HRR', null, 'HR Executive');
[$code3] = $fr->poolFor($db->one('SELECT * FROM candidates WHERE id = ?', [$odd]), ['function_code' => 'HRR']);
$check('a job title the app can\'t place: falls back to their field', $code3 === 'HRR');

echo "\nThe thank-you email names the job\n";
$ack = new Acknowledger($app);
[$subject, $html, $text] = $ack->render($pc, [0], null);
$check('subject: "We\'ve received your application for Document Controller, EPC Projects (Chennai)"', $subject === "We've received your application for Document Controller, EPC Projects (Chennai)");
$check('…the body names the position, not "a role with an Eshcol Global client"', str_contains($text, 'Your application for the Document Controller, EPC Projects (Chennai) position has reached us safely') && !str_contains($text, 'a role with an Eshcol Global client'));
$check('…as an application, not a bare CV', str_contains($html, 'Application received'));
[$subject2, , $text2] = $ack->render($rc, [0], null);
$check('no job named: still "We\'ve received your CV"', $subject2 === "We've received your CV" && str_contains($text2, 'a role with an Eshcol Global client'));

echo "\nThe CV report compares only positions in their field\n";
$acc = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounting Manager ZZ$tag", 'location' => 'Pune', 'status' => 'open', 'function_codes' => 'ACC', 'exp_min' => 5, 'exp_max' => 10, 'accepts_routed' => 1, 'score_threshold' => 0, 'jd_text' => 'Accounts']);
$epc = $db->insert('roles', ['client_id' => $clientId, 'title' => "Document Controller ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'function_codes' => 'EPC', 'exp_min' => 5, 'exp_max' => 20, 'accepts_routed' => 0, 'score_threshold' => 0, 'jd_text' => 'Document control']);
$unknown = $db->insert('roles', ['client_id' => $clientId, 'title' => "Zz Mystery Post $tag", 'location' => 'Chennai', 'status' => 'open', 'function_codes' => null, 'accepts_routed' => 0, 'score_threshold' => 0, 'jd_text' => 'x']);
$rep = new CvReport($app);
$all = $db->all("SELECT r.*, 'c' AS client FROM roles r WHERE r.id IN (?, ?, ?)", [$acc, $epc, $unknown]);
$kept = array_column($rep->inTheirField($pc, $all), 'id');
$check('an accounts position is left out for a document controller', !in_array($acc, $kept, true));
$check('a document-control position (their second field) stays', in_array($epc, $kept, true));
$check('a position whose field isn\'t known stays', in_array($unknown, $kept, true));
$check('a position they are already on always stays', in_array($acc, array_column($rep->inTheirField($pc, $all, [$acc]), 'id'), true));
$set('category_mode', 'off');
$check('categories off: nothing is filtered', count($rep->inTheirField($pc, $all)) === 3);
$set('category_mode', 'on');
$db->run('UPDATE roles SET status = ? WHERE id IN (?, ?)', ['filled', $epc, $unknown]);
$built = $rep->build($palani);
$check('no position in their field: the report says so and names the pool', $built && $built['roles'] === []
    && str_contains((string) $built['field_note'], 'No open position in their field (IT, Software & Data / Engineering Projects, Site & Document Control); they applied for Document Controller, EPC Projects (Chennai).')
    && str_contains((string) $built['field_note'], 'Filed in the Engineering Projects, Site & Document Control · Chennai talent pool')
    && str_contains($built['summary'], 'No open position in their field'));
