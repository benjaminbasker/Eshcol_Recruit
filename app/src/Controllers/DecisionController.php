<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;

/**
 * Deciding on a candidate (own-hiring and staffing plan v2, phase 1, 15 Nov 2026): interview feedback per round,
 * reference checks, and the offer approval Eshcol's own offers need (own team and staffing) before "Offer made".
 */
final class DecisionController extends Controller
{
    public const RECOMMEND = ['yes' => 'Yes, hire', 'maybe' => 'Maybe', 'no' => 'No'];
    public const OUTCOME = ['positive' => 'Positive', 'mixed' => 'Mixed', 'negative' => 'Negative'];

    public function feedback(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $rating = (int) ($_POST['rating'] ?? 0);
        $rec = (string) ($_POST['recommend'] ?? '');
        $strengths = $this->str('strengths', 4000);
        $concerns = $this->str('concerns', 4000);
        if (!$strengths && !$concerns && !isset(self::RECOMMEND[$rec]) && ($rating < 1 || $rating > 5)) {
            Http::flash('error', 'Nothing to save: give a rating, a recommendation or a few words.');
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        $this->db->insert('interview_feedback', [
            'application_id' => $appId, 'round' => max(1, min(9, (int) ($_POST['round'] ?? 1))), 'user_id' => Auth::user()['id'],
            'interviewer' => $this->str('interviewer', 160) ?: Auth::user()['name'], 'rating' => $rating >= 1 && $rating <= 5 ? $rating : null,
            'strengths' => $strengths, 'concerns' => $concerns, 'recommend' => isset(self::RECOMMEND[$rec]) ? $rec : null,
        ]);
        Audit::log('interview_feedback', 'candidate', (int) $a['candidate_id'], ['application' => $appId]);
        Http::flash('ok', 'Feedback saved.');
        Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
    }

    public function reference(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $referee = $this->str('referee', 160);
        if (!$referee) {
            Http::flash('error', 'Add the referee\'s name.');
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        $out = (string) ($_POST['outcome'] ?? '');
        $on = $this->str('checked_on', 10);
        $this->db->insert('reference_checks', [
            'application_id' => $appId, 'referee' => $referee, 'relation' => $this->str('relation', 160), 'contact' => $this->str('contact', 190),
            'outcome' => isset(self::OUTCOME[$out]) ? $out : null, 'summary' => $this->str('summary', 4000),
            'checked_on' => $on && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : date('Y-m-d'), 'user_id' => Auth::user()['id'],
        ]);
        Audit::log('reference_checked', 'candidate', (int) $a['candidate_id'], ['application' => $appId]);
        Http::flash('ok', 'Reference saved.');
        Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
    }

    /** The offer's details; the owner ticks "approved" (anyone else can prepare it). Changing an approved offer clears the approval. */
    public function offer(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $me = Auth::user();
        $old = $this->db->one('SELECT * FROM offer_approvals WHERE application_id = ?', [$appId]);
        $date = $this->str('joining_date', 10);
        $errs = [];
        $row = [
            'designation' => $this->str('designation', 160), 'ctc_lpa' => $this->lpa('ctc_lpa', $errs),
            'joining_date' => $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null, 'note' => $this->str('note', 500),
        ];
        if (!empty($errs)) {
            Http::flash('error', reset($errs));
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        // Staffing (phase 2): the monthly gross pay decides the cost, the bill, GST and the margin; the annual CTC follows from it.
        $isStaffing = $this->db->col('SELECT r.engagement FROM roles r WHERE r.id = ?', [$a['role_id']]) === 'staffing';
        $problems = [];
        if ($isStaffing) {
            $gross = is_numeric($g = trim(str_replace([',', '₹'], '', (string) ($_POST['gross_month'] ?? '')))) && (float) $g > 0 ? round((float) $g, 2) : null;
            $reason = $this->str('margin_reason', 255);
            [$problems, $calc, $terms] = (new \Eshcol\Ats\Services\Staffing($this->app))->check($appId, $gross, $reason);
            $row['gross_month'] = $gross;
            $row['margin_reason'] = $reason;
            $row['bill_month'] = $calc['bill'] ?? null;
            $row['cost_month'] = $calc['cost'] ?? null;
            $row['margin_month'] = $calc['margin'] ?? null;
            $row['calc'] = $calc ? json_encode($calc) : null;
            $row['client_terms_id'] = $terms['id'] ?? null;
            // The terms this offer was worked out on: the client's, or the position's own fee on top of them.
            $row['terms_used'] = $terms ? json_encode(array_intersect_key($terms, array_flip(['billing_model', 'markup_pct', 'markup_base', 'fixed_fee', 'gst_pct', 'wage_rule', 'payers', 'overridden']))) : null;
            if ($calc) {
                $row['ctc_lpa'] = $calc['ctc_lpa'];
            }
        }
        $changed = !$old || $old['designation'] !== $row['designation'] || (string) $old['ctc_lpa'] !== (string) $row['ctc_lpa'] || $old['joining_date'] !== $row['joining_date']
            || ($isStaffing && ((string) $old['gross_month'] !== (string) $row['gross_month'] || (string) $old['bill_month'] !== (string) $row['bill_month']));
        $approve = !empty($_POST['approve']) && $me['role'] === 'owner';
        if ($approve && $problems) {
            // Saved, but not approved: say exactly what stops it.
            $row += ['approved_by' => null, 'approved_at' => null];
            $old ? $this->db->run('UPDATE offer_approvals SET ' . implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row))) . ' WHERE application_id = ?', [...array_values($row), $appId])
                 : $this->db->insert('offer_approvals', $row + ['application_id' => $appId]);
            Http::flash('error', 'Saved, but not approved yet: ' . implode(' ', $problems));
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        if ($approve && (!$row['designation'] || $row['ctc_lpa'] === null || !$row['joining_date'])) {
            Http::flash('error', 'To approve, fill in the designation, the annual CTC and the joining date.');
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        if ($approve) {
            $row += ['approved_by' => $me['id'], 'approved_at' => date('Y-m-d H:i:s')];
        } elseif ($changed || !empty($_POST['unapprove'])) {
            $row += ['approved_by' => null, 'approved_at' => null];
        }
        if ($old) {
            $this->db->run('UPDATE offer_approvals SET ' . implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row))) . ' WHERE application_id = ?', [...array_values($row), $appId]);
        } else {
            $this->db->insert('offer_approvals', $row + ['application_id' => $appId]);
        }
        Audit::log($approve ? 'offer_approved' : 'offer_saved', 'candidate', (int) $a['candidate_id'], ['application' => $appId, 'ctc' => $row['ctc_lpa']]);
        Http::flash('ok', $approve ? 'Offer approved. You can now move them to "Offer made".' : 'Offer details saved' . ($old && $old['approved_at'] && $changed ? '; it needs approving again because the terms changed.' : '.'));
        Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
    }

    /** The joining checklist (own team and staffing): ticks and the date each document came in. Never the numbers. */
    public function joining(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $me = Auth::user();
        $on = (string) ($_POST['received_on'] ?? '');
        $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : date('Y-m-d');
        $ticked = array_intersect(array_map('strval', (array) ($_POST['items'] ?? [])), array_keys(\Eshcol\Ats\Services\Staffing::JOINING));
        $had = array_column($this->db->all('SELECT item FROM joining_items WHERE application_id = ?', [$appId]), 'item');
        foreach ($ticked as $item) {
            if (!in_array($item, $had, true)) {
                $this->db->insert('joining_items', ['application_id' => $appId, 'item' => $item, 'received_on' => $on, 'user_id' => $me['id']]);
                if ($item === 'consent') {
                    // DPDP: their details are now also used for employment with Eshcol, a purpose of its own.
                    $this->db->insert('consents', ['candidate_id' => (int) $a['candidate_id'], 'purpose' => 'employment', 'status' => 'given', 'via' => 'joining_checklist', 'note' => 'Received ' . $on]);
                }
            }
        }
        foreach (array_diff($had, $ticked) as $item) {
            $this->db->run('DELETE FROM joining_items WHERE application_id = ? AND item = ?', [$appId, $item]);
        }
        Audit::log('joining_checklist', 'candidate', (int) $a['candidate_id'], ['application' => $appId, 'items' => array_values($ticked)]);
        Http::flash('ok', 'Joining checklist saved.');
        Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
    }

    /** Own team and staffing: "Joined" only with every joining document received. */
    public static function joinBlocked(\Eshcol\Ats\App $app, int $appId, int $toStage): ?string
    {
        $db = $app->db;
        if (!\Eshcol\Ats\Services\Stages::is($app, $toStage, \Eshcol\Ats\Services\Stages::JOINED)) {
            return null;
        }
        $eng = (string) $db->col('SELECT r.engagement FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$appId]);
        if (!in_array($eng, ['inhouse', 'staffing'], true)) {
            return null;
        }
        $have = (int) $db->col('SELECT COUNT(*) FROM joining_items WHERE application_id = ?', [$appId]);
        $need = count(\Eshcol\Ats\Services\Staffing::JOINING);
        return $have >= $need ? null : "They join Eshcol's payroll, so every joining document must be in first: " . ($need - $have) . " of $need still to receive (the checklist under \"Decide\").";
    }

    /** Own team and staffing: may this application move to "Offer made"? Only with an approved offer. */
    public static function offerBlocked(\Eshcol\Ats\App $app, int $appId, int $toStage): ?string
    {
        $db = $app->db;
        if (!\Eshcol\Ats\Services\Stages::is($app, $toStage, \Eshcol\Ats\Services\Stages::OFFER)) {
            return null;
        }
        $eng = (string) $db->col('SELECT r.engagement FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$appId]);
        if (!in_array($eng, ['inhouse', 'staffing'], true)) {
            return null;
        }
        return $db->col('SELECT 1 FROM offer_approvals WHERE application_id = ? AND approved_at IS NOT NULL', [$appId])
            ? null : 'Eshcol makes this offer, so it needs your approval first: fill in the offer under "Decide" on the candidate and tick Approved.';
    }
}
