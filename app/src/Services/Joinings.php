<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The joining record (own-hiring and staffing plan v2, phase 3, 15 Nov 2026). When someone joins Eshcol's payroll,
 * for our own team or on staffing, Recruit writes down everything the HRMS needs on their first day: who they are,
 * the job, the pay and its statutory flags, the deployment at the client, the joining checklist, and how they were
 * chosen. Recruit's part ends there. Until the HRMS exists the record is downloaded (a spreadsheet of everyone, and
 * one file per person); later the HRMS imports the same record. Format: "eshcol.joining", version 1, described in
 * Eshcol_HRMS_Joining_Handoff_Spec_15Nov2026.md. Never holds Aadhaar, PAN or bank numbers (only that they were received).
 */
final class Joinings
{
    public const FORMAT = 'eshcol.joining';
    public const VERSION = 1;
    public const STATUS = ['ready' => 'Ready for the HRMS', 'downloaded' => 'Downloaded', 'imported' => 'In the HRMS', 'withdrawn' => 'Withdrawn'];

    public function __construct(private readonly App $app)
    {
    }

    /** Creates (or refreshes, while not yet in the HRMS) the record for an application that has joined. Returns its id, or null. */
    public function record(int $applicationId, ?int $userId): ?int
    {
        $db = $this->app->db;
        $payload = $this->build($applicationId);
        if (!$payload) {
            return null;
        }
        $old = $db->one('SELECT id, status FROM joinings WHERE application_id = ?', [$applicationId]);
        $row = ['candidate_id' => $payload['person']['candidate_id'], 'full_name' => $payload['person']['full_name'] ?: 'Name not found',
            'engagement' => $payload['job']['type'], 'joining_date' => $payload['job']['joining_date'], 'format_version' => self::VERSION,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($old) {
            if ($old['status'] === 'imported') {
                return (int) $old['id']; // the HRMS has it: it is the HRMS's record now
            }
            $db->run('UPDATE joinings SET ' . implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row))) . ", status = 'ready' WHERE id = ?", [...array_values($row), $old['id']]);
            $id = (int) $old['id'];
        } else {
            $id = $db->insert('joinings', $row + ['application_id' => $applicationId, 'created_by' => $userId]);
        }
        Audit::log('joining_recorded', 'candidate', (int) $payload['person']['candidate_id'], ['joining' => $id, 'type' => $payload['job']['type']]);
        return $id;
    }

    /** The record itself: null unless the application is on an own-team or staffing position. */
    public function build(int $applicationId): ?array
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.*, r.title, r.code, r.engagement, r.location AS role_location, r.work_mode, r.contract_months, r.work_kind, r.skill_level,
                r.bill_rate_month, r.hiring_manager_id, r.client_id, cl.name AS client_name, cl.legal_name AS client_legal_name, cl.gstin AS client_gstin,
                cl.billing_address AS client_billing_address, cl.interview_address AS client_site, cl.is_self
            FROM applications a JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id WHERE a.id = ?', [$applicationId]);
        if (!$a || !in_array($a['engagement'], ['inhouse', 'staffing'], true)) {
            return null;
        }
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$a['candidate_id']]) ?: [];
        $offer = $db->one('SELECT o.*, u.name AS approver FROM offer_approvals o LEFT JOIN users u ON u.id = o.approved_by WHERE o.application_id = ?', [$applicationId]) ?: [];
        $calc = !empty($offer['calc']) ? (json_decode((string) $offer['calc'], true) ?: null) : null;
        $terms = !empty($offer['client_terms_id']) ? $db->one('SELECT * FROM client_terms WHERE id = ?', [$offer['client_terms_id']]) : null;
        $cv = $db->one('SELECT id, original_name, ext, created_at FROM cv_files WHERE id = ?', [(new Shortlists($this->app))->cvFor($applicationId) ?? 0]);
        $joining = $offer['joining_date'] ?? null;
        $isStaffing = $a['engagement'] === 'staffing';
        $v = (new Staffing($this->app))->values();
        $wage = $calc['wage'] ?? null;
        $gross = $offer['gross_month'] ?? null;
        $n = fn ($x) => $x === null || $x === '' ? null : (float) $x;
        $date = fn ($x) => $x ? substr((string) $x, 0, 10) : null;
        return [
            'format' => self::FORMAT, 'version' => self::VERSION, 'made_at' => date('c'), 'source' => 'Eshcol Recruit',
            'source_ref' => ['application_id' => (int) $a['id'], 'candidate_ref' => ref_candidate((int) $a['candidate_id']), 'position_code' => $a['code']],
            'person' => [
                'candidate_id' => (int) $a['candidate_id'], 'full_name' => $c['full_name'] ?? null, 'first_name' => $c['first_name'] ?? null,
                'email' => $c['email'] ?? null, 'phone' => $c['phone'] ?? null, 'city' => $c['location'] ?? null, 'locality' => $c['locality'] ?? null,
                'pin_code' => $c['pin_code'] ?? null, 'linkedin' => $c['linkedin_url'] ?? null, 'qualifications' => $c['qualifications'] ?? null,
                'experience_months' => isset($c['exp_months']) ? exp_now($c) : null, 'previous_title' => $c['current_title'] ?? null,
                'previous_employer' => $c['current_company'] ?? null, 'languages' => $c['languages'] ?? null,
            ],
            'job' => [
                'type' => $a['engagement'], 'type_label' => $isStaffing ? 'Staffing (on Eshcol\'s payroll, deputed to a client)' : 'Eshcol Global\'s own team',
                'designation' => $offer['designation'] ?? $a['title'], 'position_title' => $a['title'], 'joining_date' => $date($joining),
                'work_location' => $a['role_location'], 'work_mode' => $a['work_mode'], 'work_kind' => $a['work_kind'], 'skill_level' => $a['skill_level'],
                'reports_to' => $a['hiring_manager_id'] ? $db->col('SELECT name FROM users WHERE id = ?', [$a['hiring_manager_id']]) : null,
                'contract_months' => $a['contract_months'] !== null ? (int) $a['contract_months'] : null,
                'contract_end' => $isStaffing && $joining && $a['contract_months'] ? date('Y-m-d', strtotime($date($joining) . ' +' . (int) $a['contract_months'] . ' months -1 day')) : null,
            ],
            'pay' => [
                'gross_month' => $n($gross), 'annual_ctc_lpa' => $n($offer['ctc_lpa'] ?? null), 'wages_month' => $n($wage),
                'pf_applicable' => true,
                'esi_applicable' => $gross !== null ? (float) $gross <= ($v['esi_ceiling'] ?? 21000) : null,
                'bonus_eligible' => $wage !== null ? (float) $wage <= ($v['bonus_eligibility'] ?? 21000) : null,
                'wage_rule' => $calc['wage_rule'] ?? null, 'employer_costs_month' => $calc['parts'] ?? null, 'who_pays' => $calc['payers'] ?? null, 'cost_month' => $n($calc['cost'] ?? null),
                'note' => 'Worked out in Recruit at offer approval from the statutory rates confirmed then. The HRMS payroll recalculates every month.',
            ],
            'deployment' => $isStaffing ? [
                'client_name' => $a['client_name'], 'client_legal_name' => $a['client_legal_name'], 'client_gstin' => $a['client_gstin'],
                'client_billing_address' => $a['client_billing_address'], 'client_site' => $a['client_site'] ?: $a['role_location'],
                'start' => $date($joining), 'planned_end' => $joining && $a['contract_months'] ? date('Y-m-d', strtotime($date($joining) . ' +' . (int) $a['contract_months'] . ' months -1 day')) : null,
                'bill_month' => $n($offer['bill_month'] ?? null), 'margin_month' => $n($offer['margin_month'] ?? null),
                'margin_pct' => $calc['margin_pct'] ?? null, 'margin_reason' => $offer['margin_reason'] ?? null,
                'gst_pct' => $n($terms['gst_pct'] ?? null), 'invoice_month' => $n($calc['invoice'] ?? null),
                'fee_used' => !empty($offer['terms_used']) ? (json_decode((string) $offer['terms_used'], true) ?: null) : null, // the position's own fee, if it had one
                'terms' => $terms ? [
                    'version_id' => (int) $terms['id'], 'billing_model' => $terms['billing_model'], 'markup_pct' => $n($terms['markup_pct']), 'fixed_fee' => $n($terms['fixed_fee']),
                    'bill_rate_month' => $n($a['bill_rate_month']), 'invoice_timing' => $terms['invoice_timing'], 'payment_due_days' => (int) $terms['payment_due_days'],
                    'deposit_months' => $n($terms['deposit_months']), 'replacement_days' => $terms['replacement_days'] !== null ? (int) $terms['replacement_days'] : null,
                    'notice_days' => $terms['notice_days'] !== null ? (int) $terms['notice_days'] : null,
                    'conversion_fee_pct' => ['within_6_months' => $n($terms['conv_pct_6m']), 'months_6_to_12' => $n($terms['conv_pct_12m']), 'after_12_months' => $n($terms['conv_pct_after'])],
                    'rate_revision' => $terms['rate_revision'], 'markup_base' => $terms['markup_base'] ?? null, 'wage_rule' => $terms['wage_rule'] ?? null,
                    'who_pays' => !empty($terms['payers']) ? (json_decode((string) $terms['payers'], true) ?: null) : null,
                    'reimburse_days' => isset($terms['reimburse_days']) ? (int) $terms['reimburse_days'] : null,
                ] : null,
            ] : null,
            'documents' => array_map(fn ($r) => ['item' => $r['item'], 'label' => Staffing::JOINING[$r['item']] ?? $r['item'], 'received_on' => $r['received_on']],
                $db->all('SELECT item, received_on FROM joining_items WHERE application_id = ? ORDER BY received_on, item', [$applicationId])),
            'cv' => $cv ? ['cv_file_id' => (int) $cv['id'], 'file_name' => $cv['original_name'], 'type' => $cv['ext'], 'received' => $date($cv['created_at'])] : null,
            'selection' => [
                'offer_approved_by' => $offer['approver'] ?? null, 'offer_approved_at' => $offer['approved_at'] ?? null,
                'interview_feedback' => array_map(fn ($f) => ['round' => (int) $f['round'], 'interviewer' => $f['interviewer'], 'rating' => $f['rating'] !== null ? (int) $f['rating'] : null,
                    'recommend' => $f['recommend'], 'strengths' => $f['strengths'], 'concerns' => $f['concerns'], 'on' => $date($f['created_at'])],
                    $db->all('SELECT * FROM interview_feedback WHERE application_id = ? ORDER BY round, id', [$applicationId])),
                'references' => array_map(fn ($r) => ['referee' => $r['referee'], 'relation' => $r['relation'], 'outcome' => $r['outcome'], 'summary' => $r['summary'], 'on' => $r['checked_on']],
                    $db->all('SELECT * FROM reference_checks WHERE application_id = ? ORDER BY id', [$applicationId])),
                'consents' => array_map(fn ($r) => ['purpose' => $r['purpose'], 'status' => $r['status'], 'via' => $r['via'], 'on' => $date($r['created_at'])],
                    $db->all('SELECT purpose, status, via, created_at FROM consents WHERE candidate_id = ? ORDER BY id', [$a['candidate_id']])),
            ],
        ];
    }

    /** One row per person for the spreadsheet download: the fields a payroll set-up needs first. */
    public static function csvRow(array $p): array
    {
        $d = $p['deployment'] ?? [];
        return [
            $p['source_ref']['candidate_ref'] ?? '', $p['person']['full_name'] ?? '', $p['person']['email'] ?? '', $p['person']['phone'] ?? '',
            $p['job']['type'] ?? '', $p['job']['designation'] ?? '', $p['job']['joining_date'] ?? '', $p['job']['work_location'] ?? '',
            $p['job']['contract_end'] ?? '', $p['pay']['gross_month'] ?? '', $p['pay']['annual_ctc_lpa'] ?? '',
            ($p['pay']['esi_applicable'] ?? null) === null ? '' : (($p['pay']['esi_applicable']) ? 'Yes' : 'No'),
            ($p['pay']['bonus_eligible'] ?? null) === null ? '' : (($p['pay']['bonus_eligible']) ? 'Yes' : 'No'),
            $d['client_name'] ?? '', $d['client_site'] ?? '', $d['bill_month'] ?? '', $d['margin_pct'] ?? '',
            count($p['documents'] ?? []) . ' of ' . count(Staffing::JOINING),
        ];
    }

    public const CSV_HEAD = ['Ref', 'Name', 'Email', 'Mobile', 'Type', 'Designation', 'Joining date', 'Work location', 'Contract ends', 'Gross per month (₹)',
        'Annual CTC (LPA)', 'ESI applies', 'Bonus eligible', 'Client', 'Client site', 'Bill per month (₹)', 'Margin %', 'Joining documents'];
}
