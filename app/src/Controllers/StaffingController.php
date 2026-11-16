<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Staffing;

/**
 * Settings › Staffing (15 Nov 2026), owner only: the statutory rates (each confirmed by Benjamin, with his name and the
 * date), Tamil Nadu minimum wages, the contract labour licence and the minimum margin. And a staffing client's terms.
 */
final class StaffingController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $st = new Staffing($this->app);
        $this->view('settings/staffing', ['title' => 'Staffing', 'rates' => $st->rates(), 'headcount' => $st->headcount(), 'about' => $st->aboutToJoin(), 'licence' => $st->licence(),
            'licenceNo' => (string) $this->app->setting('labour_licence_no', ''), 'licenceTo' => (string) $this->app->setting('labour_licence_valid_to', ''),
            'minMargin' => $st->minMarginPct(),
            'das' => $this->db->all('SELECT d.*, u.name AS confirmer FROM min_wage_da d LEFT JOIN users u ON u.id = d.confirmed_by ORDER BY d.work_kind, d.effective_from DESC'),
            // In the order of the classes list (unskilled first), then zone.
            'wages' => $this->db->all("SELECT w.*, u.name AS confirmer FROM min_wages w LEFT JOIN users u ON u.id = w.confirmed_by
                ORDER BY w.work_kind, FIELD(w.skill_level, '" . implode("','", array_keys(Staffing::SKILLS)) . "'), w.zone, w.effective_from DESC")]);
    }

    /** Saves the rates. A changed value needs confirming again; ticked rows are confirmed now, in Benjamin's name. */
    public function saveRates(): never
    {
        $me = Auth::requireOwner();
        $st = new Staffing($this->app);
        $confirm = array_flip(array_map('strval', (array) ($_POST['confirm'] ?? [])));
        $n = 0;
        foreach ($st->rates() as $k => $r) {
            $raw = trim(str_replace([',', '₹', '%'], '', (string) ($_POST['v'][$k] ?? '')));
            if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
                continue;
            }
            $val = round((float) $raw, 4);
            $changed = abs($val - (float) $r['value']) > 0.00001;
            $upd = ['value' => $val];
            if (isset($confirm[$k])) {
                $upd += ['confirmed_by' => $me['id'], 'confirmed_at' => date('Y-m-d H:i:s')];
                $n++;
            } elseif ($changed) {
                $upd += ['confirmed_by' => null, 'confirmed_at' => null];
            } else {
                continue;
            }
            $this->db->run('UPDATE statutory_rates SET ' . implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($upd))) . ' WHERE k = ?', [...array_values($upd), $k]);
            Audit::log('statutory_rate', 'setting', null, ['k' => $k, 'value' => $val, 'confirmed' => isset($confirm[$k])]);
        }
        $m = trim((string) ($_POST['min_margin'] ?? ''));
        if ($m !== '' && is_numeric($m) && (float) $m >= 0 && (float) $m <= 50) {
            $this->db->run("INSERT INTO settings (k, v) VALUES ('staffing_min_margin_pct', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string) (float) $m]);
        }
        Http::flash('ok', 'Saved' . ($n ? ", $n rate" . ($n > 1 ? 's' : '') . ' confirmed.' : '.') . ' A rate you change needs confirming again.');
        Http::redirect('/settings/staffing#rates');
    }

    /** A Tamil Nadu minimum wage Benjamin entered from the notification: confirmed in his name as he adds it. */
    /** A basic rate Benjamin entered from a notification (per month, or per day x 30): confirmed in his name as he adds it. */
    public function addWage(): never
    {
        $me = Auth::requireOwner();
        $kind = (string) ($_POST['work_kind'] ?? '');
        $skill = (string) ($_POST['skill_level'] ?? '');
        $zone = in_array($_POST['zone'] ?? '', array_keys(Staffing::ZONES), true) ? $_POST['zone'] : null;
        $amount = (float) str_replace([',', '₹'], '', (string) ($_POST['amount'] ?? ''));
        $daily = ($_POST['per'] ?? 'month') === 'day';
        $from = (string) ($_POST['effective_from'] ?? '');
        if (!isset(Staffing::WORK_KINDS[$kind]) || !isset(Staffing::SKILLS[$skill]) || !$zone || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            Http::flash('error', 'Choose the scheduled employment, the class and the zone, and enter the basic rate and the date it applies from.');
            Http::redirect('/settings/staffing#wages');
        }
        $this->db->insert('min_wages', ['work_kind' => $kind, 'skill_level' => $skill, 'zone' => $zone, 'monthly' => round($daily ? $amount * 30 : $amount, 2),
            'daily' => $daily ? $amount : null, 'effective_from' => $from, 'note' => $this->str('note', 255), 'confirmed_by' => $me['id'], 'confirmed_at' => date('Y-m-d H:i:s')]);
        Audit::log('min_wage_added', 'setting', null, ['kind' => $kind, 'skill' => $skill, 'zone' => $zone, 'amount' => $amount, 'per' => $daily ? 'day' : 'month', 'from' => $from]);
        Http::flash('ok', 'Basic rate added.');
        Http::redirect('/settings/staffing#wages');
    }

    /** A new dearness allowance (each 1 April), confirmed in Benjamin's name as he adds it. */
    public function addDa(): never
    {
        $me = Auth::requireOwner();
        $kind = (string) ($_POST['work_kind'] ?? '');
        $monthly = (float) str_replace([',', '₹'], '', (string) ($_POST['monthly'] ?? ''));
        $from = (string) ($_POST['effective_from'] ?? '');
        if (!isset(Staffing::WORK_KINDS[$kind]) || $monthly <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            Http::flash('error', 'Enter the DA per month and the date it applies from.');
            Http::redirect('/settings/staffing#wages');
        }
        $pts = is_numeric($p = trim((string) ($_POST['index_points'] ?? ''))) ? (float) $p : null;
        $this->db->insert('min_wage_da', ['work_kind' => $kind, 'monthly' => $monthly, 'effective_from' => $from, 'index_points' => $pts,
            'note' => $this->str('note', 255), 'confirmed_by' => $me['id'], 'confirmed_at' => date('Y-m-d H:i:s')]);
        Audit::log('min_wage_da_added', 'setting', null, ['kind' => $kind, 'monthly' => $monthly, 'from' => $from]);
        Http::flash('ok', 'Dearness allowance added.');
        Http::redirect('/settings/staffing#wages');
    }

    /** Confirms the ticked basic rates and DA rows (loaded from the notification), in Benjamin's name. */
    public function confirmWages(): never
    {
        $me = Auth::requireOwner();
        $n = 0;
        foreach (['min_wages' => 'mw', 'min_wage_da' => 'da'] as $table => $field) {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST[$field] ?? []))));
            if ($ids) {
                $n += count($ids);
                $this->db->run("UPDATE $table SET confirmed_by = ?, confirmed_at = NOW() WHERE confirmed_at IS NULL AND id IN (" . implode(',', $ids) . ')', [$me['id']]);
            }
        }
        Audit::log('min_wages_confirmed', 'setting', null, ['n' => $n]);
        Http::flash('ok', $n ? "$n confirmed." : 'Nothing was ticked.');
        Http::redirect('/settings/staffing#wages');
    }

    public function deleteWage(int $id): never
    {
        Auth::requireOwner();
        $this->db->run('DELETE FROM min_wages WHERE id = ?', [$id]);
        Audit::log('min_wage_deleted', 'setting', null, ['id' => $id]);
        Http::flash('ok', 'Removed.');
        Http::redirect('/settings/staffing#wages');
    }

    public function saveLicence(): never
    {
        Auth::requireOwner();
        $no = $this->str('licence_no', 80) ?? '';
        $to = (string) ($_POST['licence_valid_to'] ?? '');
        foreach (['labour_licence_no' => $no, 'labour_licence_valid_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : ''] as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Audit::log('labour_licence', 'setting', null, ['no' => $no, 'to' => $to]);
        Http::flash('ok', 'Licence saved.');
        Http::redirect('/settings/staffing#licence');
    }

    /** A staffing client's terms: saving adds a new version and keeps the old one. */
    public function saveTerms(int $clientId): never
    {
        $me = Auth::requireOwner();
        $this->findOr404('clients', $clientId);
        $model = isset(Staffing::BILLING[$_POST['billing_model'] ?? '']) ? $_POST['billing_model'] : 'markup';
        $num = fn (string $k, ?float $d = null) => is_numeric($v = trim(str_replace([',', '₹', '%'], '', (string) ($_POST[$k] ?? '')))) ? (float) $v : $d;
        $row = [
            'client_id' => $clientId, 'billing_model' => $model, 'markup_pct' => $model === 'markup' ? $num('markup_pct') : null,
            'markup_base' => ($_POST['markup_base'] ?? '') === 'cost' ? 'cost' : 'gross',
            'wage_rule' => ($_POST['wage_rule'] ?? '') === 'gross' ? 'gross' : 'law',
            'payers' => json_encode(array_map(fn ($k) => isset(Staffing::PAYERS[$_POST['payer'][$k] ?? '']) ? $_POST['payer'][$k] : Staffing::PAYER_DEFAULTS[$k], array_combine(array_keys(Staffing::COSTS), array_keys(Staffing::COSTS)))),
            'reimburse_days' => ($v = $num('reimburse_days')) !== null ? max(0, min(90, (int) $v)) : null,
            'fixed_fee' => $model === 'fixed_fee' ? $num('fixed_fee') : null, 'gst_pct' => $num('gst_pct', 18.0),
            'invoice_timing' => ($_POST['invoice_timing'] ?? '') === 'advance' ? 'advance' : 'month_end',
            'payment_due_days' => max(0, min(90, (int) $num('payment_due_days', 5.0))), 'deposit_months' => max(0, min(6, (float) $num('deposit_months', 0.0))),
            'replacement_days' => ($v = $num('replacement_days')) !== null ? (int) $v : null, 'notice_days' => ($v = $num('notice_days')) !== null ? (int) $v : null,
            'conv_pct_6m' => $num('conv_pct_6m', 8.33), 'conv_pct_12m' => $num('conv_pct_12m', 4.0), 'conv_pct_after' => $num('conv_pct_after', 0.0),
            'rate_revision' => $this->str('rate_revision', 255), 'note' => $this->str('note', 500), 'created_by' => $me['id'],
        ];
        if ($model === 'markup' && $row['markup_pct'] === null || $model === 'fixed_fee' && $row['fixed_fee'] === null) {
            Http::flash('error', $model === 'markup' ? 'Enter the fee percentage.' : 'Enter the fixed fee per person per month.');
            Http::redirect("/clients/$clientId#staffing-terms");
        }
        $this->db->tx(function () use ($clientId, $row) {
            $this->db->run('UPDATE client_terms SET is_current = 0 WHERE client_id = ?', [$clientId]);
            $this->db->insert('client_terms', $row);
        });
        Audit::log('client_terms', 'client', $clientId, ['model' => $model]);
        $safe = Staffing::safeguard($row);
        Http::flash($safe ? 'ok' : 'error', $safe ? "Terms saved. Wages by the 7th: $safe." : 'Terms saved, but they don\'t protect wages by the 7th. Staffing offers for this client can\'t be approved until they do.');
        Http::redirect("/clients/$clientId#staffing-terms");
    }
}
