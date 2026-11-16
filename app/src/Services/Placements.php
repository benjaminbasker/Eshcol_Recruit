<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Placements (B9, approved by Benjamin 14 Nov 2026).
 *  - "Offer made" (the last active stage before Joined) opens a placement for the offer details.
 *  - Joined (a "success" stage) makes it a placement: fee from the client's terms (% of the TOTAL annual CTC,
 *    variable pay included, or a fixed amount), guarantee end from the client's replacement days.
 *  - Invoices are raised in Zoho Books; the app keeps the number and dates, and reminds.
 *  - Left within the guarantee: the same position reopens as a replacement; the next joiner carries no fee.
 *  - Money is the owner's only (enforced by the controller and the views).
 */
final class Placements
{
    public const INVOICE_NUDGE_DAYS = 3;
    public const CALL_BEFORE_JOIN_DAYS = 3;

    /** @var callable|null fixes "today" in tests: fn(): string (Y-m-d) */
    public $today = null;

    public function __construct(private readonly App $app)
    {
    }

    private function today(): string
    {
        return $this->today ? (string) ($this->today)() : date('Y-m-d');
    }

    /** The "Offer made" stage: the last active stage before the first success stage. */
    public function offerStageId(): ?int
    {
        $db = $this->app->db;
        $success = $db->col("SELECT MIN(sort) FROM stages WHERE kind = 'success'");
        $id = $success !== null ? $db->col("SELECT id FROM stages WHERE kind = 'active' AND sort < ? ORDER BY sort DESC LIMIT 1", [$success]) : null;
        return $id ? (int) $id : null;
    }

    /** Called after any stage move: opens, completes or closes the placement for that application. */
    public function afterStage(int $applicationId, int $toStage): ?int
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.*, r.client_id, r.is_pool, r.replacement_for, r.engagement, cd.full_name FROM applications a JOIN roles r ON r.id = a.role_id
            JOIN candidates cd ON cd.id = a.candidate_id WHERE a.id = ?', [$applicationId]);
        // A placement (with a fee) is client recruitment only. Our own team and staffing go to the HRMS through the joining record (15 Nov 2026).
        if (!$a || $a['is_pool'] || in_array($a['engagement'] ?? 'placement', ['inhouse', 'staffing'], true)) {
            return null;
        }
        $kind = (string) $db->col('SELECT kind FROM stages WHERE id = ?', [$toStage]);
        $p = $db->one('SELECT * FROM placements WHERE application_id = ?', [$applicationId]);
        if ($kind === 'success') {
            $id = $p ? (int) $p['id'] : $this->open($a, 'joined');
            $p = $db->one('SELECT * FROM placements WHERE id = ?', [$id]);
            $changes = ['status' => 'joined', 'joined_on' => $p['joined_on'] ?: ($p['expected_join'] && $p['expected_join'] <= $this->today() ? $p['expected_join'] : $this->today())];
            $db->update('placements', $changes, $id);
            $this->recompute($id);
            Audit::log('placement_joined', 'candidate', (int) $a['candidate_id'], ['placement' => $id]);
            return $id;
        }
        if ($toStage === $this->offerStageId()) {
            if ($p) {
                $db->update('placements', ['status' => 'offer'], (int) $p['id']);
                return (int) $p['id'];
            }
            return $this->open($a, 'offer');
        }
        if ($p && $p['status'] === 'offer' && $kind === 'fail') {
            $db->update('placements', ['status' => 'withdrew'], (int) $p['id']); // the offer fell through
        }
        return $p ? (int) $p['id'] : null;
    }

    private function open(array $a, string $status): int
    {
        $db = $this->app->db;
        $client = $a['client_id'] ? $db->one('SELECT fee_type, fee_value, payment_terms_days FROM clients WHERE id = ?', [$a['client_id']]) : null;
        $row = ['application_id' => (int) $a['id'], 'candidate_id' => (int) $a['candidate_id'], 'candidate_name' => $a['full_name'], 'role_id' => (int) $a['role_id'],
            'client_id' => $a['client_id'] ? (int) $a['client_id'] : null, 'status' => $status, 'fee_type' => $client['fee_type'] ?? null,
            'fee_value' => $client['fee_value'] ?? null, 'payment_terms_days' => $client['payment_terms_days'] ?? null];
        // The first joiner on a reopened position replaces the one who left: no new fee.
        if ($a['replacement_for'] && !$db->col("SELECT 1 FROM placements WHERE replacement_of = ?", [$a['replacement_for']])) {
            $row['replacement_of'] = (int) $a['replacement_for'];
        }
        $id = $db->insert('placements', $row);
        if ($status === 'offer' && ($ctc = $db->col('SELECT ctc_expected_lpa FROM candidates WHERE id = ?', [$a['candidate_id']]))) {
            $db->update('placements', ['offer_ctc_lpa' => $ctc], $id); // a starting point; you correct it to the offer
        }
        return $id;
    }

    /** The fee: % of the total annual CTC (variable included), or the fixed amount; 0 for a replacement. */
    public static function fee(?string $type, $value, $ctcLpa, bool $replacement): ?float
    {
        if ($replacement) {
            return 0.0;
        }
        if ($type === 'fixed' && $value !== null && $value !== '') {
            return round((float) $value);
        }
        if ($type === 'percent' && $value !== null && $value !== '' && $ctcLpa !== null && $ctcLpa !== '') {
            return round((float) $ctcLpa * 100000 * (float) $value / 100);
        }
        return null;
    }

    /** Re-works out the fee (unless you set your own) and the guarantee end. */
    public function recompute(int $id): void
    {
        $db = $this->app->db;
        $p = $db->one('SELECT * FROM placements WHERE id = ?', [$id]);
        $changes = [];
        if (!$p['fee_overridden']) {
            $changes['fee_amount'] = self::fee($p['fee_type'], $p['fee_value'], $p['offer_ctc_lpa'], (bool) $p['replacement_of']);
        }
        $days = $p['client_id'] ? $db->col('SELECT replacement_days FROM clients WHERE id = ?', [$p['client_id']]) : null;
        $changes['guarantee_until'] = $p['joined_on'] && $days !== null ? date('Y-m-d', strtotime($p['joined_on'] . " +$days days")) : null;
        $db->update('placements', $changes, $id);
    }

    /** Saves what you typed on the candidate page. Blank fee = worked out from the client's terms. */
    public function save(int $id, array $in): void
    {
        $date = fn ($k) => isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in[$k]) ? (string) $in[$k] : null;
        $num = fn ($k) => isset($in[$k]) && trim((string) $in[$k]) !== '' && is_numeric(str_replace(',', '', (string) $in[$k])) ? (float) str_replace(',', '', (string) $in[$k]) : null;
        $fee = $num('fee_amount');
        $this->app->db->update('placements', [
            'offer_date' => $date('offer_date'), 'offer_ctc_lpa' => $num('offer_ctc_lpa'), 'expected_join' => $date('expected_join'),
            'joined_on' => $date('joined_on'), 'invoice_no' => mb_substr(trim((string) ($in['invoice_no'] ?? '')), 0, 60) ?: null,
            'invoiced_on' => $date('invoiced_on'), 'paid_on' => $date('paid_on'),
            'fee_amount' => $fee, 'fee_overridden' => $fee !== null && ($in['fee_auto'] ?? '') !== (string) $fee ? 1 : 0,
        ], $id);
        $this->recompute($id);
        Audit::log('placement_saved', 'placement', $id, []);
    }

    /** Left within the guarantee: reopen the same position as a replacement (no new fee for the next joiner). */
    public function left(int $id, ?string $on, ?string $note): void
    {
        $db = $this->app->db;
        $p = $db->one('SELECT * FROM placements WHERE id = ?', [$id]);
        $on = $on && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : $this->today();
        $db->update('placements', ['status' => 'left', 'left_on' => $on, 'left_note' => $note ? mb_substr($note, 0, 255) : null], $id);
        $inGuarantee = $p['guarantee_until'] && $on <= $p['guarantee_until'];
        if ($inGuarantee) {
            $db->run("UPDATE roles SET status = 'open', replacement_for = ? WHERE id = ?", [$id, $p['role_id']]);
        }
        Audit::log('placement_left', 'placement', $id, ['in_guarantee' => $inGuarantee]);
    }

    public function checkin(int $id, int $day): void
    {
        if (in_array($day, [30, 60], true)) {
            $this->app->db->run("UPDATE placements SET checkin{$day}_at = NOW() WHERE id = ?", [$id]);
        }
    }

    /** Money status: to_invoice, invoiced, overdue, paid, none (no fee: replacement), or '' before joining. */
    public function moneyStatus(array $p): string
    {
        if ($p['status'] !== 'joined' && $p['status'] !== 'left') {
            return '';
        }
        if ($p['paid_on']) {
            return 'paid';
        }
        if ($p['replacement_of'] || (float) ($p['fee_amount'] ?? -1) === 0.0) {
            return 'none';
        }
        if (!$p['invoice_no'] && !$p['invoiced_on']) {
            return 'to_invoice';
        }
        $due = $this->dueOn($p);
        return $due && $due < $this->today() ? 'overdue' : 'invoiced';
    }

    public function dueOn(array $p): ?string
    {
        return $p['invoiced_on'] ? date('Y-m-d', strtotime($p['invoiced_on'] . ' +' . (int) ($p['payment_terms_days'] ?? 30) . ' days')) : null;
    }

    public const MONEY = ['to_invoice' => 'To invoice', 'invoiced' => 'Invoiced', 'overdue' => 'Payment overdue', 'paid' => 'Paid', 'none' => 'No fee (replacement)'];
    public const STATUS = ['offer' => 'Offer made', 'joined' => 'Joined', 'left' => 'Left', 'withdrew' => 'Offer fell through'];

    /** All placements with names, newest first. */
    public function all(?string $year = null): array
    {
        $db = $this->app->db;
        $rows = $db->all('SELECT p.*, COALESCE(cd.full_name, p.candidate_name) AS name, r.title AS role, cl.name AS client FROM placements p
            LEFT JOIN candidates cd ON cd.id = p.candidate_id LEFT JOIN roles r ON r.id = p.role_id LEFT JOIN clients cl ON cl.id = p.client_id
            ORDER BY COALESCE(p.joined_on, p.expected_join, p.created_at) DESC');
        foreach ($rows as &$r) {
            $r['money'] = $this->moneyStatus($r);
            $r['due_on'] = $this->dueOn($r);
        }
        return $rows;
    }

    /** Totals for a period (joined in it): placed, fees, invoiced, received, outstanding. */
    public function totals(array $rows, string $from, string $to): array
    {
        $t = ['placed' => 0, 'fees' => 0.0, 'invoiced' => 0.0, 'received' => 0.0, 'outstanding' => 0.0];
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['joined', 'left'], true) || !$r['joined_on'] || $r['joined_on'] < $from || $r['joined_on'] > $to) {
                continue;
            }
            $fee = (float) ($r['fee_amount'] ?? 0);
            $t['placed']++;
            $t['fees'] += $fee;
            if ($r['invoice_no'] || $r['invoiced_on']) {
                $t['invoiced'] += $fee;
            }
            if ($r['paid_on']) {
                $t['received'] += $fee;
            } elseif ($r['invoice_no'] || $r['invoiced_on']) {
                $t['outstanding'] += $fee;
            }
        }
        return $t;
    }

    /** What My day should say: calls before joining, invoices to raise, payments overdue, check-ins, guarantees ending. */
    public function reminders(): array
    {
        $out = [];
        $today = $this->today();
        foreach ($this->all() as $p) {
            $who = '<a href="/candidates/' . (int) $p['candidate_id'] . '#placement-' . (int) $p['id'] . '">' . e((string) ($p['name'] ?: 'Candidate')) . '</a> (' . e((string) $p['role']) . ', ' . e((string) $p['client']) . ')';
            if ($p['status'] === 'offer' && $p['expected_join']) {
                $days = (int) round((strtotime($p['expected_join']) - strtotime($today)) / 86400);
                if ($days >= 0 && $days <= self::CALL_BEFORE_JOIN_DAYS) {
                    $out[] = ['info', "Joining in $days day" . ($days === 1 ? '' : 's') . ": call $who to make sure they join."];
                } elseif ($days < 0) {
                    $out[] = ['info', "Expected to join on " . e(nice_date($p['expected_join'])) . ": did $who join? Move them to Joined, or record what happened."];
                }
            }
            if ($p['status'] === 'joined' || $p['status'] === 'left') {
                if ($p['money'] === 'to_invoice' && $p['joined_on'] && $p['joined_on'] <= date('Y-m-d', strtotime($today . ' -' . self::INVOICE_NUDGE_DAYS . ' days'))) {
                    $out[] = ['info', "Invoice not raised in Zoho Books yet for $who, joined " . e(nice_date($p['joined_on'])) . '.'];
                }
                if ($p['money'] === 'overdue') {
                    $out[] = ['error', "Payment overdue for $who: invoice " . e((string) $p['invoice_no']) . ' was due ' . e(nice_date($p['due_on'])) . '.'];
                }
            }
            if ($p['status'] === 'joined' && $p['joined_on']) {
                foreach ([30, 60] as $d) {
                    $dueAt = date('Y-m-d', strtotime($p['joined_on'] . " +$d days"));
                    if ($dueAt <= $today && !$p["checkin{$d}_at"] && (!$p['guarantee_until'] || $dueAt <= $p['guarantee_until'])) {
                        $out[] = ['info', "$d-day check-in: is $who still there? <a href=\"/candidates/" . (int) $p['candidate_id'] . '#placement-' . (int) $p['id'] . '">Record it</a>.'];
                    }
                }
                if ($p['guarantee_until'] && $p['guarantee_until'] >= $today && $p['guarantee_until'] <= date('Y-m-d', strtotime($today . ' +7 days'))) {
                    $out[] = ['info', "Replacement guarantee for $who ends " . e(nice_date($p['guarantee_until'])) . '.'];
                }
            }
        }
        return $out;
    }
}
