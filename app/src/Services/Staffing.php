<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Staffing (own-hiring and staffing plan v2, phase 2, 15 Nov 2026): Eshcol employs and deputes people to clients in
 * Tiruvallur. This works out what a person costs Eshcol each month (the loaded cost), what the client is billed, the
 * margin and GST, and checks everything a staffing offer must meet before Benjamin can approve it:
 *   - every statutory rate confirmed by him (they are kept with his name and the date);
 *   - the Tamil Nadu minimum wage for the kind of work and skill level, entered and met;
 *   - the client's terms protect wages being paid by the 7th (short payment terms, advance billing or a deposit);
 *   - the margin at least the minimum (8 % of the bill), or his reason for going below;
 *   - the contract labour licence once 50 people are deputed (a warning from 40).
 * Payroll itself is for the HRMS; Recruit stops at the approved offer and the joining checklist.
 */
final class Staffing
{
    /** Tamil Nadu scheduled employments we staff in (15 Nov 2026: General Engineering and Fabrication first; add more as needed). */
    public const WORK_KINDS = ['gen_engineering' => 'General Engineering and Fabrication Industry'];
    /** Classes of employee, as in the schedule of G.O.(2D) No. 67, 13 Nov 2017. */
    public const SKILLS = [
        'unskilled' => 'Unskilled (work needing no training)',
        'semi_2' => 'Semi-skilled Grade II (helper, 2+ years in a trade)',
        'semi_1' => 'Semi-skilled Grade I (trade certificate / ITI)',
        'skilled' => 'Skilled (5 years in a trade, or ITI + 3 years)',
        'watchman' => 'Watchman, Peon',
        'attender' => 'Attender, Security Guard',
        'clerk' => 'Clerk, Time-keeper',
        'cashier' => 'Cashier, Store Keeper, Typist',
        'accountant' => 'Accountant',
        'asst_manager' => 'Assistant Manager',
        'manager' => 'Manager',
        'tool_keeper' => 'Workshop tool keeper',
        'supervisor' => 'Supervisor, Chargeman',
        'foreman' => 'Foreman',
    ];
    public const ZONES = ['A' => 'Zone A: district headquarters, corporations and municipalities', 'B' => 'Zone B: other places'];
    public const BILLING = ['markup' => 'A fee %', 'fixed_fee' => 'A fixed fee per person per month', 'rate' => 'A monthly bill rate per person'];
    /** What a fee % is worked out on (16 Nov 2026: chosen per client). */
    public const MARKUP_BASE = ['gross' => 'Gross pay only', 'cost' => 'Gross pay plus all employer costs'];
    /** What PF, bonus and gratuity are worked out on (16 Nov 2026: the law by default, a client's terms can switch). */
    public const WAGE_RULES = [
        'law' => 'The law: wages (basic + DA) at least the minimum wage',
        'gross' => 'Common practice: wages are the share of gross; only the gross must meet the minimum wage',
    ];
    /** The employer costs, grouped by when Eshcol pays them. Overhead is Eshcol's own and is never billed as a line. */
    public const COSTS = [
        'pf' => ['Employer PF and charges', 'monthly'], 'esi' => ['Employer ESI', 'monthly'], 'insurance' => ['Insurance', 'monthly'],
        'bonus' => ['Bonus', 'yearly'], 'leave' => ['Leave', 'yearly'], 'lwf' => ['Labour Welfare Fund', 'yearly'],
        'gratuity' => ['Gratuity', 'exit'],
    ];
    public const GROUPS = ['monthly' => 'Monthly compliance (paid by the 15th)', 'yearly' => 'Yearly (bonus, leave, LWF by 31 Jan)', 'exit' => 'On leaving (gratuity)'];
    /** Who pays each cost: in the monthly bill, reimbursed against Eshcol's proof of payment, or Eshcol bears it. */
    public const PAYERS = ['bill' => 'Client, in the monthly bill', 'proof' => 'Client, against proof of payment', 'eshcol' => 'Eshcol bears it'];
    public const PAYER_DEFAULTS = ['pf' => 'proof', 'esi' => 'proof', 'insurance' => 'bill', 'bonus' => 'bill', 'leave' => 'bill', 'lwf' => 'bill', 'gratuity' => 'bill'];
    public const LICENCE_AT = 50;
    public const WARN_AT = 40;
    /** The joining checklist: that each was received, and when. Never the numbers themselves. */
    public const JOINING = [
        'offer_signed' => 'Signed offer and appointment letter',
        'id_address' => 'ID and address proof',
        'pan' => 'PAN',
        'bank' => 'Bank details form',
        'pf_esi' => 'PF and ESI nomination forms',
        'education' => 'Education certificates',
        'photo' => 'Photograph',
        'consent' => 'Consent to employment with Eshcol (their details used for employment)',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, array> the statutory rates, in order */
    public function rates(): array
    {
        $out = [];
        foreach ($this->app->db->all('SELECT r.*, u.name AS confirmer FROM statutory_rates r LEFT JOIN users u ON u.id = r.confirmed_by ORDER BY r.sort, r.k') as $r) {
            $out[$r['k']] = $r;
        }
        return $out;
    }

    /** @return array<string, float> */
    public function values(): array
    {
        return array_map(fn ($r) => (float) $r['value'], $this->rates());
    }

    /** Labels of the rates not yet confirmed. */
    public function unconfirmed(): array
    {
        return array_values(array_map(fn ($r) => $r['label'], array_filter($this->rates(), fn ($r) => $r['confirmed_at'] === null)));
    }

    /**
     * The Tamil Nadu minimum wage in force today for this kind of work, skill level and zone (confirmed rows only), or null.
     * A row for the position's zone wins; a row with no zone applies to every zone.
     */
    public function minWage(?string $kind, ?string $skill, ?string $zone = null, ?string $on = null): ?array
    {
        if (!$kind || !$skill) {
            return null;
        }
        $zone = trim((string) $zone);
        $basic = $this->app->db->one("SELECT * FROM min_wages WHERE work_kind = ? AND skill_level = ? AND effective_from <= ? AND confirmed_at IS NOT NULL
            AND (zone IS NULL OR zone = '' OR zone = ?) ORDER BY (zone = ?) DESC, effective_from DESC, id DESC LIMIT 1", [$kind, $skill, $on ?? date('Y-m-d'), $zone, $zone]);
        if (!$basic) {
            return null;
        }
        // Tamil Nadu: the minimum wage is the basic rate plus the dearness allowance in force (revised every 1 April).
        // A daily rate is turned into a month at x 30, and the DA per month is added (Benjamin's working, Jan 2026).
        $da = $this->da($kind, $on);
        if (!$da) {
            return null;
        }
        $basic['basic'] = (float) $basic['monthly'];
        $basic['da'] = (float) $da['monthly'];
        $basic['da_from'] = $da['effective_from'];
        $basic['monthly'] = round($basic['basic'] + $basic['da'], 2);
        return $basic;
    }

    /** The dearness allowance in force for a scheduled employment (confirmed rows only), or null. */
    public function da(string $kind, ?string $on = null): ?array
    {
        return $this->app->db->one('SELECT * FROM min_wage_da WHERE work_kind = ? AND effective_from <= ? AND confirmed_at IS NOT NULL ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$kind, $on ?? date('Y-m-d')]) ?: null;
    }

    /** What is still waiting for Benjamin's confirmation among the minimum wages and DA (counts). */
    public function wagesToConfirm(): array
    {
        return ['basic' => (int) $this->app->db->col('SELECT COUNT(*) FROM min_wages WHERE confirmed_at IS NULL'),
            'da' => (int) $this->app->db->col('SELECT COUNT(*) FROM min_wage_da WHERE confirmed_at IS NULL')];
    }

    /** Zones entered with the minimum wages, for the position form. */
    public function zones(): array
    {
        return array_column($this->app->db->all("SELECT DISTINCT zone FROM min_wages WHERE zone IS NOT NULL AND zone <> '' ORDER BY zone"), 'zone');
    }

    /**
     * The terms a staffing position bills on: its client's, unless the position sets its own fee (plan v2, 4.3:
     * "each staffing position copies the client's terms and can override them"). Everything else stays the client's.
     */
    public static function effectiveTerms(?array $clientTerms, array $role): ?array
    {
        if (!$clientTerms) {
            return null;
        }
        $model = $role['terms_model'] ?? null;
        if (!$model || !isset(self::BILLING[$model])) {
            return $clientTerms + ['overridden' => false];
        }
        return ['billing_model' => $model, 'markup_pct' => $model === 'markup' ? $role['terms_markup_pct'] : null,
            'fixed_fee' => $model === 'fixed_fee' ? $role['terms_fixed_fee'] : null, 'overridden' => true] + $clientTerms;
    }

    /** The client's current staffing terms, or null. */
    public function terms(int $clientId): ?array
    {
        return $this->app->db->one('SELECT * FROM client_terms WHERE client_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1', [$clientId]) ?: null;
    }

    /** Do these terms let Eshcol pay wages by the 7th? Short payment terms, advance billing, or at least a month's deposit. */
    public static function safeguard(?array $t): ?string
    {
        if (!$t) {
            return null;
        }
        if ($t['invoice_timing'] === 'advance') {
            return 'Billed in advance';
        }
        if ((float) $t['deposit_months'] >= 1) {
            return 'Deposit of ' . rtrim(rtrim(number_format((float) $t['deposit_months'], 2), '0'), '.') . ' month' . ((float) $t['deposit_months'] > 1 ? 's\'' : '\'s') . ' billing';
        }
        if ((int) $t['payment_due_days'] <= 6) {
            return 'Invoiced at month end, due in ' . (int) $t['payment_due_days'] . ' days';
        }
        return null;
    }

    /**
     * The month for one person: what they cost Eshcol (gross pay plus employer PF, ESI, bonus, gratuity, leave, LWF,
     * insurance and overhead), what the client is billed, the margin and GST. Pure: tested directly.
     * @param array<string, float> $v statutory values (values())
     */
    public static function calc(float $gross, array $terms, ?float $billRate, array $v, ?float $minWage = null): array
    {
        $g = max(0.0, $gross);
        $share = $g * ($v['wage_share_pct'] ?? 50) / 100;
        // The law: basic + DA can't be below the minimum wage, so wages are at least it (never more than the gross).
        $lawWage = min($g, max($share, (float) $minWage));
        $rule = ($terms['wage_rule'] ?? 'law') === 'gross' ? 'gross' : 'law';
        $wage = $rule === 'law' ? $lawWage : $share;
        $partsFor = function (float $wage) use ($g, $v, $minWage): array {
            $pfBase = min($wage, $v['pf_ceiling'] ?? 15000);
            return [
                'pf' => $pfBase * (($v['pf_employer_pct'] ?? 12) + ($v['pf_admin_pct'] ?? 0)) / 100,
                'esi' => $g <= ($v['esi_ceiling'] ?? 21000) ? $g * ($v['esi_employer_pct'] ?? 3.25) / 100 : 0.0,
                'bonus' => $wage <= ($v['bonus_eligibility'] ?? 21000) ? ($v['bonus_pct'] ?? 8.33) / 100 * min($wage, max($v['bonus_calc_ceiling'] ?? 7000, (float) $minWage)) : 0.0,
                'gratuity' => $wage * ($v['gratuity_pct'] ?? 4.81) / 100,
                'leave' => $g * ($v['leave_pct'] ?? 0) / 100,
                'lwf' => ($v['lwf_employer_year'] ?? 0) / 12,
                'insurance' => $v['insurance_month'] ?? 0.0,
                'overhead' => $g * ($v['overhead_pct'] ?? 0) / 100,
            ];
        };
        $parts = $partsFor($wage);
        $pfBase = min($wage, $v['pf_ceiling'] ?? 15000);
        $cost = $g + array_sum($parts);
        // Who pays each cost. Terms saved before 16 Nov 2026 have none: everything was in the monthly bill.
        $saved = is_string($terms['payers'] ?? null) ? (json_decode($terms['payers'], true) ?: []) : (array) ($terms['payers'] ?? []);
        $payers = [];
        foreach (self::COSTS as $k => $_) {
            $payers[$k] = isset(self::PAYERS[$saved[$k] ?? '']) ? $saved[$k] : (isset($terms['payers']) ? self::PAYER_DEFAULTS[$k] : 'bill');
        }
        $sum = fn (string $who, ?string $group = null) => array_sum(array_map(fn ($k) => $payers[$k] === $who && ($group === null || self::COSTS[$k][1] === $group) ? $parts[$k] : 0.0, array_keys(self::COSTS)));
        $inBill = $sum('bill');
        $proof = $sum('proof');
        $model = $terms['billing_model'] ?? 'markup';
        // The fee % base: gross pay only, or the whole cost (terms saved before 16 Nov 2026 used the whole cost).
        $base = ($terms['markup_base'] ?? 'cost') === 'gross' ? $g : $cost;
        if ($model === 'rate') {
            $monthly = (float) ($billRate ?? 0); // the rate covers the wages, the costs in the monthly bill and the fee
            $fee = $monthly - $g - $inBill;
        } else {
            $fee = $model === 'fixed_fee' ? (float) ($terms['fixed_fee'] ?? 0) : $base * (float) ($terms['markup_pct'] ?? 0) / 100;
            $monthly = $g + $inBill + $fee;
        }
        $bill = $monthly + $proof; // all the client pays for the month, excluding GST
        $margin = $bill - $cost;
        $gstPct = (float) ($terms['gst_pct'] ?? $v['gst_pct'] ?? 18);
        $gst = $bill * $gstPct / 100;
        $r = fn (float $x) => round($x, 2);
        // On the "common practice" rule, what it would cost more if an inspector applied the law.
        $lawExtra = $rule === 'gross' && $lawWage > $share + 0.005 ? array_sum($partsFor($lawWage)) - array_sum($parts) : 0.0;
        $groups = [];
        foreach (self::GROUPS as $gk => $_) {
            $groups[$gk] = ['bill' => $r($sum('bill', $gk)), 'proof' => $r($sum('proof', $gk)), 'eshcol' => $r($sum('eshcol', $gk))];
        }
        return [
            'gross' => $r($g), 'wage' => $r($wage), 'wage_rule' => $rule, 'law_wage' => $r($lawWage), 'law_extra' => $r($lawExtra),
            'parts' => array_map($r, $parts), 'payers' => $payers, 'groups' => $groups, 'cost' => $r($cost),
            'fee' => $r($fee), 'markup_base' => $model === 'markup' ? (($terms['markup_base'] ?? 'cost') === 'gross' ? 'gross' : 'cost') : null,
            'monthly_bill' => $r($monthly), 'monthly_gst' => $r($monthly * $gstPct / 100), 'reimbursed' => $r($proof),
            'reimbursed_monthly' => $r($sum('proof', 'monthly')), 'eshcol_bears' => $r($sum('eshcol') + $parts['overhead']),
            'bill' => $r($bill),
            'margin' => $r($margin), 'margin_pct' => $bill > 0 ? round($margin / $bill * 100, 2) : 0.0,
            'gst' => $r($gst), 'invoice' => $r($bill + $gst),
            // Annual CTC as the candidate's offer states it: gross plus employer PF (not the admin charges), ESI, bonus and gratuity.
            'ctc_lpa' => round(($g + $pfBase * ($v['pf_employer_pct'] ?? 12) / 100 + $parts['esi'] + $parts['bonus'] + $parts['gratuity']) * 12 / 100000, 2),
        ];
    }

    /** People with an approved Eshcol offer on a staffing position who haven't joined yet (and haven't left the pipeline). */
    public function aboutToJoin(?int $exceptApp = null): int
    {
        return (int) $this->app->db->col("SELECT COUNT(DISTINCT a.candidate_id) FROM offer_approvals o JOIN applications a ON a.id = o.application_id
            JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
            WHERE r.engagement = 'staffing' AND o.approved_at IS NOT NULL AND s.name <> 'Joined' AND s.kind NOT IN ('fail', 'hold') AND a.id <> ?", [(int) $exceptApp]);
    }

    /** People deputed now: on a staffing position and at "Joined". */
    public function headcount(): int
    {
        return (int) $this->app->db->col("SELECT COUNT(DISTINCT a.candidate_id) FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
            WHERE r.engagement = 'staffing' AND s.name = 'Joined'");
    }

    /** Is the contract labour licence on file and in date? */
    public function licence(): ?array
    {
        $no = trim((string) $this->app->setting('labour_licence_no', ''));
        $to = (string) $this->app->setting('labour_licence_valid_to', '');
        return $no !== '' && $to >= date('Y-m-d') ? ['no' => $no, 'valid_to' => $to] : null;
    }

    public function minMarginPct(): float
    {
        return (float) $this->app->setting('staffing_min_margin_pct', '8');
    }

    /**
     * Everything stopping a staffing offer being approved (empty: none), and the worked figures.
     * @return array{0: list<string>, 1: ?array, 2: ?array} [problems, calc, terms]
     */
    public function check(int $appId, ?float $gross, ?string $marginReason): array
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.id, a.candidate_id, r.* , a.id AS app_id FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$appId]);
        $problems = [];
        if (!$a) {
            return [['Not found.'], null, null];
        }
        if ($un = $this->unconfirmed()) {
            $problems[] = count($un) . ' statutory rate' . (count($un) > 1 ? 's are' : ' is') . ' not confirmed yet (Settings › Staffing).';
        }
        $clientTerms = $this->terms((int) $a['client_id']);
        if (!$clientTerms) {
            $problems[] = 'This client has no staffing terms yet (on the client\'s page).';
        } elseif (!self::safeguard($clientTerms)) {
            $problems[] = 'The client\'s terms don\'t protect wages by the 7th: use payment within 6 days of a month-end invoice, advance billing, or a deposit.';
        }
        // The position's own fee, if it sets one (the rest of the terms stay the client's).
        $terms = self::effectiveTerms($clientTerms, $a);
        if (!$a['work_kind'] || !$a['skill_level']) {
            $problems[] = 'Set the kind of work and the skill level on the position (for the minimum wage).';
        }
        $zone = trim((string) ($a['wage_zone'] ?? ''));
        $mw = $this->minWage($a['work_kind'], $a['skill_level'], $zone);
        if ($a['work_kind'] && $a['skill_level'] && !$mw) {
            $problems[] = 'No confirmed Tamil Nadu minimum wage for ' . (self::WORK_KINDS[$a['work_kind']] ?? $a['work_kind']) . ', ' . (self::SKILLS[$a['skill_level']] ?? $a['skill_level'])
                . ($zone !== '' ? ", Zone $zone" : '') . ($a['work_kind'] && !$this->da((string) $a['work_kind']) ? ': the dearness allowance isn\x27t confirmed' : '') . ' (Settings › Staffing).';
        }
        if ($zone === '' && $a['work_kind']) {
            $problems[] = 'Choose the minimum-wage zone of the client\x27s site on the position (Zone A or B).';
        }
        if ($gross === null || $gross <= 0) {
            $problems[] = 'Enter the monthly gross pay.';
            return [$problems, null, $terms];
        }
        // Never below the Tamil Nadu minimum wage, nor the national floor wage (Code on Wages).
        $floor = (float) ($this->values()['floor_wage_month'] ?? 0);
        if ($mw && $gross < (float) $mw['monthly']) {
            $problems[] = 'The pay is below the Tamil Nadu minimum wage of ₹' . number_format((float) $mw['monthly']) . ' a month (basic ₹' . number_format((float) $mw['basic']) . ' + DA ₹' . number_format((float) $mw['da']) . ').';
        }
        if ($floor > 0 && $gross < $floor) {
            $problems[] = 'The pay is below the national floor wage of ₹' . number_format($floor) . ' a month.';
        }
        if (($terms['billing_model'] ?? '') === 'rate' && !$a['bill_rate_month']) {
            $problems[] = 'This position is billed a monthly rate: enter it on the position.';
        }
        if (!empty($terms['overridden']) && (($terms['billing_model'] === 'markup' && $terms['markup_pct'] === null) || ($terms['billing_model'] === 'fixed_fee' && $terms['fixed_fee'] === null))) {
            $problems[] = 'The position\'s own fee is chosen but not filled in.';
        }
        $minFor = $mw ? max((float) $mw['monthly'], $floor) : ($floor > 0 ? $floor : null);
        $calc = $terms ? self::calc($gross, $terms, $a['bill_rate_month'] !== null ? (float) $a['bill_rate_month'] : null, $this->values(), $minFor) : null;
        if ($calc && $mw) {
            // Shown on the offer: "minimum wage ₹x (basic ₹y + DA ₹z)".
            $calc['min_wage'] = ['total' => (float) $mw['monthly'], 'basic' => (float) $mw['basic'], 'da' => (float) $mw['da'], 'zone' => $zone];
        }
        if ($calc && $calc['margin_pct'] < $this->minMarginPct() && trim((string) $marginReason) === '') {
            $problems[] = 'The margin is ' . $calc['margin_pct'] . ' % of the bill, below your minimum of ' . rtrim(rtrim(number_format($this->minMarginPct(), 2), '0'), '.') . ' %: give a reason to go ahead.';
        }
        $already = (int) $db->col("SELECT 1 FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ? AND s.name = 'Joined'", [$appId]);
        // Counted: people deputed, and those with an approved offer who are about to join (plan v2, 4.3).
        if ($this->headcount() + $this->aboutToJoin($appId) + ($already ? 0 : 1) >= self::LICENCE_AT && !$this->licence()) {
            $problems[] = 'With the people deputed and those about to join, this would make ' . self::LICENCE_AT . ' or more: enter the contract labour licence (Settings › Staffing) first.';
        }
        return [$problems, $calc, $terms];
    }
}
