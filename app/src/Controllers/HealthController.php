<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Consents;
use Eshcol\Ats\Services\EmailHealth;

/** Bounces (Brevo's webhook), a candidate's email status and consent, and the "download their data" file (14 Nov 2026). */
final class HealthController extends Controller
{
    /** One-click unsubscribe, opened from the link: a page with one button (so a link scanner can't unsubscribe them). */
    public function unsubPage(string|int $token): never
    {
        $cid = EmailHealth::unsubscribeCandidate($this->app, (string) $token);
        $this->publicPage($cid ? 'Unsubscribe' : 'This link isn\'t valid', $cid
            ? 'Press the button and we will stop emailing you. Your details stay with us unless you ask us to remove them.'
            : 'Please check you copied the whole link. You can also reply to our email with "Unsubscribe".', $cid ? (string) $token : null, $cid ? 200 : 404);
    }

    /** The unsubscribe itself: from the button above, or posted directly by Gmail, Yahoo and others (RFC 8058). */
    public function unsub(string|int $token): never
    {
        $cid = EmailHealth::unsubscribeCandidate($this->app, (string) $token);
        if ($cid) {
            (new EmailHealth($this->app))->unsubscribe($cid);
        }
        if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
            http_response_code($cid ? 200 : 404); // a mail provider: no page needed
            exit;
        }
        $this->publicPage($cid ? 'You are unsubscribed' : 'This link isn\'t valid', $cid
            ? 'We will not email you again. If you apply for a role with us in future, we will start afresh.'
            : 'Please check you copied the whole link.', null, $cid ? 200 : 404);
    }

    private function publicPage(string $heading, string $body, ?string $token, int $code): never
    {
        http_response_code($code);
        echo \Eshcol\Ats\View::render('health/unsub', ['heading' => $heading, 'body' => $body, 'token' => $token, 'title' => 'Eshcol Global'], 'layout/public');
        exit;
    }

    /** Brevo calls this with each event. The long secret in the address is the only way in. */
    public function brevoHook(string|int $token): never
    {
        $h = new EmailHealth($this->app);
        if (!$h->validToken((string) $token)) {
            Http::json(['ok' => false], 404);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 1048576);
        $payload = json_decode($raw, true);
        $n = is_array($payload) ? $h->record($payload) : 0;
        Http::json(['ok' => true, 'recorded' => $n]);
    }

    public function connectBrevo(): never
    {
        Auth::requireOwner();
        $problem = (new EmailHealth($this->app))->connectBrevo();
        Http::flash($problem ? 'error' : 'ok', $problem ?: 'Connected: Brevo will now tell the app about bounces, blocks, spam complaints and unsubscribes.');
        Http::redirect('/settings#email');
    }

    public function clearEmail(int $id): never
    {
        $this->findOr404('candidates', $id);
        (new EmailHealth($this->app))->clear($id);
        Http::flash('ok', 'Emails will go to this address again.');
        Http::redirect("/candidates/$id#contact-consent");
    }

    /** Everything held about one person, as a file, for a request to see their data (DPDP Act). Owners only. */
    public function exportData(int $id): never
    {
        Auth::requireOwner();
        $data = (new Consents($this->app))->export($id);
        $data || Http::abort(404);
        Audit::log('candidate_data_exported', 'candidate', $id);
        header_remove('Content-Security-Policy');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . ref_candidate($id) . ' - data held by Eshcol Global.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
