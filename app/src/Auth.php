<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * Sign-in in two steps: password, then a 6-digit authenticator code.
 * "Trust this device" skips the code (never the password) on that browser for 7 days,
 * and a sign-in there lasts 7 days (Benjamin, 13 Nov 2026) instead of ending after two idle hours.
 * Locks an account for 15 minutes after 5 wrong passwords, and slows any one
 * address that keeps failing, whatever account it tries.
 */
final class Auth
{
    private const MAX_FAILS = 5;
    private const LOCK_MINUTES = 15;
    private const IP_LIMIT = 20;
    private const IP_WINDOW_MIN = 15;
    public const TRUST_DAYS = 7;

    public static function user(): ?array
    {
        static $cache = null;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        if ($cache && $cache['id'] === $id) {
            return $cache;
        }
        try {
            $u = App::get()->db->one('SELECT id, name, email, role, totp_enabled FROM users WHERE id = ? AND is_active = 1', [$id]);
            // Signed in before "Forget devices" or a password change: that sign-in has ended (a 7-day sign-in on a lost device included).
            $cut = $u ? (int) App::get()->setting('signed_out_before_' . (int) $id, '0') : 0;
            if ($u && $cut && (int) ($_SESSION['login_at'] ?? 0) < $cut) {
                Audit::log('signed_out_remotely', 'user', (int) $id);
                $_SESSION = [];
                $u = null;
            }
        } catch (\PDOException) {
            $u = null; // e.g. a fresh install with no tables yet: treat as signed out
        }
        if (!$u) {
            unset($_SESSION['uid']);
        }
        return $cache = $u;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            Http::redirect('/login');
        }
        return $u;
    }

    public static function requireOwner(): array
    {
        $u = self::require();
        if ($u['role'] !== 'owner') {
            Http::abort(403);
        }
        return $u;
    }

    /** Step 1. Returns null on success (pending 2FA), or an error message. */
    public static function attemptPassword(string $email, string $password): ?string
    {
        $db = App::get()->db;
        $ip = Http::ip();

        $t = $db->one('SELECT attempts, window_start FROM auth_throttle WHERE ip = ?', [$ip]);
        if ($t && strtotime($t['window_start']) > time() - self::IP_WINDOW_MIN * 60 && $t['attempts'] >= self::IP_LIMIT) {
            return 'Too many attempts from this connection. Please wait 15 minutes.';
        }

        $u = $db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [mb_strtolower($email)]);
        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            return 'This account is locked for a few minutes after repeated failed attempts.';
        }

        // Always run a hash check, so timing does not reveal whether the email exists.
        $ok = password_verify($password, $u['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$N280WUxrd21NZWxHTUpaVQ$hn//96MdA6XuewEcYmsosAq22jLyhfHzrlPXDmfkYfQ');
        if (!$u || !$ok) {
            self::recordFailure($ip, $u);
            Audit::log('login_failed', 'user', $u['id'] ?? null, ['email' => $email]);
            return 'That email and password do not match.';
        }

        if (password_needs_rehash($u['password_hash'], PASSWORD_ARGON2ID)) {
            $db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $u['id']]);
        }
        $db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
        $db->run('DELETE FROM auth_throttle WHERE ip = ?', [$ip]);

        session_regenerate_id(true);
        unset($_SESSION['setup_secret'], $_SESSION['setup_uid'], $_SESSION['setup_error']);
        $_SESSION['pending_uid'] = (int) $u['id'];
        $_SESSION['pending_at'] = time();
        return null;
    }

    /** Step 2. Completes sign-in when the authenticator code is right. */
    public static function attemptCode(string $code): bool
    {
        $uid = $_SESSION['pending_uid'] ?? null;
        if (!$uid || time() - ($_SESSION['pending_at'] ?? 0) > 300) {
            return false;
        }
        $u = App::get()->db->one('SELECT id, totp_secret, totp_enabled FROM users WHERE id = ?', [$uid]);
        if (!$u || !$u['totp_enabled'] || !Totp::verify($u['totp_secret'], $code)) {
            self::recordFailure(Http::ip(), null);
            Audit::log('2fa_failed', 'user', (int) $uid);
            return false;
        }
        self::complete((int) $uid);
        return true;
    }

    public static function complete(int $uid, bool $trustedDevice = false): void
    {
        unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['login_at'] = time();
        if ($trustedDevice) {
            Http::persistSession(App::get());
        }
        App::get()->db->run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$uid]);
        Audit::log('login', 'user', $uid, $trustedDevice ? ['via' => 'trusted device'] : null);
    }

    /** After the code: remember this browser for TRUST_DAYS, so it isn't asked for the code again. */
    public static function trustDevice(int $uid): void
    {
        $app = App::get();
        $token = bin2hex(random_bytes(32));
        $app->db->run('DELETE FROM trusted_devices WHERE expires_at < NOW()');
        $app->db->insert('trusted_devices', [
            'user_id' => $uid,
            'token_hash' => hash('sha256', $token),
            'label' => mb_substr(self::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 190),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TRUST_DAYS * 86400),
        ]);
        setcookie(Http::cookieName($app, 'eshcol_td_'), $token, [
            'expires' => time() + self::TRUST_DAYS * 86400, 'path' => '/',
            'secure' => !$app->isLocal(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        Http::persistSession($app);
        Audit::log('device_trusted', 'user', $uid);
    }

    /** True when this browser carries a valid, unexpired trust cookie for this user. */
    public static function isTrustedDevice(int $uid): bool
    {
        $app = App::get();
        $token = (string) ($_COOKIE[Http::cookieName($app, 'eshcol_td_')] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return false;
        }
        $id = $app->db->col('SELECT id FROM trusted_devices WHERE token_hash = ? AND user_id = ? AND expires_at > NOW()', [hash('sha256', $token), $uid]);
        if (!$id) {
            return false;
        }
        $app->db->run('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?', [$id]);
        return true;
    }

    /** Every trusted browser for this user will be asked for the code again. */
    public static function forgetDevices(int $uid): int
    {
        $app = App::get();
        $n = (int) $app->db->col('SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?', [$uid]);
        $app->db->run('DELETE FROM trusted_devices WHERE user_id = ?', [$uid]);
        // Every sign-in of this user made before now ends too (see user()), except the one doing this.
        $now = time();
        $app->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['signed_out_before_' . $uid, (string) $now]);
        if ((int) ($_SESSION['uid'] ?? 0) === $uid) {
            $_SESSION['login_at'] = max((int) ($_SESSION['login_at'] ?? 0), $now);
            unset($_SESSION['trusted']); // this browser is no longer trusted either: back to the two-hour rule
        }
        if (PHP_SAPI !== 'cli') {
            setcookie(Http::cookieName($app, 'eshcol_td_'), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !$app->isLocal(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $n;
    }

    /** "Chrome on Windows" style label, so the list of trusted devices is recognisable. */
    public static function deviceLabel(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'A browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => '',
        };
        return $browser . ($os ? " on $os" : '');
    }

    public static function logout(): void
    {
        Audit::log('logout', 'user', $_SESSION['uid'] ?? null);
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function validPassword(string $p): ?string
    {
        if (mb_strlen($p) < 12) {
            return 'Use at least 12 characters.';
        }
        return null;
    }

    private static function recordFailure(string $ip, ?array $u): void
    {
        $db = App::get()->db;
        $db->run('INSERT INTO auth_throttle (ip, attempts, window_start) VALUES (?, 1, NOW())
                  ON DUPLICATE KEY UPDATE
                    attempts = IF(window_start < NOW() - INTERVAL ' . self::IP_WINDOW_MIN . ' MINUTE, 1, attempts + 1),
                    window_start = IF(window_start < NOW() - INTERVAL ' . self::IP_WINDOW_MIN . ' MINUTE, NOW(), window_start)', [$ip]);
        if ($u) {
            $fails = (int) $u['failed_logins'] + 1;
            $lock = $fails >= self::MAX_FAILS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            $db->run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $u['id']]);
        }
    }
}
