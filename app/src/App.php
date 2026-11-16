<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * Boots the app: loads the private config (kept outside the web root), sets the
 * timezone and opens the database. One instance per request.
 */
final class App
{
    private static ?App $instance = null;

    public readonly array $config;
    public readonly Db $db;
    public readonly string $root;

    private function __construct(array $config, string $root)
    {
        $this->config = $config;
        $this->root = $root;
        $this->db = new Db($config['db']);
    }

    public static function boot(bool $cli = false): self
    {
        if (self::$instance) {
            return self::$instance;
        }
        $root = dirname(__DIR__);
        // Live: eshcol-ats-config.php beside the app folder, outside the web root.
        // This PC: config.local.php. An environment variable can override both.
        $path = getenv('ESHCOL_ATS_CONFIG') ?: '';
        foreach ([$path, dirname($root) . '/eshcol-ats-config.php', dirname($root) . '/config.local.php'] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if (!is_file($path)) {
            http_response_code(500);
            exit($cli ? "Config not found at $path\n" : 'Configuration missing.');
        }
        $config = require $path;
        date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');
        mb_internal_encoding('UTF-8');

        self::$instance = new self($config, $root);
        // Private storage lives outside the web root; create its folders on first use.
        foreach (['cv', 'uploads-tmp', 'backups', 'logs'] as $dir) {
            $p = self::$instance->storage($dir);
            if (!is_dir($p)) {
                @mkdir($p, 0750, true);
            }
        }
        return self::$instance;
    }

    public static function get(): self
    {
        return self::$instance ?? throw new \LogicException('App not booted');
    }

    public function isLocal(): bool
    {
        return ($this->config['app']['env'] ?? 'production') === 'local';
    }

    /** Absolute path inside the private storage folder (never web-reachable). */
    public function storage(string $sub = ''): string
    {
        $base = rtrim(str_replace('\\', '/', $this->config['app']['storage']), '/');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $v = $this->db->col('SELECT v FROM settings WHERE k = ?', [$key]);
        return $v === false || $v === null ? $default : (string) $v;
    }
}
