<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\ClientPortal;
use Eshcol\Ats\Services\Shortlists;
use Eshcol\Ats\View;

/**
 * The client portal's pages (13 Nov 2026). Every route is "public" to the router (no staff sign-in);
 * each one checks the client's own sign-in here, and every query is limited to that client's company.
 */
final class PortalController extends Controller
{
    private function cookie(): string
    {
        return Http::cookieName($this->app, 'eshcol_cp_');
    }

    private function svc(): ClientPortal
    {
        return new ClientPortal($this->app);
    }

    /** The signed-in contact, or off to the sign-in page. */
    private function me(): array
    {
        $c = $this->svc()->contactBySession((string) ($_COOKIE[$this->cookie()] ?? ''));
        if (!$c) {
            Http::redirect('/portal');
        }
        return $c;
    }

    private function page(string $template, array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        echo View::render($template, $data, 'layout/portal');
        exit;
    }

    private function roleOr404(array $me, int $id): array
    {
        $r = $this->svc()->role($me, $id);
        if (!$r) {
            $this->page('portal/message', ['title' => 'Client portal', 'me' => $me, 'heading' => 'That position isn\'t here', 'body' => 'It may have been closed. Go back to your positions.'], 404);
        }
        return $r;
    }

    // ---------------------------------------------------------------- signing in

    public function home(): never
    {
        $c = $this->svc()->contactBySession((string) ($_COOKIE[$this->cookie()] ?? ''));
        if (!$c) {
            $this->page('portal/signin', ['title' => 'Client portal', 'me' => null, 'sent' => false, 'ended' => isset($_GET['ended']), 'error' => null]);
        }
        $this->page('portal/home', ['title' => $c['client'] . ' · Client portal', 'me' => $c, 'roles' => $this->svc()->roles($c)]);
    }

    /** "Email me a code" (20 Nov 2026): the same page whether or not the address is known. The address rides in the form, never the URL. */
    public function link(): never
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->page('portal/signin', ['title' => 'Client portal', 'me' => null, 'sent' => false, 'ended' => false, 'error' => 'Enter your email address.'], 422);
        }
        $this->svc()->requestCode($email, Http::ip());
        $this->page('portal/code', ['title' => 'Client portal', 'me' => null, 'email' => $email, 'error' => null]);
    }

    /** The 6-digit code: right, and you are in for 30 days on this device. */
    public function code(): never
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $session = $this->svc()->verifyCode($email, (string) ($_POST['code'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!$session) {
            $this->page('portal/code', ['title' => 'Client portal', 'me' => null, 'email' => $email,
                'error' => 'That code is not right, or it has expired. Check the latest email from us, or ask for a new code.'], 422);
        }
        $this->setSession($session, ClientPortal::SESSION_DAYS * 86400);
        Http::redirect('/portal');
    }

    private function setSession(string $session, int $seconds): void
    {
        setcookie($this->cookie(), $session, ['expires' => time() + $seconds, 'path' => '/', 'secure' => !$this->app->isLocal(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** In a preview (the owner looking as the client), nothing is saved. */
    private function notInPreview(array $me, string $back): void
    {
        if (!empty($me['preview_by'])) {
            Http::flash('error', 'This is a preview of what your client sees: nothing you press here is saved.');
            Http::redirect($back);
        }
    }

    /** Opening the link shows a button; pressing it signs in (so an email scanner doesn't use the link up). */
    public function open(string|int $token): never
    {
        $c = $this->svc()->peek((string) $token);
        if (!$c) {
            $this->page('portal/message', ['title' => 'Client portal', 'me' => null, 'heading' => 'This sign-in link can\'t be used',
                'body' => 'It has been used already, or it has expired. Enter your email address and we will send you a fresh one.', 'signin' => true], 410);
        }
        $this->page('portal/open', ['title' => 'Client portal', 'me' => null, 'contact' => $c, 'token' => (string) $token]);
    }

    public function enter(string|int $token): never
    {
        $session = $this->svc()->consume((string) $token, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!$session) {
            Http::redirect('/portal/in/' . rawurlencode((string) $token));
        }
        $this->setSession($session, ClientPortal::SESSION_DAYS * 86400);
        Http::redirect('/portal');
    }

    public function out(): never
    {
        $this->svc()->signOut((string) ($_COOKIE[$this->cookie()] ?? ''));
        setcookie($this->cookie(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !$this->app->isLocal(), 'httponly' => true, 'samesite' => 'Lax']);
        Http::redirect('/portal?ended=1');
    }

    public function logo(): never
    {
        $me = $this->me();
        (new \Eshcol\Ats\Services\ClientLogos($this->app))->send((int) $me['client_id']);
    }

    // ---------------------------------------------------------------- a position

    public function role(int $id): never
    {
        $me = $this->me();
        $role = $this->roleOr404($me, $id);
        $svc = $this->svc();
        if (empty($me['preview_by'])) {
            $svc->markViewed($me, $id);
        }
        $this->page('portal/role', ['title' => $role['title'] . ' · Client portal', 'me' => $me, 'role' => $role, 'cands' => $svc->candidates($me, $id),
            'schedule' => $svc->schedule($me, $id)]);
    }

    public function decide(int $id): never
    {
        $me = $this->me();
        $this->roleOr404($me, $id);
        $this->notInPreview($me, "/portal/roles/$id");
        $n = $this->svc()->saveDecisions($me, $id, $_POST);
        Http::flash('ok', $n ? "Thank you: your feedback on $n candidate" . ($n === 1 ? '' : 's') . ' has reached us. We will be in touch about next steps.' : 'Nothing was changed.');
        Http::redirect("/portal/roles/$id");
    }

    /** One candidate's CV: the Eshcol-branded PDF, or their profile page as a PDF. */
    public function cv(int $id, int $item): never
    {
        $me = $this->me();
        $this->roleOr404($me, $id);
        $it = $this->svc()->item($me, $id, $item);
        if (!$it || !$it['shortlist']) {
            Http::abort(404);
        }
        $s = $it['shortlist'];
        $sl = new Shortlists($this->app);
        Audit::log('portal_cv_opened', 'shortlist', (int) $s['id'], ['contact' => (int) $me['id']]);
        $name = pathinfo($sl->cvFileName($it), PATHINFO_FILENAME);
        if ($s['mode'] === 'cvs' && $it['branded_path'] && is_file($p = $this->app->storage($it['branded_path']))) {
            $this->sendPdf((string) file_get_contents($p), $name);
        }
        $this->sendPdf($sl->pdf($s, [$it]), $name);
    }

    private function sendPdf(string $pdf, string $name): never
    {
        header_remove('Content-Security-Policy');
        header('Content-Type: application/pdf');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $name) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ---------------------------------------------------------------- interview times

    public function times(int $id): never
    {
        $me = $this->me();
        $this->roleOr404($me, $id);
        $this->notInPreview($me, "/portal/roles/$id#times");
        [$n, $problem] = $this->svc()->offerTimes($me, $id, $_POST);
        Http::flash($n ? 'ok' : 'error', $n ? "Thank you: $n interview time" . ($n === 1 ? ' is' : 's are') . ' with us. We will offer ' . ($n === 1 ? 'it' : 'them') . ' to the candidates you shortlist and confirm each interview with you.' : $problem);
        Http::redirect("/portal/roles/$id#times");
    }

    public function removeSlot(int $slot): never
    {
        $me = $this->me();
        $this->notInPreview($me, '/portal');
        $roleId = $this->svc()->removeSlot($me, $slot);
        if ($roleId === null) {
            Http::abort(404);
        }
        Http::flash('ok', 'That time is withdrawn.');
        Http::redirect("/portal/roles/$roleId#times");
    }

    // ---------------------------------------------------------------- asking for a position

    public function newForm(): never
    {
        $me = $this->me();
        $this->page('portal/new', ['title' => 'Ask for a new position · Client portal', 'me' => $me, 'old' => [], 'error' => null]);
    }

    public function newSave(): never
    {
        $me = $this->me();
        $this->notInPreview($me, '/portal/new');
        $r = $this->svc()->requestPosition($me, $_POST);
        if (is_string($r)) {
            $this->page('portal/new', ['title' => 'Ask for a new position · Client portal', 'me' => $me, 'old' => $_POST, 'error' => $r], 422);
        }
        $this->page('portal/message', ['title' => 'Client portal', 'me' => $me, 'heading' => 'Thank you: we have your request',
            'body' => 'We will check the details, come back to you with any questions, and start the search. The position appears under "Your positions" as "Requested" until we open it.']);
    }
}
