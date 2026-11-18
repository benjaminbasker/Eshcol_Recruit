<?php
/**
 * Checks before a shortlist goes to a client (B11, 14 Nov 2026): permission, already sent to this client in 6
 * months (needs confirming), salary and notice unknown or over, an old CV, Claude's Weak; the reason kept; the CV
 * version pinned; chasing a client after 5 working days. Throwaway data. LOCAL ONLY. Run: php tests/submission-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Shortlists;
use Eshcol\Ats\Services\SubmissionChecks;

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
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$was = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "ZZ Checks $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Officer ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0,
    'ctc_max_lpa' => 4.2, 'notice_max_days' => 30]);
$role2 = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Manager ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $was, &$ok, &$bad) {
    $db->run('DELETE s FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ?', [$client]);
    $db->run("DELETE FROM email_queue WHERE subject LIKE '%ZZ%'");
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $was === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$was]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-sub-' . getmypid() . '.txt');
$person = function (string $name, int $roleId) use ($db, $imp, $tmp, $uid, $tag, &$made): array {
    file_put_contents($tmp, "$name\n" . strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999)
        . "\nPune\nAccountant, 2021 – Present\n4 years of experience, Tally, GST, bank reconciliation and month-end closing.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    @unlink($tmp);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $roleId])];
};
$chk = new SubmissionChecks($app);
$texts = fn (array $w) => implode(' | ', array_column($w, 0));

echo "What gets flagged\n";
[$ravi, $appRavi] = $person('Ravi Unknown', $role);
$db->run('UPDATE candidates SET ctc_min_lpa = NULL, ctc_expected_lpa = NULL, notice_days = NULL WHERE id = ?', [$ravi]);
$w = $chk->forApplication($appRavi);
$check('nothing recorded about permission: no warning any more (Benjamin, 17 Nov 2026); salary and notice not known still warn', !str_contains($texts($w), 'permission') && str_contains($texts($w), 'Salary expectation not known')
    && str_contains($texts($w), 'Notice period not known'));
$db->run("UPDATE candidates SET ctc_min_lpa = 5.5, notice_days = 60, cv_dated = '2024-06-01' WHERE id = ?", [$ravi]);
$db->insert('fit_assessments', ['application_id' => $appRavi, 'candidate_id' => $ravi, 'role_id' => $role, 'status' => 'done', 'score' => 40, 'band' => 'Weak']);
$w = $chk->forApplication($appRavi);
$check('over the budget, notice too long, an old CV, Claude rated Weak', str_contains($texts($w), 'above the budget of 4.2 LPA') && str_contains($texts($w), 'longer than the 30 days')
    && str_contains($texts($w), 'over a year old') && str_contains($texts($w), 'Weak'));
$check('none of those needs a confirming tick (a reason is enough)', !array_filter($w, fn ($x) => $x[1]));

echo "\nPermission\n";
$chk->setPermission($appRavi, true, 'phone', $uid);
$check('"They agreed" (after a call) still records it, and there is no warning', !str_contains($texts($chk->forApplication($appRavi)), 'permission')
    && $db->col('SELECT permission FROM applications WHERE id = ?', [$appRavi]) === 'yes');
$chk->setPermission($appRavi, false, 'phone', $uid);
$check('"Not yet, please ask me first" (the candidate asked) still warns: check with them first', str_contains($texts($chk->forApplication($appRavi)), 'check with them before'));

echo "\nAlready sent to this client\n";
[$sita, $appSita] = $person('Sita Clean', $role);
$db->run('UPDATE candidates SET ctc_min_lpa = 4, notice_days = 15, cv_dated = CURDATE() WHERE id = ?', [$sita]);
$chk->setPermission($appSita, true, 'details_form', null);
$check('a clean candidate: nothing to flag', $chk->forApplication($appSita) === []);
$sl = new Shortlists($app);
$first = $sl->create($role, [[$appSita, 'Good fit']], 'Client', "hr.$tag@client.test", '', '', false, $uid, 'profiles');
$check('the exact CV version is kept with the shortlist', (int) $db->col('SELECT cv_file_id FROM shortlist_items WHERE shortlist_id = ?', [$first]) > 0);
$check('sending a clean shortlist goes straight through', $sl->send($first, $uid) === null);
$appSita2 = $db->insert('applications', ['candidate_id' => $sita, 'role_id' => $role2, 'stage_id' => (int) $db->col("SELECT id FROM stages WHERE kind = 'active' ORDER BY sort LIMIT 1"), 'permission' => 'yes']);
$second = $sl->create($role2, [[$appSita2, 'Also fits']], 'Client', "hr.$tag@client.test", '', '', false, $uid, 'profiles');
$w = $chk->forShortlist($second);
$check('the same person for another position at the same client: flagged, needs confirming', isset($w[$appSita2]) && (bool) array_filter($w[$appSita2], fn ($x) => $x[1] && str_contains($x[0], 'Already sent to')));
$chk->recordReasons($second, $w, 'Client asked to see her for this role too');
$check('your reason is kept with the item', str_contains((string) $db->col('SELECT check_note FROM shortlist_items WHERE shortlist_id = ?', [$second]), 'Client asked to see her'));
$db->run('UPDATE shortlists SET sent_at = NOW() - INTERVAL 7 MONTH WHERE id = ?', [$first]);
$check('after 6 months it is no longer flagged', !isset($chk->forShortlist($second)[$appSita2]));
$db->run('UPDATE shortlists SET sent_at = NOW() WHERE id = ?', [$first]);

echo "\nThe client sees nothing of this\n";
$prof = json_decode((string) $db->col('SELECT profile FROM shortlist_items WHERE shortlist_id = ?', [$first]), true);
$check('the permission answer is not in the profile sent', !str_contains(json_encode($prof), 'put your profile forward'));

echo "\nChasing the client\n";
$check('working days skip the weekend (Fri 13 → Mon 16 Nov = 1)', SubmissionChecks::workingDaysBetween('2026-11-13', '2026-11-16') === 1);
$chk->clock = fn () => strtotime('+4 weekdays');
$check('4 working days after sending: not yet', !in_array($first, array_map('intval', array_column($chk->toChase(), 'id')), true));
$chk->clock = fn () => strtotime('+5 weekdays');
$check('5 working days, no decision: chase them (on My day)', in_array($first, array_map('intval', array_column($chk->toChase(), 'id')), true));
$chk->chased($first);
$check('"I chased them": off the list for another 5 working days', !in_array($first, array_map('intval', array_column($chk->toChase(), 'id')), true));
$db->run("UPDATE shortlist_items SET decision = 'shortlist' WHERE shortlist_id = ?", [$first]);
$chk->clock = fn () => strtotime('+15 weekdays');
$check('once the client has decided, no more chasing', !in_array($first, array_map('intval', array_column($chk->toChase(), 'id')), true));
$fb = $chk->feedback($role);
$check('the feedback strip counts the client\'s decisions', (int) ($fb[0]['yes'] ?? 0) === 1 && (int) $fb[0]['waiting'] === 0);
