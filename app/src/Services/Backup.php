<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Encrypted backups, free.
 *
 * - Database: exported nightly to a compressed SQL file, encrypted (AES-256-GCM),
 *   kept locally (last 7) and copied to Cloudflare R2 when configured.
 * - CV files: each new file is encrypted and copied to R2 once (files never change).
 *   Deleting a candidate also deletes their copies there.
 * - Job descriptions, company profiles and client logos (storage/docs, storage/logos):
 *   same, copied once each; when one is replaced or removed here, its copy there goes too.
 *
 * Encryption is in 1 MB chunks so large databases never need much memory. Each chunk
 * is authenticated with its position, and the last chunk is marked, so a reordered or
 * cut-off file is rejected on restore. Without the key, a backup is unreadable:
 * the key must also be kept somewhere safe away from the server.
 */
final class Backup
{
    private const MAGIC = "ESHBK1\n";
    private const CHUNK = 1048576;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Off-site storage, if set up. Either Cloudflare R2:
     *   'r2' => ['account_id' => ..., 'bucket' => ..., 'access_key' => ..., 'secret_key' => ...]
     * or any S3-compatible store such as Backblaze B2:
     *   's3' => ['endpoint' => 'https://s3.us-west-004.backblazeb2.com', 'region' => 'us-west-004', 'bucket' => ..., 'access_key' => ..., 'secret_key' => ...]
     */
    public static function remote(App $app): ?S3Client
    {
        $r = $app->config['backup']['r2'] ?? null;
        if ($r && !empty($r['account_id']) && !empty($r['access_key']) && !empty($r['secret_key']) && !empty($r['bucket'])) {
            return S3Client::forR2($r['account_id'], $r['bucket'], $r['access_key'], $r['secret_key']);
        }
        // Saved through Settings › System (encrypted in the database).
        $saved = json_decode((string) \Eshcol\Ats\Secrets::get($app, 'backup_r2'), true);
        if (is_array($saved) && !empty($saved['account_id']) && !empty($saved['access_key']) && !empty($saved['secret_key']) && !empty($saved['bucket'])) {
            return S3Client::forR2($saved['account_id'], $saved['bucket'], $saved['access_key'], $saved['secret_key']);
        }
        $s = $app->config['backup']['s3'] ?? null;
        if ($s && !empty($s['endpoint']) && !empty($s['region']) && !empty($s['access_key']) && !empty($s['secret_key']) && !empty($s['bucket'])) {
            return new S3Client(rtrim($s['endpoint'], '/'), $s['bucket'], $s['access_key'], $s['secret_key'], $s['region']);
        }
        return null;
    }

    public static function cvKey(string $sha, string $ext): string
    {
        return "cv/$sha.$ext.enc";
    }

    /** Nightly: database export, encrypted, kept locally and copied off-site. */
    public function runDatabase(): string
    {
        $dir = $this->app->storage('backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $name = 'db-' . date('Ymd-His') . '.sql.gz';
        $plain = "$dir/$name";
        $enc = "$plain.enc";
        try {
            $this->dumpDatabase($plain);
            $this->encryptFile($plain, $enc);
        } finally {
            @unlink($plain);
        }
        if ($remote = self::remote($this->app)) {
            $remote->put("db/$name.enc", (string) file_get_contents($enc));
        }
        $old = glob("$dir/db-*.sql.gz.enc") ?: [];
        rsort($old);
        foreach (array_slice($old, 7) as $f) {
            @unlink($f);
        }
        $this->set('backup_last_ok', date('Y-m-d H:i:s'));
        return $enc;
    }

    /** Copies CV files not yet backed up to R2. Returns how many were sent. */
    public function syncCvs(int $limit = 200): int
    {
        $remote = self::remote($this->app);
        if (!$remote) {
            return 0;
        }
        $db = $this->app->db;
        $n = 0;
        foreach ($db->all('SELECT id, sha256, ext, storage_path FROM cv_files WHERE backed_up_at IS NULL ORDER BY id LIMIT ' . (int) $limit) as $f) {
            $src = $this->app->storage($f['storage_path']);
            if (!is_file($src)) {
                continue;
            }
            $tmp = $this->app->storage('uploads-tmp/' . bin2hex(random_bytes(8)));
            try {
                $this->encryptFile($src, $tmp);
                $remote->put(self::cvKey($f['sha256'], $f['ext']), (string) file_get_contents($tmp));
                $db->run('UPDATE cv_files SET backed_up_at = NOW() WHERE id = ?', [$f['id']]);
                $n++;
            } finally {
                @unlink($tmp);
            }
        }
        return $n;
    }

    /** Folders of uploaded documents and logos that are copied off-site. */
    public const FILE_DIRS = ['docs', 'logos'];

    public static function fileKey(string $rel): string
    {
        return "files/$rel.enc";
    }

    /**
     * Copies job descriptions, company profiles and logos not yet sent, and removes
     * the off-site copy of any that were deleted here. Their names never change
     * (a new upload gets a new name), so a list of what was sent is all we need.
     * Returns [copied, removed]. $remote is for tests; normally the configured store.
     */
    public function syncFiles(int $limit = 500, ?object $remote = null): array
    {
        $remote ??= self::remote($this->app);
        if (!$remote) {
            return [0, 0];
        }
        $sent = json_decode((string) $this->app->setting('backup_files_sent'), true);
        $sent = is_array($sent) ? array_fill_keys($sent, true) : [];
        $here = [];
        foreach (self::FILE_DIRS as $dir) {
            foreach (glob($this->app->storage($dir) . '/*') ?: [] as $path) {
                if (is_file($path) && !str_starts_with(basename($path), '.')) {
                    $here["$dir/" . basename($path)] = $path;
                }
            }
        }
        [$copied, $removed] = [0, 0];
        try {
            foreach ($here as $rel => $path) {
                if (isset($sent[$rel]) || $copied >= $limit) {
                    continue;
                }
                $tmp = $this->app->storage('uploads-tmp/' . bin2hex(random_bytes(8)));
                try {
                    $this->encryptFile($path, $tmp);
                    $remote->put(self::fileKey($rel), (string) file_get_contents($tmp));
                    $sent[$rel] = true;
                    $copied++;
                } finally {
                    @unlink($tmp);
                }
            }
            foreach (array_keys($sent) as $rel) {
                if (!isset($here[$rel])) {
                    $remote->delete(self::fileKey($rel));
                    unset($sent[$rel]);
                    $removed++;
                }
            }
        } finally {
            // Saved even after a failure part-way, so finished copies aren't sent again.
            $this->set('backup_files_sent', json_encode(array_keys($sent), JSON_UNESCAPED_SLASHES));
        }
        return [$copied, $removed];
    }

    /** Streams every table to a gzip'd SQL file, without holding it all in memory. */
    public function dumpDatabase(string $target): void
    {
        $pdo = $this->app->db->pdo();
        $gz = gzopen($target, 'wb6');
        gzwrite($gz, "-- Eshcol Recruit backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
        $buffered = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
            $pdo->setAttribute($buffered, false);
            $rows = $pdo->query("SELECT * FROM `$t`", \PDO::FETCH_ASSOC);
            $batch = [];
            $cols = null;
            foreach ($rows as $row) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $rows->closeCursor();
            $pdo->setAttribute($buffered, true);
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    /** Restores a decrypted dump into the given database name (must already exist). */
    public function restore(string $encFile, string $dbName): int
    {
        $plain = $this->app->storage('uploads-tmp/restore-' . bin2hex(random_bytes(6)) . '.sql.gz');
        $this->decryptFile($encFile, $plain);
        $cfg = $this->app->config['db'];
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'] ?? 3306, $dbName),
            $cfg['user'], $cfg['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $gz = gzopen($plain, 'rb');
        $stmt = '';
        $n = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                if ($stmt === '' && str_starts_with($line, '--')) {
                    continue;
                }
                $stmt .= $line;
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec($stmt);
                    $stmt = '';
                    $n++;
                }
            }
        } finally {
            gzclose($gz);
            @unlink($plain);
        }
        return $n;
    }

    public function encryptFile(string $in, string $out): void
    {
        $key = $this->key();
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        fwrite($dst, self::MAGIC);
        $i = 0;
        $chunk = fread($src, self::CHUNK);
        do {
            $next = feof($src) ? '' : fread($src, self::CHUNK);
            $last = $next === '' || $next === false;
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt((string) $chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $i) . ($last ? 'L' : 'M'));
            fwrite($dst, pack('N', strlen($ct)) . $iv . $tag . $ct);
            $chunk = $next;
            $i++;
        } while (!$last);
        fclose($src);
        fclose($dst);
    }

    public function decryptFile(string $in, string $out, ?string $keyHex = null): void
    {
        $key = $keyHex !== null ? hex2bin($keyHex) : $this->key();
        $src = fopen($in, 'rb');
        if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new \RuntimeException('Not an Eshcol backup file.');
        }
        $dst = fopen($out, 'wb');
        $i = 0;
        $sawLast = false;
        while (!feof($src) && ($len = fread($src, 4)) !== '' && $len !== false) {
            if ($sawLast) {
                throw new \RuntimeException('Backup file has extra data after its end.');
            }
            $n = unpack('N', $len)[1];
            $iv = fread($src, 12);
            $tag = fread($src, 16);
            $ct = $n > 0 ? fread($src, $n) : '';
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $i) . 'L');
            if ($pt !== false) {
                $sawLast = true;
            } else {
                $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $i) . 'M');
            }
            if ($pt === false) {
                throw new \RuntimeException('Backup is damaged, or the key is wrong.');
            }
            fwrite($dst, $pt);
            $i++;
        }
        fclose($src);
        fclose($dst);
        if (!$sawLast) {
            throw new \RuntimeException('Backup file is incomplete (it was cut off).');
        }
    }

    private function key(): string
    {
        $hex = (string) ($this->app->config['backup']['key'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new \RuntimeException('Backup key missing from the config file.');
        }
        return hex2bin($hex);
    }

    private function set(string $k, string $v): void
    {
        $this->app->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
}
