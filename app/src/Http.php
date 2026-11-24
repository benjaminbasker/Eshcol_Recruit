<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/** Request, response, session, CSRF and flash helpers. */
final class Http
{
    public static function startSession(App $app): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !$app->isLocal();
        session_name(self::cookieName($app, 'eshcol_ats_'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        // Kept in the app's own folder, so the host's shared clean-up (often 24 minutes)
        // can't cut a sign-in short; PHP removes files older than a day and a bit.
        $dir = $app->storage('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.gc_maxlifetime', (string) (self::TRUSTED_SESSION + 3600));
        session_start();

        // On a trusted device (see Auth::trustDevice) a sign-in lasts 7 days (Benjamin, 13 Nov 2026), even if the
        // browser is closed. Anywhere else, two hours without activity signs you out.
        $now = time();
        if (!empty($_SESSION['uid']) && !empty($_SESSION['trusted'])) {
            if ($now - (int) ($_SESSION['login_at'] ?? 0) > self::TRUSTED_SESSION) {
                $_SESSION = [];
                session_regenerate_id(true);
                self::flash('info', 'Your 7-day sign-in on this device has ended. Please sign in again.');
            }
        } elseif (isset($_SESSION['last_seen']) && $now - $_SESSION['last_seen'] > self::IDLE_SESSION) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('info', 'You were signed out after two hours of inactivity.');
        }
        $_SESSION['last_seen'] = $now;
    }

    /** Sign-in length on a trusted device, and the idle limit everywhere else (seconds). */
    public const TRUSTED_SESSION = 7 * 86400;
    public const IDLE_SESSION = 7200;

    /** Keeps this sign-in's cookie through a browser restart, until 7 days after signing in. */
    public static function persistSession(App $app): void
    {
        $_SESSION['trusted'] = true;
        $_SESSION['login_at'] ??= time();
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(session_name(), session_id(), [
            'expires' => (int) $_SESSION['login_at'] + self::TRUSTED_SESSION, 'path' => '/',
            'secure' => !$app->isLocal(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /**
     * Each install (database) gets its own cookie names, so two copies never share a sign-in.
     * Over HTTPS the __Host- prefix makes the browser refuse the cookie from any other site or path.
     */
    public static function cookieName(App $app, string $base): string
    {
        $suffix = substr(hash('sha256', ($app->config['db']['name'] ?? '') . '|' . ($app->config['app']['key'] ?? '')), 0, 8);
        return ($app->isLocal() ? '' : '__Host-') . $base . $suffix;
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * A simple limit: true while fewer than $max hits for $key in the last $seconds (and counts this one).
     * Kept as small files under storage/ratelimit (no database needed, so it works even mid-update).
     */
    public static function throttle(string $key, int $max, int $seconds): bool
    {
        $dir = \Eshcol\Ats\App::get()->storage('ratelimit');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return true; // never lock people out because of a file problem
        }
        flock($fh, LOCK_EX);
        $hits = array_values(array_filter((array) json_decode((string) stream_get_contents($fh), true), fn ($t) => is_int($t) && $t > $now - $seconds));
        $ok = count($hits) < $max;
        if ($ok) {
            $hits[] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        flock($fh, LOCK_UN);
        fclose($fh);
        if (random_int(1, 200) === 1) { // now and then, tidy files nobody has used for a day
            foreach (glob("$dir/*.json") ?: [] as $old) {
                if (filemtime($old) < $now - 86400) {
                    @unlink($old);
                }
            }
        }
        return $ok;
    }

    public static function path(): string
    {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Some mail programs tack "&something=1" onto a link with no "?" (seen 16 Nov 2026: "/s/<code>&routed=1"),
        // which made a client's shortlist link show "Page not found". No path of ours has "&" in it, so drop what follows.
        if (($amp = strpos($p, '&')) !== false) {
            $p = substr($p, 0, $amp) ?: '/';
        }
        return rtrim($p, '/') ?: '/';
    }

    public static function input(string $key, mixed $default = ''): mixed
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function ip(): string
    {
        // Behind Cloudflare the visitor's address arrives in CF-Connecting-IP.
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        return filter_var($cf, FILTER_VALIDATE_IP) ? $cf : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function redirect(string $to): never
    {
        // After an action on a page opened from somewhere else, go back there (Back, 24 Nov 2026).
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_back'])) {
            $failed = (bool) array_filter($_SESSION['flash'] ?? [], fn ($f) => ($f[0] ?? '') === 'error');
            $to = Back::afterPost($to, Back::safe($_POST['_back']), Back::safe($_POST['_here'] ?? null), $failed);
        }
        header('Location: ' . $to, true, 303);
        exit;
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        echo View::render('layout/error', ['code' => $code, 'message' => $message ?: match ($code) {
            403 => 'You do not have access to this page.',
            404 => 'That page does not exist.',
            419 => 'This form expired. Please go back, refresh and try again.',
            default => 'Something went wrong.',
        }], 'layout/bare');
        exit;
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::csrfToken() . '">';
    }

    public static function verifyCsrf(): void
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            self::abort(419);
        }
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; worker-src 'self' blob:; connect-src 'self' data: blob:; frame-ancestors 'none'; form-action 'self'");
        // 15 Nov 2026 (audit B16): the in-browser scan reader is gone (Claude writes out scans), so WebAssembly, outside
        // workers and the OCR data host are no longer allowed. 16 Nov 2026: the branded-CV builder's two libraries (pdf-lib,
        // mammoth) are served from /assets/vendor, still integrity-checked, so no outside script host is allowed at all.
    }
}
