<?php
/**
 * Staffing, phase 2 (15 Nov 2026): the monthly cost and margin worked out by hand and by the app must agree to the
 * rupee; the client's terms must protect wages by the 7th; and a staffing offer can't be approved while a rate is
 * unconfirmed, the minimum wage is missing or not met, the margin is below 8 % without a reason, or the licence is
 * needed. Throwaway data, removed afterwards; statutory rates restored. LOCAL ONLY. Run: php tests/staffing-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Staffing as S;

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
$near = fn (float $a, float $b) => abs($a - $b) < 0.011;

// The suggested rates, as update 049 enters them.
$v = ['wage_share_pct' => 50, 'pf_employer_pct' => 12, 'pf_admin_pct' => 1, 'pf_ceiling' => 15000, 'esi_employer_pct' => 3.25, 'esi_ceiling' => 21000,
    'bonus_pct' => 8.33, 'bonus_eligibility' => 21000, 'bonus_calc_ceiling' => 7000, 'gratuity_pct' => 4.81, 'leave_pct' => 0, 'lwf_employer_year' => 0,
    'insurance_month' => 0, 'overhead_pct' => 0, 'gst_pct' => 18];

echo "The monthly sums, by hand\n";
// ₹18,000 gross: wages 9,000; PF 13% of 9,000 = 1,170; ESI 3.25% of 18,000 = 585; bonus 8.33% of max(7,000, 12,500 min wage) capped at
// wages 9,000 = 749.70; gratuity 4.81% of 9,000 = 432.90. Cost 20,937.60. A 10% fee: bill 23,031.36, margin 2,093.76 (9.09%), GST 4,145.64.
$c = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'gst_pct' => 18, 'wage_rule' => 'gross'], null, $v, 12500);
$check('PF on half the pay, with admin charges', $near($c['parts']['pf'], 1170.00));
$check('ESI at 3.25 % (pay under ₹21,000)', $near($c['parts']['esi'], 585.00));
$check('bonus on the minimum wage, capped at the wages', $near($c['parts']['bonus'], 749.70));
$check('gratuity', $near($c['parts']['gratuity'], 432.90));
$check('cost ₹20,937.60', $near($c['cost'], 20937.60));
$check('bill with a 10 % fee: ₹23,031.36', $near($c['bill'], 23031.36));
$check('margin ₹2,093.76 = 9.09 % of the bill', $near($c['margin'], 2093.76) && $near($c['margin_pct'], 9.09));
$check('GST 18 % on the gross bill: ₹4,145.64; invoice ₹27,177.00', $near($c['gst'], 4145.64) && $near($c['invoice'], 27177.00));
$c2 = S::calc(30000, ['billing_model' => 'fixed_fee', 'fixed_fee' => 2500, 'gst_pct' => 18, 'wage_rule' => 'gross'], null, $v, 12500);
// ₹30,000 gross: wages 15,000, so still bonus-eligible (eligibility goes by wages, not gross): 8.33 % of the 12,500 minimum wage.
$check('above ₹21,000: no ESI; PF stops at the ₹15,000 ceiling; bonus still due on wages of ₹15,000', $c2['parts']['esi'] == 0 && $near($c2['parts']['pf'], 1950.00) && $near($c2['parts']['bonus'], 1041.25));
$c4 = S::calc(50000, ['billing_model' => 'markup', 'markup_pct' => 10, 'wage_rule' => 'gross'], null, $v, 12500);
$check('wages above ₹21,000 (₹50,000 gross): no bonus', $c4['parts']['bonus'] == 0);
$check('a fixed fee adds to the cost', $near($c2['bill'] - $c2['cost'], 2500));
$c3 = S::calc(18000, ['billing_model' => 'rate', 'gst_pct' => 18, 'wage_rule' => 'gross'], 22000, $v, 12500);
$check('a monthly bill rate: margin is the rate less the cost', $near($c3['bill'], 22000) && $near($c3['margin'], 22000 - 20937.60));

echo "\nHINE: the client's own basic share and a bonus paid within the gross (24 Nov 2026)\n";
// ₹21,500 gross: basic 70 % = 15,050 (HINE only; everyone else stays on the general 50 %), HRA 27 %, bonus 3 % paid monthly
// inside the gross. 8 % on the total cost; PF ceiling ₹25,000; LWF ₹40 a year; Skilled Zone B minimum wage ₹15,955.
// PF 13 % of 15,050 = 1,956.50 (employer 1,806 + admin/EDLI 150.50); gratuity 4.81 % = 723.91; LWF 3.33; no ESI (over ₹21,000);
// no bonus on top. Cost 24,183.74; fee 8 % = 1,934.70; bill 26,118.44.
$hine = ['billing_model' => 'markup', 'markup_pct' => 8, 'markup_base' => 'cost', 'wage_rule' => 'gross', 'wage_share_pct' => '70.00', 'bonus_in_gross' => 1, 'gst_pct' => 18,
    'payers' => json_encode(['pf' => 'proof', 'esi' => 'proof', 'insurance' => 'bill', 'bonus' => 'bill', 'leave' => 'bill', 'lwf' => 'bill', 'gratuity' => 'bill'])];
$vH = ['pf_ceiling' => 25000, 'lwf_employer_year' => 40] + $v;
$h = S::calc(21500, $hine, null, $vH, 15955);
$check('basic is HINE\'s own 70 %: ₹15,050', $near($h['wage'], 15050) && $h['wage_share_pct'] == 70.0);
$check('PF and charges 13 % of 15,050 = ₹1,956.50; gratuity ₹723.91; no ESI', $near($h['parts']['pf'], 1956.50) && $near($h['parts']['gratuity'], 723.91) && $h['parts']['esi'] == 0);
$check('the bonus is inside the gross: nothing added on top', $h['parts']['bonus'] == 0 && $h['bonus_in_gross'] === true);
$check('8 % on the total cost: cost ₹24,183.74, fee ₹1,934.70, bill ₹26,118.44', $near($h['cost'], 24183.74) && $near($h['fee'], 1934.70) && $near($h['bill'], 26118.44));
$other = S::calc(21500, ['wage_share_pct' => null, 'bonus_in_gross' => 0] + $hine, null, $vH, 15955);
$check('a client with no share of its own: the general 50 % (₹10,750), and the bonus added', $near($other['wage'], 10750) && $other['parts']['bonus'] > 0);
$check('terms saved before update 078 (no such fields): as before', $near(S::calc(21500, ['markup_base' => 'cost', 'wage_rule' => 'gross'] + array_diff_key($hine, ['wage_share_pct' => 1, 'bonus_in_gross' => 1]), null, $vH, 15955)['wage'], 10750));

echo "\nThe wages rule (16 Nov 2026)\n";
// ₹18,000 gross, Tamil Nadu minimum wage ₹15,400 (Unskilled Zone B: 7,650 + 7,750), PF ceiling ₹25,000.
$v25 = ['pf_ceiling' => 25000] + $v;
$law = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'markup_base' => 'gross', 'payers' => []], null, $v25, 15400);
$check('the law: wages are the minimum wage ₹15,400, not half the gross', $near($law['wage'], 15400) && $law['wage_rule'] === 'law');
$check('the law: PF 13 % of 15,400 = 2,002; bonus 8.33 % of 15,400 = 1,282.82; gratuity 4.81 % = 740.74',
    $near($law['parts']['pf'], 2002) && $near($law['parts']['bonus'], 1282.82) && $near($law['parts']['gratuity'], 740.74));
$check('the law: no extra-cost warning', $law['law_extra'] == 0);
$prac = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'markup_base' => 'gross', 'wage_rule' => 'gross', 'payers' => []], null, $v25, 15400);
$check('common practice: wages ₹9,000 (half the gross)', $near($prac['wage'], 9000));
$check('common practice: the extra cost if the law is applied (2,002 + 1,282.82 + 740.74 − 1,170 − 749.70 − 432.90 = 1,672.96)', $near($prac['law_extra'], 1672.96));
$hi = S::calc(40000, ['billing_model' => 'markup', 'markup_pct' => 10, 'wage_rule' => 'law', 'payers' => []], null, $v25, 15400);
$check('the law: half the gross above the minimum wage stays half the gross (₹20,000)', $near($hi['wage'], 20000));
$lo = S::calc(15400, ['billing_model' => 'markup', 'markup_pct' => 10, 'payers' => []], null, $v25, 15400);
$check('wages never above the gross', $near($lo['wage'], 15400));

echo "\nWho pays, and the fee base\n";
// Defaults: PF and ESI reimbursed against proof; the rest in the monthly bill. Fee 10 % of gross = 1,800.
$p = $law['parts'];
$check('fee 10 % on gross pay only = ₹1,800', $near($law['fee'], 1800));
$check('PF and ESI are reimbursed against proof, monthly', $near($law['reimbursed'], $p['pf'] + $p['esi']) && $near($law['reimbursed_monthly'], $law['reimbursed']));
$check('the monthly bill = gross + bonus + gratuity (in the bill) + fee', $near($law['monthly_bill'], 18000 + $p['bonus'] + $p['gratuity'] + 1800));
$check('the whole bill = monthly bill + reimbursements; margin = the fee', $near($law['bill'], $law['monthly_bill'] + $law['reimbursed']) && $near($law['margin'], 1800));
$check('GST on all of it, reimbursements included', $near($law['gst'], $law['bill'] * 0.18));
$bear = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'markup_base' => 'gross', 'payers' => ['bonus' => 'eshcol', 'gratuity' => 'proof']], null, $v25, 15400);
$check('Eshcol bears the bonus: the margin is the fee less the bonus', $near($bear['margin'], 1800 - $p['bonus']) && $near($bear['eshcol_bears'], $p['bonus']));
$check('gratuity on proof: reimbursed later, not monthly', $near($bear['reimbursed'] - $bear['reimbursed_monthly'], $p['gratuity']) && $bear['groups']['exit']['proof'] > 0);
$onCost = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'markup_base' => 'cost', 'payers' => []], null, $v25, 15400);
$check('fee % on the whole cost', $near($onCost['fee'], $onCost['cost'] * 0.10) && $near($onCost['margin'], $onCost['fee']));
$old = S::calc(18000, ['billing_model' => 'markup', 'markup_pct' => 10, 'wage_rule' => 'gross'], null, $v, 12500);
$check('terms saved before 16 Nov: fee on the whole cost, everything in the monthly bill (as before)', $near($old['bill'], 23031.36) && $old['reimbursed'] == 0);

echo "\nThe client's terms and wages by the 7th\n";
$check('month-end invoice due in 5 days: safe', S::safeguard(['invoice_timing' => 'month_end', 'payment_due_days' => 5, 'deposit_months' => 0]) !== null);
$check('due in 30 days with no deposit: not safe', S::safeguard(['invoice_timing' => 'month_end', 'payment_due_days' => 30, 'deposit_months' => 0]) === null);
$check('due in 30 days with a month\'s deposit: safe', S::safeguard(['invoice_timing' => 'month_end', 'payment_due_days' => 30, 'deposit_months' => 1]) !== null);
$check('billed in advance: safe', S::safeguard(['invoice_timing' => 'advance', 'payment_due_days' => 30, 'deposit_months' => 0]) !== null);

echo "\nApproving a staffing offer\n";
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$ratesWas = $db->all('SELECT k, value, confirmed_by, confirmed_at FROM statutory_rates');
$client = $db->insert('clients', ['name' => "Zz Staffing Client $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'engagement' => 'staffing', 'title' => "Machine Operator ZZ$tag", 'location' => 'Tiruvallur',
    'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0, 'contract_months' => 12]);
$made = [];
$mw = null;
register_shutdown_function(function () use ($app, $db, &$made, $client, $role, $routingWas, $ratesWas, &$mw, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE id = ?', [$role]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    // Test rows use their own scheduled employment ("zz_test"), so the rates loaded from the notification are never touched.
    $db->run("DELETE FROM min_wages WHERE work_kind = 'zz_test'");
    $db->run("DELETE FROM min_wage_da WHERE work_kind = 'zz_test'");
    foreach ($ratesWas as $r) {
        $db->run('UPDATE statutory_rates SET value = ?, confirmed_by = ?, confirmed_at = ? WHERE k = ?', [$r['value'], $r['confirmed_by'], $r['confirmed_at'], $r['k']]);
    }
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$tmp = $app->storage("uploads-tmp/zz-staff-$tag.txt");
file_put_contents($tmp, "Murugan Zzstaff\nmurugan.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nTiruvallur\nMachine Operator, 2021 – Present\nCNC lathe.\nITI");
$r = (new CandidateImporter($app))->import($tmp, 'm.txt', $role, $owner);
@unlink($tmp);
$made[] = (int) $r['candidate_id'];
$appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [(int) $r['candidate_id'], $role]);
$st = new S($app);
$has = fn (array $p, string $s) => (bool) array_filter($p, fn ($x) => str_contains($x, $s));

$db->run('UPDATE statutory_rates SET confirmed_by = NULL, confirmed_at = NULL');
[$p] = $st->check($appId, 18000.0, null);
$check('unconfirmed rates stop it', $has($p, 'not confirmed'));
$check('no client terms stop it', $has($p, 'no staffing terms'));
$check('no scheduled employment, class or zone stops it', $has($p, 'kind of work'));
$db->run('UPDATE statutory_rates SET confirmed_by = ?, confirmed_at = NOW()', [$owner]);
$db->insert('client_terms', ['client_id' => $client, 'billing_model' => 'markup', 'markup_pct' => 10, 'markup_base' => 'cost', 'wage_rule' => 'gross', 'payment_due_days' => 30, 'created_by' => $owner]);
$db->update('roles', ['work_kind' => 'zz_test', 'skill_level' => 'unskilled', 'wage_zone' => 'A'], $role);
$daId = $db->insert('min_wage_da', ['work_kind' => 'zz_test', 'monthly' => 5000, 'effective_from' => date('Y-m-d', strtotime('-30 days')), 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
[$p] = $st->check($appId, 18000.0, null);
$check('terms that don\'t protect wages by the 7th stop it', $has($p, 'by the 7th'));
$check('no confirmed minimum wage stops it', $has($p, 'No confirmed Tamil Nadu minimum wage'));
$db->run('UPDATE client_terms SET payment_due_days = 5 WHERE client_id = ?', [$client]);
// Basic ₹14,000 + DA ₹5,000 = ₹19,000: ₹18,000 is below it.
$mw = $db->insert('min_wages', ['work_kind' => 'zz_test', 'skill_level' => 'unskilled', 'zone' => 'A', 'monthly' => 14000, 'effective_from' => date('Y-m-d', strtotime('-10 days')), 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
[$p] = $st->check($appId, 18000.0, null);
$check('pay below the minimum wage (basic + DA) stops it, and says so', $has($p, 'below the Tamil Nadu minimum wage of ₹19,000 a month (basic ₹14,000 + DA ₹5,000)'));
$db->update('min_wage_da', ['confirmed_at' => null], $daId);
$check('a DA that isn\'t confirmed means no minimum wage yet', $st->minWage('zz_test', 'unskilled', 'A') === null);
$db->update('min_wage_da', ['confirmed_at' => date('Y-m-d H:i:s')], $daId);
$db->update('min_wages', ['monthly' => 7500], $mw); // basic 7,500 + DA 5,000 = 12,500
[$p, $calc] = $st->check($appId, 18000.0, null);
$check('everything in order: the 9.09 % margin passes the 8 % minimum', $p === [] && $near($calc['margin_pct'], 9.09));
$db->run('UPDATE client_terms SET markup_pct = 5 WHERE client_id = ?', [$client]);
[$p] = $st->check($appId, 18000.0, null);
$check('a 5 % fee is below the 8 % minimum: needs a reason', $has($p, 'below your minimum'));
[$p] = $st->check($appId, 18000.0, 'Volume client, first month');
$check('with your reason it can go ahead', $p === []);
$check('the minimum is 8 % (your choice)', $st->minMarginPct() == 8.0);

echo "\nJoining (own team and staffing)\n";
$joined = (int) $db->col("SELECT id FROM stages WHERE name = 'Joined'");
$check('"Joined" waits for every joining document', \Eshcol\Ats\Controllers\DecisionController::joinBlocked($app, $appId, $joined) !== null);
foreach (array_keys(S::JOINING) as $item) {
    $db->insert('joining_items', ['application_id' => $appId, 'item' => $item, 'received_on' => date('Y-m-d'), 'user_id' => $owner]);
}
$check('with all eight received, they can join', \Eshcol\Ats\Controllers\DecisionController::joinBlocked($app, $appId, $joined) === null);
$cidS = (int) $db->col('SELECT candidate_id FROM applications WHERE id = ?', [$appId]);
$cons = new \Eshcol\Ats\Services\Consents($app);
$before = $cons->current($cidS);
$db->insert('consents', ['candidate_id' => $cidS, 'purpose' => 'employment', 'status' => 'given', 'via' => 'joining_checklist']);
$check('the employment consent is kept apart: "keep my details" standing is unchanged', $cons->current($cidS) == $before);
$check('and it is in their data export', (bool) array_filter((array) ($cons->export($cidS)['consents'] ?? []), fn ($c) => ($c['purpose'] ?? '') === 'employment'));
$db->update('roles', ['engagement' => 'placement'], $role);
$check('client recruitment never needs the checklist', \Eshcol\Ats\Controllers\DecisionController::joinBlocked($app, $appId, $joined) === null);

echo "\nThe position's own fee, zones, the floor wage, about to join (15 Nov 2026)\n";
$db->update('roles', ['engagement' => 'staffing'], $role);
$db->run('UPDATE client_terms SET markup_pct = 10 WHERE client_id = ?', [$client]);
[, $cClient, $tClient] = $st->check($appId, 18000.0, null);
$db->update('roles', ['terms_model' => 'markup', 'terms_markup_pct' => 15], $role);
[$p, $cOwn, $tOwn] = $st->check($appId, 18000.0, null);
$check('the position\'s own 15 % fee is used instead of the client\'s 10 %', $p === [] && !empty($tOwn['overridden']) && $near($cOwn['bill'], $cOwn['cost'] * 1.15) && $cOwn['bill'] > $cClient['bill']);
$check('the rest of the client\'s terms still apply (wages by the 7th)', (int) $tOwn['payment_due_days'] === 5);
$db->update('roles', ['terms_model' => 'fixed_fee', 'terms_markup_pct' => null, 'terms_fixed_fee' => null], $role);
[$p] = $st->check($appId, 18000.0, null);
$check('an own fee chosen but left empty stops it', $has($p, 'own fee is chosen but not filled in'));
$db->update('roles', ['terms_model' => null, 'terms_fixed_fee' => null], $role);

$mwZone = $db->insert('min_wages', ['work_kind' => 'zz_test', 'skill_level' => 'unskilled', 'zone' => 'B', 'monthly' => 9000, 'effective_from' => date('Y-m-d', strtotime('-5 days')), 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
$check('Zone B uses Zone B\'s basic rate (9,000 + DA 5,000)', (float) $st->minWage('zz_test', 'unskilled', 'B')['monthly'] === 14000.0);
$check('Zone A keeps its own (7,500 + DA 5,000)', (float) $st->minWage('zz_test', 'unskilled', 'A')['monthly'] === 12500.0);
$db->update('roles', ['wage_zone' => 'B'], $role);
[$p] = $st->check($appId, 13000.0, 'test');
$check('₹13,000 is fine in Zone A but below Zone B\'s ₹14,000', $has($p, 'below the Tamil Nadu minimum wage of ₹14,000'));
$db->update('roles', ['wage_zone' => null], $role);
[$p] = $st->check($appId, 18000.0, 'test');
$check('a staffing position must say which zone its site is in', $has($p, 'Zone A or B'));
$db->update('roles', ['wage_zone' => 'A'], $role);
$db->run('DELETE FROM min_wages WHERE id = ?', [$mwZone]);

echo "\nBenjamin's own working, January 2026 (unskilled, Zone B)\n";
$db->insert('min_wages', ['work_kind' => 'zz_test', 'skill_level' => 'unskilled', 'zone' => 'B', 'monthly' => 7650, 'daily' => 255, 'effective_from' => '2018-01-03', 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
$db->run("DELETE FROM min_wage_da WHERE work_kind = 'zz_test'");
$db->insert('min_wage_da', ['work_kind' => 'zz_test', 'monthly' => 7708, 'effective_from' => '2025-04-01', 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
$db->insert('min_wage_da', ['work_kind' => 'zz_test', 'monthly' => 7750, 'effective_from' => '2026-04-01', 'confirmed_by' => $owner, 'confirmed_at' => date('Y-m-d H:i:s')]);
$jan = $st->minWage('zz_test', 'unskilled', 'B', '2026-01-28');
$check('on 28 Jan 2026: 255 × 30 = 7,650 + DA 7,708 = ₹15,358 (as in his email)', $jan && (float) $jan['basic'] === 7650.0 && (float) $jan['da'] === 7708.0 && (float) $jan['monthly'] === 15358.0);
$now = $st->minWage('zz_test', 'unskilled', 'B', '2026-11-15');
$check('from 1 Apr 2026 the new DA applies by itself: 7,650 + 7,750 = ₹15,400', $now && (float) $now['monthly'] === 15400.0);
$check('the notification\'s rates were loaded (28 basic rates, General Engineering)', (int) $db->col("SELECT COUNT(*) FROM min_wages WHERE work_kind = 'gen_engineering'") >= 28
    && (float) $db->col("SELECT monthly FROM min_wages WHERE work_kind = 'gen_engineering' AND skill_level = 'unskilled' AND zone = 'B' ORDER BY id LIMIT 1") === 7650.0);
$check('and the 2026 DA of ₹7,750', (float) $db->col("SELECT monthly FROM min_wage_da WHERE work_kind = 'gen_engineering' AND effective_from = '2026-04-01'") === 7750.0);

$db->run("UPDATE statutory_rates SET value = 13500 WHERE k = 'floor_wage_month'");
[$p] = $st->check($appId, 13000.0, 'test');
$check('the national floor wage is checked too', $has($p, 'below the national floor wage of ₹13,500'));
$db->run("UPDATE statutory_rates SET value = 0 WHERE k = 'floor_wage_month'");
$check('the floor wage is a rate you confirm like the others', (bool) $db->col("SELECT 1 FROM statutory_rates WHERE k = 'floor_wage_month'"));

$before = $st->aboutToJoin();
$db->run("INSERT INTO offer_approvals (application_id, designation, gross_month, joining_date, approved_by, approved_at) VALUES (?, 'Operator', 18000, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE approved_at = NOW()", [$appId, date('Y-m-d', strtotime('+10 days')), $owner]);
$db->update('applications', ['stage_id' => (int) $db->col("SELECT id FROM stages WHERE name = 'Offer made'")], $appId);
$check('an approved offer not yet joined counts as about to join', $st->aboutToJoin() === $before + 1);
$check('but not when checking that same offer (it is counted once)', $st->aboutToJoin($appId) === $before);
