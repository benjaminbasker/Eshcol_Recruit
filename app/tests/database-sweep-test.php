<?php
/**
 * 23 Nov 2026: the morning sweep. Positions with it on get "Add all relevant" from the database every morning;
 * others are untouched; nobody is emailed. LOCAL ONLY. Run: php tests/database-sweep-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\DatabaseSweep;
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
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$tag = bin2hex(random_bytes(3));
$routingWas = $app->setting('routing_enabled', '1');
$db->run("UPDATE settings SET v = '0' WHERE k = 'routing_enabled'");
$client = $db->insert('clients', ['name' => "Zz Sweep Client $tag"]);
$mk = fn (string $t) => $db->insert('roles', ['client_id' => $client, 'title' => "$t $tag", 'location' => 'Chennai', 'status' => 'open', 'score_threshold' => 0, 'accepts_routed' => 0, 'exp_min' => 0, 'exp_max' => 3]);
$on = $mk('Zz Plant Trainee');
$off = $mk('Zz Plant Trainee Two');
foreach ([$on, $off] as $r) {
    foreach (['Assembly', 'Warehouse'] as $m) {
        $db->insert('role_criteria', ['role_id' => $r, 'kind' => 'must', 'value' => $m]);
    }
}
$made = [];
$tmp = $app->storage("uploads-tmp/zz-sweep-$tag.txt");
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
register_shutdown_function(function () use ($app, $db, &$made, $client, $on, $off, $routingWas, $tmp, $maxEmail, &$ok, &$bad) {
    foreach (array_unique($made) as $id) {
        (new Retention($app))->delete($id, 'manual', false);
    }
    $db->run('DELETE FROM email_queue WHERE id > ?', [$maxEmail]);
    $db->run("DELETE FROM settings WHERE k IN (?, ?, ?, ?)", ["role_sweep_$on", "role_sweep_$off", "role_sweep_last_$on", "role_sweep_last_$off"]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    @unlink($tmp);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$imp = new CandidateImporter($app);
$person = function (string $name, string $cv) use ($imp, $tmp, $uid, $tag, &$made): int {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 98765 4" . random_int(1000, 9999) . "\nChennai\n$cv");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', null, $uid);
    $made[] = (int) $r['candidate_id'];
    return (int) $r['candidate_id'];
};
$fits = $person('Ravi Sweep', "Diploma in Mechanical Engineering. 1 year of experience in assembly and warehouse work at a pump plant.");
$other = $person('Meena Sweep', "Accountant, 2 years of experience in Tally, GST and bank reconciliation. B.Com.");

echo "Switch\n";
$check('off by default', !DatabaseSweep::on($app, $on));
DatabaseSweep::set($app, $on, true);
$check('on once set', DatabaseSweep::on($app, $on) && !DatabaseSweep::on($app, $off));

echo "\nThe morning run\n";
$line = (new DatabaseSweep($app))->runDaily($uid);
$onRole = fn (int $c, int $r) => (bool) $db->col('SELECT 1 FROM applications WHERE candidate_id = ? AND role_id = ?', [$c, $r]);
$check('the assembly / warehouse person is added to the position with the sweep on', $onRole($fits, $on));
$check('the accountant is not', !$onRole($other, $on));
$check('the position with the sweep off is untouched', !$onRole($fits, $off));
$check('marked as added by the morning sweep', str_contains((string) $db->col('SELECT fit_note FROM applications WHERE candidate_id = ? AND role_id = ?', [$fits, $on]), 'morning sweep')
    || $db->col("SELECT source FROM applications WHERE candidate_id = ? AND role_id = ?", [$fits, $on]) === 'database');
$last = DatabaseSweep::last($app, $on);
$check('last run remembered with the names', $last && in_array('Ravi Sweep', $last['added'], true));
$check('the job line says how many', str_contains($line, '1 added'));
$check('nobody emailed', (int) $db->col('SELECT COUNT(*) FROM email_queue WHERE id > ? AND candidate_id IN (?, ?) AND subject NOT LIKE ?', [$maxEmail, $fits, $other, '%received%']) === 0);
(new DatabaseSweep($app))->runDaily($uid);
$check('the next morning adds nobody twice', (int) $db->col('SELECT COUNT(*) FROM applications WHERE candidate_id = ? AND role_id = ?', [$fits, $on]) === 1 && DatabaseSweep::last($app, $on)['added'] === []);
$db->run("UPDATE roles SET status = 'on_hold' WHERE id = ?", [$on]);
$check('a position on hold is skipped', str_contains((new DatabaseSweep($app))->runDaily($uid), 'no position'));
