<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\View;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Shortlists;

final class ShortlistController extends Controller
{
    // ---- Your side ----

    /** Choose candidates, write a line about each, and address it. */
    public function create(int $roleId): never
    {
        $role = $this->role($roleId);
        $apps = $this->db->all("
            SELECT a.id, a.candidate_id, a.stage_id, COALESCE(a.override_score, a.score) AS eff_score, s.name AS stage, s.sort AS stage_sort,
                   c.full_name, c.current_title, c.location, c.exp_months, c.exp_as_of, c.do_not_contact,
                   (SELECT i.decision FROM shortlist_items i WHERE i.application_id = a.id ORDER BY i.id DESC LIMIT 1) AS last_decision,
                   (SELECT COUNT(*) FROM shortlist_items i WHERE i.application_id = a.id) AS sent_before
            FROM applications a JOIN stages s ON s.id = a.stage_id JOIN candidates c ON c.id = a.candidate_id
            WHERE a.role_id = ? AND s.kind = 'active' AND " . \Eshcol\Ats\Services\Contact::putForwardSql('c') . "
              AND " . \Eshcol\Ats\Services\ClientRejections::notRejectedSql($this->app, 'c', (string) (int) $role['client_id']) . "
            ORDER BY s.sort DESC, eff_score DESC LIMIT 100", [$roleId]);
        $svc = new Shortlists($this->app);
        $remarks = $svc->autoRemarks(array_column($apps, 'id'));
        foreach ($apps as &$a) {
            $a['summary'] = $remarks[(int) $a['id']] ?? '';
            $a['preselect'] = \Eshcol\Ats\Services\Stages::is($this->app, (int) $a['stage_id'], \Eshcol\Ats\Services\Stages::SHORTLISTED) && !$a['sent_before'];
        }
        $contact = $this->db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$role['client_id']]) ?: ['name' => '', 'email' => ''];
        $this->view('shortlist/create', ['title' => 'Send a shortlist · ' . $role['title'], 'role' => $role, 'apps' => $apps, 'contact' => $contact, 'error' => null, 'old' => []]);
    }

    public function store(int $roleId): never
    {
        $role = $this->role($roleId);
        [$items, $error] = $this->chosen($roleId);
        $to = mb_strtolower(trim((string) ($_POST['to_email'] ?? '')));
        $cc = trim((string) ($_POST['cc'] ?? ''));
        $error ??= match (true) {
            !filter_var($to, FILTER_VALIDATE_EMAIL) => 'Enter the client\'s email address.',
            !Mailer::deliverable($to) => 'The client\'s email domain can\'t receive email. Check it for a typo.',
            (bool) array_filter(preg_split('/[,;\s]+/', $cc, -1, PREG_SPLIT_NO_EMPTY), fn ($e) => !filter_var($e, FILTER_VALIDATE_EMAIL)) => 'One of the CC addresses is not valid.',
            default => null,
        };
        if ($error) {
            Http::flash('error', $error);
            Http::redirect("/roles/$roleId/shortlist/new");
        }
        $id = (new Shortlists($this->app))->create($roleId, $items, trim((string) ($_POST['to_name'] ?? '')), $to, $cc,
            trim((string) ($_POST['note'] ?? '')), isset($_POST['show_salary']), (int) Auth::user()['id'], ($_POST['mode'] ?? '') === 'profiles' ? 'profiles' : 'cvs');
        Http::redirect("/shortlists/$id");
    }

    /** The PDF exactly as the client would get it, before sending. */
    public function preview(int $roleId): never
    {
        $role = $this->role($roleId);
        [$items, $error] = $this->chosen($roleId);
        if ($error) {
            Http::flash('error', $error);
            Http::redirect("/roles/$roleId/shortlist/new");
        }
        $svc = new Shortlists($this->app);
        $rows = array_map(fn ($x) => ['summary' => $x[1], 'p' => $svc->profile((int) $x[0], isset($_POST['show_salary']))], $items);
        $this->sendPdf($svc->pdf($role, $rows), 'Preview - ' . $role['title']);
    }

    public function show(int $id): never
    {
        $s = $this->db->one('SELECT s.*, r.title, r.code, r.client_id, c.name AS client, u.name AS sender FROM shortlists s JOIN roles r ON r.id = s.role_id
                             JOIN clients c ON c.id = r.client_id LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
        $s || Http::abort(404);
        $svc = new Shortlists($this->app);
        if ($s['status'] === 'draft') {
            [$subject] = $svc->emailContent($id);
            $this->view('shortlist/draft', ['title' => 'Check and send · ' . $s['title'], 's' => $s, 'items' => $svc->items($id), 'subject' => $subject,
                'checks' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? [] : (new \Eshcol\Ats\Services\SubmissionChecks($this->app))->forShortlist($id)]);
        }
        $this->view('shortlist/show', ['title' => 'Shortlist · ' . $s['title'], 's' => $s, 'items' => $svc->items($id), 'link' => $svc->link($id),
            'email' => $s['email_id'] ? $this->db->one('SELECT status, sent_at, last_error FROM email_queue WHERE id = ?', [$s['email_id']]) : null]);
    }

    /** The email as the client will see it (for the preview frame). */
    public function emailPreview(int $id): never
    {
        $this->draftOr404($id, false);
        [, $html] = (new Shortlists($this->app))->emailContent($id);
        header_remove('X-Frame-Options');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'; form-action 'none'; base-uri 'none'");
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /** "Chased": you followed up with the client; the reminder comes back after another 5 working days. */
    public function chased(int $id): never
    {
        (new \Eshcol\Ats\Services\SubmissionChecks($this->app))->chased($id);
        Http::flash('ok', 'Noted. If the client still hasn\'t decided in 5 working days, it shows on My day again.');
        Http::redirect((string) ($_POST['back'] ?? '') === 'day' ? '/' : "/shortlists/$id");
    }

    public function send(int $id): never
    {
        // Checks before it goes (B11): warnings need a short reason; an earlier send to this client needs a tick.
        if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
            $checks = new \Eshcol\Ats\Services\SubmissionChecks($this->app);
            $warnings = $checks->forShortlist($id);
            if ($warnings) {
                $reason = $this->str('check_reason', 300);
                $needsConfirm = (bool) array_filter($warnings, fn ($l) => (bool) array_filter($l, fn ($x) => $x[1]));
                if (!$reason || ($needsConfirm && empty($_POST['confirm_resend']))) {
                    Http::flash('error', !$reason ? 'Some candidates need a second look (listed below). Add a short reason to send anyway.'
                        : 'Tick the box to confirm sending someone this client has already seen.');
                    Http::redirect("/shortlists/$id#checks");
                }
                $checks->recordReasons($id, $warnings, $reason);
            }
        }
        $err = (new Shortlists($this->app))->send($id, (int) Auth::user()['id']);
        if ($err) {
            Http::flash('error', $err);
            Http::redirect("/shortlists/$id");
        }
        $s = $this->db->one('SELECT to_email FROM shortlists WHERE id = ?', [$id]);
        Http::flash('ok', 'Sent to ' . $s['to_email'] . '. The candidates moved to "Submitted to client". You will get an email when the client replies.');
        Http::redirect("/shortlists/$id");
    }

    public function discard(int $id): never
    {
        $s = $this->draftOr404($id);
        foreach ($this->db->all('SELECT branded_path FROM shortlist_items WHERE shortlist_id = ? AND branded_path IS NOT NULL', [$id]) as $r) {
            @unlink($this->app->storage($r['branded_path']));
        }
        $this->db->run('DELETE FROM shortlists WHERE id = ?', [$id]);
        Http::flash('info', 'Draft discarded. Nothing was sent.');
        Http::redirect('/roles/' . (int) $s['role_id']);
    }

    // ---- Building the branded CVs (called by shortlist-cv.js on the draft page) ----

    public function cvPlan(int $id, int $item): never
    {
        [$s, $it] = $this->item($id, $item);
        $src = (new Shortlists($this->app))->cvSource($item);
        $ext = $src[1] ?? '';
        [$head, $foot] = (new Shortlists($this->app))->stampText($s, $it);
        Http::json(['type' => $ext === 'pdf' ? 'pdf' : ($ext === 'docx' ? 'docx' : 'other'),
            'source_url' => "/shortlists/$id/items/$item/source", 'cover_url' => "/shortlists/$id/items/$item/cover", 'logo_url' => asset('logo.png'),
            'head' => $head, 'foot' => $foot, 'title' => (new Shortlists($this->app))->cvFileName($it)]);
    }

    public function cvCover(int $id, int $item): never
    {
        [$s, $it] = $this->item($id, $item);
        $this->sendPdf((new Shortlists($this->app))->coverPdf($s, $it), 'Cover');
    }

    public function cvSource(int $id, int $item): never
    {
        $this->item($id, $item);
        $src = (new Shortlists($this->app))->cvSource($item);
        $src || Http::abort(404);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($src[0]));
        readfile($src[0]);
        exit;
    }

    /** Receives a CV stamped in the browser. */
    public function cvBranded(int $id, int $item): never
    {
        $this->item($id, $item);
        $f = $_FILES['pdf'] ?? null;
        $pdf = $f && $f['error'] === UPLOAD_ERR_OK && $f['size'] <= 25 * 1048576 ? (string) file_get_contents($f['tmp_name']) : '';
        if (!str_starts_with($pdf, '%PDF')) {
            Http::json(['ok' => false, 'message' => 'The prepared CV did not arrive. Try again.'], 422);
        }
        (new Shortlists($this->app))->storeBranded($item, $pdf);
        Http::json(['ok' => true, 'kb' => (int) ceil(strlen($pdf) / 1024), 'how' => 'original layout kept']);
    }

    /** Builds a CV on the server: from the HTML of a Word file, or from the CV's text. */
    public function cvRender(int $id, int $item): never
    {
        [$s, $it] = $this->item($id, $item);
        @set_time_limit(120);
        $html = isset($_POST['html']) ? (string) $_POST['html'] : null;
        try {
            $pdf = (new Shortlists($this->app))->brandedFromContent($s, $it, $html);
        } catch (\Throwable $e) {
            Http::json(['ok' => false, 'message' => 'Could not lay out this CV: ' . mb_substr($e->getMessage(), 0, 120)], 500);
        }
        (new Shortlists($this->app))->storeBranded($item, $pdf);
        Http::json(['ok' => true, 'kb' => (int) ceil(strlen($pdf) / 1024), 'how' => $html !== null ? 'converted from Word' : 'rebuilt from the CV text']);
    }

    public function cvView(int $id, int $item): never
    {
        [, $it] = $this->item($id, $item, false);
        if (!$it['branded_path'] || !is_file($p = $this->app->storage($it['branded_path']))) {
            Http::abort(404);
        }
        $this->sendPdf((string) file_get_contents($p), pathinfo((new Shortlists($this->app))->cvFileName($it), PATHINFO_FILENAME));
    }

    /** @return array{0: array, 1: array} the shortlist and the item */
    private function item(int $id, int $item, bool $draftOnly = true): array
    {
        $s = $this->draftOr404($id, $draftOnly);
        $it = $this->db->one('SELECT * FROM shortlist_items WHERE id = ? AND shortlist_id = ?', [$item, $id]);
        $it || Http::abort(404);
        $it['p'] = json_decode((string) $it['profile'], true) ?: [];
        return [$s, $it];
    }

    private function draftOr404(int $id, bool $draftOnly = true): array
    {
        $s = $this->db->one('SELECT s.*, r.title, r.code, r.location FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$id]);
        if (!$s || ($draftOnly && $s['status'] !== 'draft')) {
            Http::abort(404);
        }
        return $s;
    }

    public function pdf(int $id): never
    {
        $s = $this->db->one('SELECT s.*, r.title, r.code FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$id]);
        $s || Http::abort(404);
        $svc = new Shortlists($this->app);
        $this->sendPdf($svc->pdf($s, $svc->items($id)), 'Shortlist - ' . $s['title']);
    }

    public function resend(int $id): never
    {
        $s = $this->db->one("SELECT * FROM shortlists WHERE id = ? AND status <> 'closed'", [$id]);
        $s || Http::abort(404);
        (new Shortlists($this->app))->email($id, (int) Auth::user()['id']);
        Http::flash('ok', 'The email was sent again to ' . $s['to_email'] . ', with the same link.');
        Http::redirect("/shortlists/$id");
    }

    public function close(int $id): never
    {
        $this->db->run("UPDATE shortlists SET status = 'closed' WHERE id = ?", [$id]);
        Audit::log('shortlist_closed', 'shortlist', $id);
        Http::flash('ok', 'Link closed: the client can no longer open it.');
        Http::redirect("/shortlists/$id");
    }

    /** @return array{0: array<int, array{0:int,1:string}>, 1: ?string} */
    private function chosen(int $roleId): array
    {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $items = [];
        foreach ($ids as $appId) {
            if ($this->db->col("SELECT a.id FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id WHERE a.id = ? AND a.role_id = ? AND " . \Eshcol\Ats\Services\Contact::putForwardSql('c') . " AND " . \Eshcol\Ats\Services\ClientRejections::notRejectedSql($this->app, 'c', 'r.client_id'), [$appId, $roleId])) {
                $items[] = [$appId, (string) ($_POST['summary'][$appId] ?? '')];
            }
        }
        return [$items, $items ? null : 'Tick at least one candidate.'];
    }

    private function role(int $id): array
    {
        $r = $this->db->one('SELECT r.*, c.name AS client FROM roles r JOIN clients c ON c.id = r.client_id WHERE r.id = ? AND r.is_pool = 0', [$id]);
        $r || Http::abort(404);
        return $r;
    }

    private function sendPdf(string $pdf, string $name): never
    {
        header_remove('Content-Security-Policy');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $name) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ---- The client's side (public, reached only through the private link) ----

    public function clientView(string|int $token): never
    {
        $this->clientPage((string) $token, false);
    }

    public function clientSave(string|int $token): never
    {
        $this->clientPage((string) $token, true);
    }

    public function clientPdf(string|int $token): never
    {
        $svc = new Shortlists($this->app);
        $s = $this->open((string) $token);
        Audit::log('shortlist_pdf_downloaded', 'shortlist', (int) $s['id']);
        $this->sendPdf($svc->pdf($s, $svc->items((int) $s['id'])), 'Candidates - ' . $s['title']);
    }

    /** One branded CV (or, for a profiles shortlist, that candidate's profile). */
    public function clientCv(string|int $token, int $item): never
    {
        $svc = new Shortlists($this->app);
        $s = $this->open((string) $token);
        $it = $this->db->one('SELECT * FROM shortlist_items WHERE id = ? AND shortlist_id = ?', [$item, $s['id']]);
        $it || Http::abort(404);
        $it['p'] = json_decode((string) $it['profile'], true) ?: [];
        Audit::log('shortlist_cv_downloaded', 'shortlist', (int) $s['id']);
        if ($s['mode'] === 'cvs' && $it['branded_path'] && is_file($p = $this->app->storage($it['branded_path']))) {
            $this->sendPdf((string) file_get_contents($p), pathinfo($svc->cvFileName($it), PATHINFO_FILENAME));
        }
        $this->sendPdf($svc->pdf($s, [$it]), pathinfo($svc->cvFileName($it), PATHINFO_FILENAME));
    }

    /** All the branded CVs as one ZIP. */
    public function clientZip(string|int $token): never
    {
        $svc = new Shortlists($this->app);
        $s = $this->open((string) $token);
        $tmp = $this->app->storage('uploads-tmp/zip-' . bin2hex(random_bytes(8)) . '.zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        foreach ($svc->items((int) $s['id']) as $it) {
            if ($it['branded_path'] && is_file($p = $this->app->storage($it['branded_path']))) {
                $zip->addFile($p, $svc->cvFileName($it));
            }
        }
        $zip->close();
        Audit::log('shortlist_zip_downloaded', 'shortlist', (int) $s['id']);
        header_remove('Content-Security-Policy');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $s['title']) . ' - CVs - Eshcol Global.zip"');
        header('Content-Length: ' . (is_file($tmp) ? filesize($tmp) : 0));
        if (is_file($tmp)) {
            readfile($tmp);
            @unlink($tmp);
        }
        exit;
    }

    private function clientPage(string $token, bool $saving): never
    {
        $svc = new Shortlists($this->app);
        $s = $this->open($token);
        $svc->markViewed($s);
        $saved = null;
        if ($saving) {
            $saved = $svc->saveFeedback($s, $_POST);
        }
        http_response_code(200);
        echo View::render('shortlist/client', ['title' => 'Candidates for ' . $s['title'], 's' => $s, 'items' => $svc->items((int) $s['id']),
            'token' => $token, 'saved' => $saved], 'layout/public');
        exit;
    }

    /** The shortlist behind a link, or a friendly page saying why it can't be opened. */
    private function open(string $token): array
    {
        $s = (new Shortlists($this->app))->byToken($token);
        $msg = function (string $h, string $b, int $code) {
            http_response_code($code);
            echo View::render('questionnaire/message', ['heading' => $h, 'body' => $b, 'title' => 'Eshcol Global'], 'layout/public');
            exit;
        };
        if (!$s) {
            $msg('This link isn\'t valid', 'Please check you copied the whole link from the email. If it still doesn\'t work, reply to the email and we will send it again.', 404);
        }
        if ($s['status'] === 'draft') {
            $msg('This link isn\'t ready yet', 'Please use the link in the email we send you.', 404);
        }
        if ($s['status'] === 'closed') {
            $msg('This shortlist has been closed', 'Please reply to our email if you would like to see these candidates again.', 410);
        }
        if (strtotime($s['expires_at']) < time()) {
            $msg('This link has expired', 'Reply to our email and we will send you a fresh link.', 410);
        }
        return $s;
    }

    /** The client's own logo, on their shortlist page. */
    public function clientLogo(string|int $token): never
    {
        $s = (new Shortlists($this->app))->byToken((string) $token);
        $s || Http::abort(404);
        (new \Eshcol\Ats\Services\ClientLogos($this->app))->send((int) $s['client_id']);
    }
}
