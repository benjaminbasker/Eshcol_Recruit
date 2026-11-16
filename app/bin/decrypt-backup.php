<?php
/**
 * Disaster recovery: turns an encrypted backup back into a plain file.
 *
 *   php bin/decrypt-backup.php <backup.enc> <output> [64-hex-key]
 *
 * A database backup becomes a .sql.gz you can import in cPanel > phpMyAdmin > Import.
 * A CV backup (cv/<sha>.<ext>.enc) becomes the original file.
 * The key is read from the config file, or can be given as the third argument,
 * so this works on any PC with PHP even if the server is gone.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Backup;

[$in, $out, $key] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? null];
if (!is_file($in) || $out === '') {
    exit("Usage: php bin/decrypt-backup.php <backup.enc> <output> [64-hex-key]\n");
}
if ($key !== null && !preg_match('/^[0-9a-f]{64}$/', $key)) {
    exit("The key must be 64 characters of 0-9 and a-f.\n");
}
try {
    (new Backup(App::boot(cli: true)))->decryptFile($in, $out, $key);
    echo "Decrypted to $out (" . number_format(filesize($out) / 1024, 1) . " KB)\n";
} catch (RuntimeException $e) {
    @unlink($out);
    exit('Could not decrypt: ' . $e->getMessage() . "\n");
}
