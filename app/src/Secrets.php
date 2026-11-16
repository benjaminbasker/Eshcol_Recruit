<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * Small secrets (such as the Brevo API key) kept in the settings table, encrypted with
 * AES-256-GCM under a key derived from the app key in the config file. A copy of the
 * database alone (a backup, say) therefore does not reveal them.
 */
final class Secrets
{
    public static function put(App $app, string $name, string $value): void
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($value, 'aes-256-gcm', self::key($app), OPENSSL_RAW_DATA, $iv, $tag, $name);
        $app->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            ["secret_$name", base64_encode($iv . $tag . $ct)]);
    }

    public static function get(App $app, string $name): ?string
    {
        $raw = base64_decode((string) $app->setting("secret_$name", ''), true);
        if (!$raw || strlen($raw) < 29) {
            return null;
        }
        $v = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($app), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $name);
        return $v === false ? null : $v;
    }

    public static function has(App $app, string $name): bool
    {
        return self::get($app, $name) !== null;
    }

    public static function forget(App $app, string $name): void
    {
        $app->db->run('DELETE FROM settings WHERE k = ?', ["secret_$name"]);
    }

    /** Encrypts a short value for storing in the database (bound to $context, so it can't be swapped between uses). */
    public static function seal(App $app, string $value, string $context): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($value, 'aes-256-gcm', self::key($app), OPENSSL_RAW_DATA, $iv, $tag, $context);
        return base64_encode($iv . $tag . $ct);
    }

    public static function open(App $app, string $sealed, string $context): ?string
    {
        $raw = base64_decode($sealed, true);
        if (!$raw || strlen($raw) < 29) {
            return null;
        }
        $v = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($app), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        return $v === false ? null : $v;
    }

    /** A short signature for a value, so a link (e.g. one-click unsubscribe) can't be made up for someone else. */
    public static function mac(App $app, string $value, string $context): string
    {
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $context . '|' . $value, self::key($app), true)), '+/', '-_'), '='), 0, 22);
    }

    private static function key(App $app): string
    {
        $k = (string) ($app->config['app']['key'] ?? '');
        if (strlen($k) < 16) {
            throw new \RuntimeException('The app key in the config file is missing.');
        }
        return hash_hkdf('sha256', $k, 32, 'eshcol-ats-secrets');
    }
}
