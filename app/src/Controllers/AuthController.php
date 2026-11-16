<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Totp;
use Eshcol\Ats\View;

final class AuthController extends Controller
{
    public function showLogin(): never
    {
        $m = new \Eshcol\Ats\Services\Migrator($this->app);
        if (!$m->isInstalled() || (int) $this->db->col('SELECT COUNT(*) FROM users') === 0) {
            Http::redirect('/setup');
        }
        if (Auth::user()) {
            Http::redirect('/');
        }
        echo View::render('auth/login', ['error' => null, 'email' => ''], 'layout/bare');
        exit;
    }

    public function login(): never
    {
        $email = mb_strtolower((string) Http::input('email'));
        $error = Auth::attemptPassword($email, (string) ($_POST['password'] ?? ''));
        if ($error) {
            echo View::render('auth/login', ['error' => $error, 'email' => $email], 'layout/bare');
            exit;
        }
        $uid = (int) $_SESSION['pending_uid'];
        $u = $this->db->one('SELECT totp_enabled FROM users WHERE id = ?', [$uid]);
        if ($u['totp_enabled'] && Auth::isTrustedDevice($uid)) {
            Auth::complete($uid, true);
            Http::redirect('/');
        }
        Http::redirect($u['totp_enabled'] ? '/login/code' : '/login/setup');
    }

    public function showCode(): never
    {
        $this->needPending();
        echo View::render('auth/code', ['error' => null], 'layout/bare');
        exit;
    }

    public function code(): never
    {
        $uid = $this->needPending();
        if (Auth::attemptCode((string) Http::input('code'))) {
            if (!empty($_POST['trust'])) {
                Auth::trustDevice($uid);
            }
            Http::redirect('/');
        }
        echo View::render('auth/code', ['error' => 'That code is not right, or it has expired. Codes change every 30 seconds.'], 'layout/bare');
        exit;
    }

    /** First sign-in: set up the authenticator app. */
    public function showSetup(): never
    {
        $uid = $this->needPending();
        $u = $this->db->one('SELECT email, totp_enabled FROM users WHERE id = ?', [$uid]);
        if ($u['totp_enabled']) {
            Http::redirect('/login/code');
        }
        if (($_SESSION['setup_uid'] ?? null) !== $uid || empty($_SESSION['setup_secret'])) {
            $_SESSION['setup_secret'] = Totp::newSecret();
            $_SESSION['setup_uid'] = $uid;
        }
        $secret = $_SESSION['setup_secret'];
        $uri = Totp::uri('Eshcol Recruit', $u['email'], $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd())))->writeString($uri);
        echo View::render('auth/setup', [
            'qr' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error' => $_SESSION['setup_error'] ?? null,
        ], 'layout/bare');
        unset($_SESSION['setup_error']);
        exit;
    }

    public function setup(): never
    {
        $uid = $this->needPending();
        $secret = ($_SESSION['setup_uid'] ?? null) === $uid ? ($_SESSION['setup_secret'] ?? null) : null;
        if (!$secret || !Totp::verify($secret, (string) Http::input('code'))) {
            $_SESSION['setup_error'] = 'That code did not match. Check the time on your phone is set automatically, then try the newest code.';
            Http::redirect('/login/setup');
        }
        $saved = $this->db->run(
            'UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ? AND totp_enabled = 0 AND is_active = 1',
            [$secret, $uid]
        )->rowCount();
        unset($_SESSION['setup_secret'], $_SESSION['setup_uid']);
        if ($saved !== 1) {
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            Http::flash('info', 'Please sign in again.');
            Http::redirect('/login');
        }
        Audit::log('2fa_enabled', 'user', $uid);
        Auth::complete($uid);
        Http::flash('ok', 'Two-step sign-in is on. From now on you will need your authenticator app to sign in.');
        Http::redirect('/');
    }

    public function logout(): never
    {
        Auth::logout();
        Http::redirect('/login');
    }

    private function needPending(): int
    {
        $uid = $_SESSION['pending_uid'] ?? null;
        if (!$uid || time() - ($_SESSION['pending_at'] ?? 0) > 600) {
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            Http::flash('info', 'Please sign in again.');
            Http::redirect('/login');
        }
        return (int) $uid;
    }
}
