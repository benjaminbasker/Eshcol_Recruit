<?php
/**
 * The remembered relevance check (audit B12, 15 Nov 2026): relevanceMany() gives exactly the answers relevance()
 * gives, keeps them, and checks again when the candidate, their CV, the position or its must-haves change.
 * Throwaway data, removed afterwards. LOCAL ONLY. Run: php tests/relevance-cache-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FitAssessor;
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
$client = $db->insert('clients', ['name' => "ZZ Relevance $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Executive ZZ$tag", 'location' => 'Pune', 'status' => 'open', 'accepts_routed' => 0,
    'score_threshold' => 0, 'exp_min' => 2, 'exp_max' => 5, 'jd_text' => 'Accounts. Tally, GST, TDS.']);
foreach (['Tally', 'GST'] as $i => $v) {
    $db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => $v, 'sort' => $i]);
}
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $routingWas, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-rel-$tag.txt");
$person = function (string $first, string $body) use ($imp, $tmp, $owner, $tag, $role, $db, &$made): int {
    $filler = str_repeat('Prepared monthly reports, reconciled ledgers and supported audits for small business clients. ', 4);
    file_put_contents($tmp, "$first Zzrel\n" . strtolower($first) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nPune\n$body\n$filler\nB.Com");
    $r = $imp->import($tmp, "$first.txt", $role, $owner);
    $made[] = (int) $r['candidate_id'];
    return (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [(int) $r['candidate_id'], $role]);
};
$fits = $person('Fitting', "Accountant, 2021 – Present\n4 years of experience with Tally, GST and TDS returns.");
$other = $person('Driver', "Delivery driver, 2021 – Present\n4 years driving light vehicles across the city.");
@unlink($tmp);
$fit = new FitAssessor($app);
$ids = [$fits, $other];

echo "Same answers as the check itself\n";
$fresh = [$fits => $fit->relevance($fits), $other => $fit->relevance($other)];
$many = $fit->relevanceMany($role, $ids);
$check('both answers match relevance()', $many[$fits] === $fresh[$fits] && $many[$other] === $fresh[$other]);
$check('the accountant is relevant, the driver is not (with a reason)', $many[$fits][0] === true && $many[$other][0] === false && $many[$other][1] !== '');
$check('both answers are kept', (int) $db->col("SELECT COUNT(*) FROM relevance_cache WHERE application_id IN ($fits, $other)") === 2);

echo "\nKept between visits\n";
$db->run("UPDATE relevance_cache SET why = 'remembered' WHERE application_id = ?", [$other]);
$check('a second visit uses the kept answer (no new check)', $fit->relevanceMany($role, $ids)[$other][1] === 'remembered');

echo "\nChecked again when something changes\n";
$cid = (int) $db->col('SELECT candidate_id FROM applications WHERE id = ?', [$fits]);
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$cid]);
$check('the candidate changed (do not contact): no longer relevant', $fit->relevanceMany($role, $ids)[$fits] === [false, 'do not contact']);
$db->run('UPDATE candidates SET do_not_contact = 0 WHERE id = ?', [$cid]);
$check('and back again', $fit->relevanceMany($role, $ids)[$fits] === [true, '']);
$db->run("UPDATE role_criteria SET value = 'SAP FICO' WHERE role_id = ? AND value = 'GST'", [$role]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'Power BI', 'sort' => 3]);
$after = $fit->relevanceMany($role, $ids);
$check('the must-haves changed: the answer follows the new must-haves', $after[$fits] === $fit->relevance($fits) && $after[$other][1] !== 'remembered');
$db->run("UPDATE relevance_cache SET why = 'stale', checked_at = NOW() - INTERVAL 2 DAY WHERE application_id = ?", [$other]);
$check('an answer older than a day is checked again', $fit->relevanceMany($role, $ids)[$other][1] !== 'stale');
$check('nothing asked, nothing returned', $fit->relevanceMany($role, []) === []);
