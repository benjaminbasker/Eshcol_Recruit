<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\Mailer;

/** Outgoing email: what waits for you, what is queued, what went out. */
final class EmailController extends Controller
{
    public function index(): never
    {
        $tab = in_array($_GET['tab'] ?? '', ['held', 'pending', 'sent', 'failed', 'unsure'], true) ? $_GET['tab'] : null;
        $counts = array_column($this->db->all("SELECT status, COUNT(*) n FROM email_queue WHERE status <> 'cancelled' GROUP BY status"), 'n', 'status');
        $tab ??= ($counts['held'] ?? 0) ? 'held' : 'pending';
        $order = $tab === 'sent' ? 'e.sent_at DESC' : ($tab === 'pending' ? 'e.priority, e.not_before' : 'e.id DESC');
        $rows = $this->db->all("SELECT e.id, e.kind, e.candidate_id, e.to_email, e.to_name, e.subject, e.status, e.hold_reason, e.not_before,
                                       e.attempts, e.last_error, e.created_at, e.sent_at, c.full_name
                                FROM email_queue e LEFT JOIN candidates c ON c.id = e.candidate_id
                                WHERE e.status = ? ORDER BY $order LIMIT 200", [$tab]);
        $m = new Mailer($this->app);
        $this->view('emails/index', ['title' => 'Emails', 'tab' => $tab, 'rows' => $rows, 'counts' => $counts,
            'sentToday' => $m->sentToday(), 'cap' => $m->dailyCap(), 'live' => $m->live()]);
    }

    /** The email itself, for the preview frame. Served with a locked-down policy: no scripts, nothing fetched but images. */
    public function body(int $id): never
    {
        $html = $this->db->col('SELECT html FROM email_queue WHERE id = ?', [$id]);
        if ($html === false || $html === null) {
            Http::abort(404);
        }
        header_remove('X-Frame-Options');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'; form-action 'none'; base-uri 'none'");
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public function show(int $id): never
    {
        $e = $this->db->one('SELECT e.*, c.full_name FROM email_queue e LEFT JOIN candidates c ON c.id = e.candidate_id WHERE e.id = ?', [$id]);
        $e || Http::abort(404);
        $this->view('emails/show', ['title' => $e['subject'], 'e' => $e]);
    }

    /** Send a held email: as it is, or to a corrected address. */
    public function release(int $id): never
    {
        $e = $this->db->one("SELECT * FROM email_queue WHERE id = ? AND status = 'held'", [$id]);
        $e || Http::abort(404);
        $to = mb_strtolower(trim((string) ($_POST['to_email'] ?? $e['to_email'])));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || ($to !== $e['to_email'] && !Mailer::deliverable($to))) {
            Http::flash('error', 'That email address is not valid, or its domain can\'t receive email. Check it for a typo.');
            Http::redirect("/emails/$id");
        }
        if ($to !== $e['to_email'] && $e['candidate_id']) {
            // A corrected address is a confirmed one: store it on the candidate too.
            $c = $this->db->one('SELECT field_sources FROM candidates WHERE id = ?', [$e['candidate_id']]);
            $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
            $src['email'] = 'you';
            $this->db->run('UPDATE candidates SET email = ?, email_norm = ?, field_sources = ? WHERE id = ?', [$to, $to, json_encode($src), $e['candidate_id']]);
        }
        $this->db->run("UPDATE email_queue SET to_email = ?, status = 'pending', hold_reason = NULL, not_before = NOW() WHERE id = ?", [$to, $id]);
        if ($e['kind'] === 'ack') {
            (new Acknowledger($this->app))->rerender($id);
        }
        Audit::log('email_released', 'email', $id, ['changed_address' => $to !== $e['to_email']]);
        Http::flash('ok', 'Approved. It goes out with the next send, within 5 minutes.');
        Http::redirect('/emails?tab=held');
    }

    public function cancel(int $id): never
    {
        $this->db->run("UPDATE email_queue SET status = 'cancelled' WHERE id = ? AND status IN ('held','pending','failed','unsure')", [$id]);
        Audit::log('email_cancelled', 'email', $id);
        Http::flash('ok', 'Cancelled. Nothing will be sent.');
        Http::redirect('/emails?tab=' . (in_array($_POST['back'] ?? '', ['held', 'pending', 'failed', 'unsure'], true) ? $_POST['back'] : 'held'));
    }

    public function retry(int $id): never
    {
        $was = (string) $this->db->col('SELECT status FROM email_queue WHERE id = ?', [$id]);
        $this->db->run("UPDATE email_queue SET status = 'pending', attempts = 0, not_before = NOW() WHERE id = ? AND status IN ('failed','unsure')", [$id]);
        Audit::log('email_retried', 'email', $id, ['was' => $was]);
        Http::flash('ok', 'Queued again. It goes out with the next send.');
        Http::redirect('/emails?tab=' . ($was === 'unsure' ? 'unsure' : 'failed'));
    }

    /** Owner: approve or cancel every held email at once. */
    public function bulk(): never
    {
        Auth::requireOwner();
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!$ids) {
            Http::flash('info', 'Nothing selected.');
            Http::redirect('/emails?tab=held');
        }
        $in = implode(',', $ids);
        if (($_POST['do'] ?? '') === 'release') {
            $n = $this->db->run("UPDATE email_queue SET status = 'pending', hold_reason = NULL, not_before = NOW() WHERE status = 'held' AND id IN ($in)")->rowCount();
            Http::flash('ok', "$n approved as they are.");
        } else {
            $n = $this->db->run("UPDATE email_queue SET status = 'cancelled' WHERE status = 'held' AND id IN ($in)")->rowCount();
            Http::flash('ok', "$n cancelled.");
        }
        Audit::log('emails_bulk', null, null, ['do' => $_POST['do'] ?? '', 'count' => $n]);
        Http::redirect('/emails?tab=held');
    }
}
