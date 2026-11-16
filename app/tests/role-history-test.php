<?php
/**
 * Position history (B13) and the new-position checklist (B14), 14 Nov 2026. Throwaway data. LOCAL ONLY.
 * Run: php tests/role-history-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\FitAssessor;
use Eshcol\Ats\Services\RoleHistory;

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
$client = $db->insert('clients', ['name' => "ZZ History $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Officer ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
register_shutdown_function(function () use ($db, $client, &$ok, &$bad) {
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    echo "\n$ok passed, $bad failed\n";
});
$h = new RoleHistory($app);
$row = fn () => $db->one('SELECT * FROM roles WHERE id = ?', [$role]);

echo "The checklist\n";
$todo = $h->toClarify($row());
$check('a bare new position lists everything to clarify', count($todo) === 7 && in_array('Budget (the most the client will pay)', $todo, true)
    && in_array('Who at the client decides', $todo, true) && in_array('At least three must-haves (0 so far)', $todo, true));
$db->run("UPDATE roles SET ctc_max_lpa = 4.2, notice_max_days = 30, location = 'Pune', exp_min = 2, exp_max = 5, interview_rounds = 2, decision_maker = 'Finance Head' WHERE id = ?", [$role]);
(new FitAssessor($app))->saveRequirements($role, [['text' => 'GST returns', 'critical' => true], ['text' => 'Tally'], ['text' => 'Bank reconciliation']]);
$check('once all are in: nothing left to clarify', $h->toClarify($row()) === []);
$check('a talent pool never shows the checklist', $h->toClarify(['is_pool' => 1] + $row()) === []);

echo "\nThe history\n";
$before = $h->snapshot($role);
$check('saving without changes records nothing', $h->record($role, $before, $h->snapshot($role), $uid) === 0);
$check('"4.20" and "4.2" are the same budget', $h->record($role, ['ctc_max_lpa' => '4.20'] + $before, $before, $uid) === 0);
$db->run('UPDATE roles SET ctc_max_lpa = 5, notice_max_days = 45 WHERE id = ?', [$role]);
(new FitAssessor($app))->saveRequirements($role, [['text' => 'GST returns', 'critical' => true], ['text' => 'Tally Prime'], ['text' => 'Bank reconciliation']]);
$n = $h->record($role, $before, $h->snapshot($role), $uid);
$check('a budget, notice and must-have change: three changes recorded', $n === 3);
$lines = $h->history($role)[0]['lines'] ?? [];
$check('in plain words (Budget: 4.2 → 5)', in_array('Budget: 4.2 → 5', $lines, true));
$check('must-haves show what was added and removed', in_array('Must-haves: added "Tally Prime"', $lines, true) && in_array('Must-haves: removed "Tally"', $lines, true));
$check('with the person who changed it', ($h->history($role)[0]['who'] ?? '') !== 'System');
