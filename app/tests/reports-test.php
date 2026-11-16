<?php
/**
 * Reports (B10, 14 Nov 2026): the funnel counts each application at the furthest step it reached, sources by
 * how they came, time to fill from opening to offer and joining, Claude's cost per month. Throwaway data. LOCAL ONLY.
 * Run: php tests/reports-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Reports;
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
$client = $db->insert('clients', ['name' => "ZZ Reports $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Officer ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0,
    'opened_at' => date('Y-m-d', strtotime('-20 days'))]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, &$ok, &$bad) {
    $db->run('DELETE p FROM placements p JOIN roles r ON r.id = p.role_id WHERE r.client_id = ?', [$client]);
    foreach ($made as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    echo "\n$ok passed, $bad failed\n";
});
$stage = fn (string $like, string $kind = 'active') => (int) $db->col('SELECT id FROM stages WHERE kind = ? AND name LIKE ? ORDER BY sort LIMIT 1', [$kind, $like]);
$applied = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' ORDER BY sort LIMIT 1");
$submitted = $stage('Submitted%');
$interview = $stage('%interview%');
$joined = (int) $db->col("SELECT id FROM stages WHERE kind = 'success' ORDER BY sort LIMIT 1");
$notSuitable = (int) $db->col("SELECT id FROM stages WHERE kind = 'fail' ORDER BY sort LIMIT 1");
$person = function (string $name, string $channel, array $path) use ($db, $role, $applied, $tag, &$made): int {
    $cid = $db->insert('candidates', ['full_name' => "$name $tag", 'source' => 'upload', 'channel' => $channel]);
    $made[] = $cid;
    $a = $db->insert('applications', ['candidate_id' => $cid, 'role_id' => $role, 'stage_id' => $applied, 'channel' => $channel]);
    $from = $applied;
    foreach ($path as $to) {
        $db->insert('stage_history', ['application_id' => $a, 'from_stage_id' => $from, 'to_stage_id' => $to]);
        $db->update('applications', ['stage_id' => $to], $a);
        $from = $to;
    }
    return $a;
};
$person('Asha', 'naukri', [$submitted, $interview, $joined]);
$person('Bala', 'naukri', [$submitted, $interview, $notSuitable]); // got to interview, then not suitable
$person('Chitra', 'indeed', [$submitted]);
$person('Deepak', 'indeed', []);
$db->insert('placements', ['role_id' => $role, 'client_id' => $client, 'status' => 'joined', 'offer_date' => date('Y-m-d', strtotime('-8 days')), 'joined_on' => date('Y-m-d', strtotime('-2 days'))]);

$r = new Reports($app);
[$from, $to] = Reports::range('quarter');
$f = array_column($r->funnel($from, $to), 'n', 'step');
echo "The funnel\n";
$check('everyone counts as applied; three were sent to the client', ($f['Applied'] ?? 0) >= 4 && ($f['Sent to client'] ?? 0) >= 3);
$check('someone who reached interview and then left still counts at interview', ($f['Client interview'] ?? 0) >= 2);
$check('one joined', ($f['Joined'] ?? 0) >= 1);

echo "\nWhere hires come from\n";
$s = array_column($r->sources($from, $to), null, 'source');
$check('Naukri: 2 CVs, 2 interviewed, 1 joined', ($s['Naukri']['cvs'] ?? 0) >= 2 && ($s['Naukri']['interviewed'] ?? 0) >= 2 && ($s['Naukri']['joined'] ?? 0) >= 1);
$check('Indeed: 2 CVs, 1 sent, none joined', ($s['Indeed']['cvs'] ?? 0) >= 2 && ($s['Indeed']['sent'] ?? 0) >= 1);

echo "\nTime to fill\n";
$t = array_values(array_filter($r->timeToFill($from, $to), fn ($x) => (int) $x['id'] === $role))[0] ?? null;
$check('opened 20 days ago: first offer after 12 days, joined after 18', $t && (int) $t['to_offer'] === 12 && (int) $t['to_join'] === 18);

echo "\nPeriods and Claude\n";
[$a, $b] = Reports::range('fy', strtotime('2026-11-14'));
$check('the financial year runs from 1 April', $a === '2026-04-01');
[$a] = Reports::range('fy', strtotime('2027-02-10'));
$check('…so in February it is still the year that began last April', $a === '2026-04-01');
$c = $r->claudeByMonth(6);
$check('Claude cost: six months, newest last', count($c) === 6 && $c[5]['month'] === date('Y-m'));
