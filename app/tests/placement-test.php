<?php
/**
 * Placements (B9, 14 Nov 2026): offer → joined, fee on the TOTAL annual CTC from the client's terms, your own
 * figure, invoice tracking (numbers from Zoho Books), reminders, check-ins, and a replacement within the guarantee
 * reopening the same position with no new fee. Throwaway data. LOCAL ONLY. Run: php tests/placement-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Access;
use Eshcol\Ats\App;
use Eshcol\Ats\Controllers\PlacementController;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Placements;
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
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$was = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "ZZ Placement $tag", 'fee_type' => 'percent', 'fee_value' => 8.33, 'payment_terms_days' => 30, 'replacement_days' => 90]);
$fixedClient = $db->insert('clients', ['name' => "ZZ Fixed $tag", 'fee_type' => 'fixed', 'fee_value' => 40000, 'payment_terms_days' => 15, 'replacement_days' => 60]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Officer ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$role2 = $db->insert('roles', ['client_id' => $fixedClient, 'title' => "Payroll Executive ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $fixedClient, $was, &$ok, &$bad) {
    $db->run('DELETE p FROM placements p JOIN roles r ON r.id = p.role_id WHERE r.client_id IN (?, ?)', [$client, $fixedClient]);
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id IN (?, ?)', [$client, $fixedClient]);
    $db->run('DELETE FROM clients WHERE id IN (?, ?)', [$client, $fixedClient]);
    $was === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$was]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-place-' . getmypid() . '.txt');
$person = function (string $name, int $roleId) use ($db, $imp, $tmp, $uid, $tag, &$made): array {
    file_put_contents($tmp, "$name\n" . strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999)
        . "\nPune\nAccountant, 2021 – Present\n4 years of experience, Tally, GST, bank reconciliation and month-end closing.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    @unlink($tmp);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$r['candidate_id'], $roleId])];
};
$svc = new Placements($app);
$today = '2026-11-16';
$svc->today = function () use (&$today) { return $today; };
$offer = $svc->offerStageId();
$joined = (int) $db->col("SELECT id FROM stages WHERE kind = 'success' ORDER BY sort LIMIT 1");
$notSuitable = (int) $db->col("SELECT id FROM stages WHERE kind = 'fail' ORDER BY sort LIMIT 1");
$P = fn (int $id) => $db->one('SELECT * FROM placements WHERE id = ?', [$id]);
$move = function (int $app, int $stage) use ($db, $svc) {
    $db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$stage, $app]);
    return $svc->afterStage($app, $stage);
};

echo "The fee\n";
$check('8.33% of a total CTC of 6 LPA = ₹49,980', Placements::fee('percent', '8.33', '6.00', false) === 49980.0);
$check('a fixed fee is the fixed amount; a replacement is ₹0; no CTC yet = not worked out', Placements::fee('fixed', '40000', null, false) === 40000.0
    && Placements::fee('percent', '8.33', '6', true) === 0.0 && Placements::fee('percent', '8.33', null, false) === null);

echo "\nOffer made, then Joined\n";
[$asha, $app1] = $person('Asha Placed', $role);
$db->run('UPDATE candidates SET ctc_expected_lpa = 5.5 WHERE id = ?', [$asha]);
$pid = (int) $move($app1, (int) $offer);
$check('"Offer made" opens a placement, with their expected CTC as a starting point', $pid > 0 && $P($pid)['status'] === 'offer' && (float) $P($pid)['offer_ctc_lpa'] === 5.5);
$svc->save($pid, ['offer_date' => '2026-11-16', 'offer_ctc_lpa' => '6', 'expected_join' => '2026-11-18', 'fee_auto' => '']);
$check('saving the offer works out the fee from the client\'s terms', (float) $P($pid)['fee_amount'] === 49980.0 && !$P($pid)['fee_overridden']);
$r = $svc->reminders();
$check('2 days before joining: My day says to call them', (bool) array_filter($r, fn ($x) => str_contains($x[1], 'Joining in 2 days') && str_contains($x[1], 'Asha Placed')));
$today = '2026-11-18';
$move($app1, $joined);
$p = $P($pid);
$check('Joined: joining date (the expected date, now reached), guarantee to joining + 90 days', $p['status'] === 'joined' && $p['joined_on'] === '2026-11-18' && $p['guarantee_until'] === '2027-02-16');
$check('money: to invoice', $svc->moneyStatus($p) === 'to_invoice');

echo "\nInvoices (raised in Zoho Books)\n";
$today = '2026-11-22';
$check('a few days after joining with no invoice: a reminder', (bool) array_filter($svc->reminders(), fn ($x) => str_contains($x[1], 'Invoice not raised') && str_contains($x[1], 'Asha Placed')));
$svc->save($pid, ['offer_date' => '2026-11-16', 'offer_ctc_lpa' => '6', 'expected_join' => '2026-11-18', 'joined_on' => '2026-11-18',
    'fee_amount' => '49980', 'fee_auto' => '49980', 'invoice_no' => "EG/26-27/0$tag", 'invoiced_on' => '2026-11-22']);
$p = $P($pid);
$check('invoice number and date recorded; payment due after the client\'s 30 days', $svc->moneyStatus($p) === 'invoiced' && $svc->dueOn($p) === '2026-12-22' && !$p['fee_overridden']);
$today = '2026-12-23';
$check('a day past due: overdue, on My day', $svc->moneyStatus($P($pid)) === 'overdue'
    && (bool) array_filter($svc->reminders(), fn ($x) => $x[0] === 'error' && str_contains($x[1], 'Payment overdue')));
$check('30-day check-in reminder ("still there?")', (bool) array_filter($svc->reminders(), fn ($x) => str_contains($x[1], '30-day check-in')));
$svc->checkin($pid, 30);
$check('after recording it, that reminder goes', !array_filter($svc->reminders(), fn ($x) => str_contains($x[1], '30-day check-in') && str_contains($x[1], 'Asha Placed')));
$svc->save($pid, ['offer_ctc_lpa' => '6', 'joined_on' => '2026-11-18', 'fee_amount' => '49980', 'fee_auto' => '49980', 'invoice_no' => "EG/26-27/0$tag", 'invoiced_on' => '2026-11-22', 'paid_on' => '2026-12-23']);
$check('paid', $svc->moneyStatus($P($pid)) === 'paid');

echo "\nYour own figure\n";
$svc->save($pid, ['offer_ctc_lpa' => '6', 'joined_on' => '2026-11-18', 'fee_amount' => '45000', 'fee_auto' => '49980', 'paid_on' => '2026-12-23']);
$check('a different fee is kept as yours', (float) $P($pid)['fee_amount'] === 45000.0 && (int) $P($pid)['fee_overridden'] === 1);
$svc->save($pid, ['offer_ctc_lpa' => '7', 'joined_on' => '2026-11-18', 'fee_amount' => '45000', 'fee_auto' => '58310', 'paid_on' => '2026-12-23']);
$check('it stays yours when the CTC changes', (float) $P($pid)['fee_amount'] === 45000.0);
$svc->save($pid, ['offer_ctc_lpa' => '6', 'joined_on' => '2026-11-18', 'fee_amount' => '', 'fee_auto' => '49980', 'paid_on' => '2026-12-23']);
$check('cleared: back to the client\'s terms', (float) $P($pid)['fee_amount'] === 49980.0 && !(int) $P($pid)['fee_overridden']);

echo "\nLeft within the guarantee\n";
$today = '2027-01-10';
$svc->left($pid, '2027-01-10', 'Resigned for a closer job');
$r = $db->one('SELECT status, replacement_for FROM roles WHERE id = ?', [$role]);
$check('recorded; the same position reopens as a replacement', $P($pid)['status'] === 'left' && $r['status'] === 'open' && (int) $r['replacement_for'] === $pid);
[$bala, $app2] = $person('Bala Replacement', $role);
$pid2 = (int) $move($app2, (int) $offer);
$svc->save($pid2, ['offer_ctc_lpa' => '6.2', 'expected_join' => '2027-01-20', 'fee_auto' => '']);
$move($app2, $joined);
$p2 = $P($pid2);
$check('the next joiner is the replacement: no new fee, nothing to invoice', (int) $p2['replacement_of'] === $pid && (float) $p2['fee_amount'] === 0.0 && $svc->moneyStatus($p2) === 'none');
[$chitra, $app3] = $person('Chitra Second', $role);
$pid3 = (int) $move($app3, $joined);
$check('a second joiner on that position is a normal placement again', !$P($pid3)['replacement_of']);

echo "\nOther cases\n";
[$deepak, $app4] = $person('Deepak Fixedfee', $role2);
$pid4 = (int) $move($app4, $joined);
$check('a fixed-fee client: ₹40,000, guarantee 60 days', (float) $P($pid4)['fee_amount'] === 40000.0 && $P($pid4)['guarantee_until'] === date('Y-m-d', strtotime($today . ' +60 days')));
$svc->left($pid4, date('Y-m-d', strtotime($today . ' +90 days')), 'Left after the guarantee');
$check('leaving after the guarantee does not reopen the position', !$db->col('SELECT replacement_for FROM roles WHERE id = ?', [$role2]));
[$esha, $app5] = $person('Esha Declined', $role2);
$pid5 = (int) $move($app5, (int) $offer);
$move($app5, $notSuitable);
$check('an offer that falls through is marked so (no fee, no invoice)', $P($pid5)['status'] === 'withdrew' && $svc->moneyStatus($P($pid5)) === '');
$t = $svc->totals($svc->all(), '2026-11-01', '2026-11-30');
$check('totals for November count Asha: fee, invoiced and received', $t['placed'] >= 1 && $t['received'] >= 49980.0);
(new Retention($app))->delete($asha, 'manual', false);
$made = array_diff($made, [$asha]);
$kept = $P($pid);
$check('if the candidate is deleted, the placement (money record) stays, with the name only', $kept && $kept['candidate_id'] === null && $kept['candidate_name'] === 'Asha Placed');

echo "\nWho sees it\n";
$k = ['id' => 0, 'role' => 'contractor'];
$check('contractors cannot open placements', !Access::allowsRoute($k, PlacementController::class, 'index', []) && !Access::allowsRoute($k, PlacementController::class, 'save', [$pid]));
