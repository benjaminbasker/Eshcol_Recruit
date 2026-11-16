<?php
/**
 * Proves backups restore: dump -> encrypt -> decrypt -> restore into a scratch
 * database -> compare every table. Then checks damaged or wrong-key files are
 * refused. LOCAL ONLY (creates and drops a scratch database with the root account).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Backup;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};

$cfg = $app->config['db'];
$root = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", 'root', $app->config['db_root_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$scratch = 'eshcol_ats_restoretest';
$root->exec("DROP DATABASE IF EXISTS `$scratch`");
$root->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach (['127.0.0.1', 'localhost'] as $h) {
    $root->exec("GRANT ALL PRIVILEGES ON `$scratch`.* TO '{$cfg['user']}'@'$h'");
}

$b = new Backup($app);
$t0 = microtime(true);
$enc = $b->runDatabase();
printf("Backup written: %s (%s KB, %.1fs)\n", basename($enc), number_format(filesize($enc) / 1024, 1), microtime(true) - $t0);
$check('file starts with the encrypted-backup marker', file_get_contents($enc, false, null, 0, 7) === "ESHBK1\n");
$check('no readable personal data inside (searched for a test email)', !str_contains((string) file_get_contents($enc), 'priya.ramanathan'));

$n = $b->restore($enc, $scratch);
echo "Restored $n statements into $scratch\n";

$live = $app->db->pdo();
$tables = $live->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$same = true;
foreach ($tables as $t) {
    // The "last backup" timestamp is written after the snapshot, so it is rightly not in it.
    $skip = $t === 'settings' ? " WHERE k NOT IN ('backup_last_ok','cron_heartbeat') AND k NOT LIKE 'cron_last_%'" : '';
    $a = (int) $live->query("SELECT COUNT(*) FROM `$t`$skip")->fetchColumn();
    $r = (int) $root->query("SELECT COUNT(*) FROM `$scratch`.`$t`$skip")->fetchColumn();
    if ($a !== $r) {
        $same = false;
        echo "    $t: live $a, restored $r\n";
    }
}
$check('every table has the same number of rows (' . count($tables) . ' tables)', $same);
$sumA = $live->query('SELECT MD5(GROUP_CONCAT(CONCAT_WS("|", id, full_name, email, exp_months, ctc_expected_lpa, field_sources) ORDER BY id)) FROM candidates')->fetchColumn();
$sumR = $root->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, full_name, email, exp_months, ctc_expected_lpa, field_sources) ORDER BY id)) FROM `$scratch`.candidates")->fetchColumn();
$check('candidate details identical after restore (checksum)', $sumA === $sumR);
$cvA = $live->query('SELECT MD5(GROUP_CONCAT(text ORDER BY id)) FROM cv_files')->fetchColumn();
$cvR = $root->query("SELECT MD5(GROUP_CONCAT(text ORDER BY id)) FROM `$scratch`.cv_files")->fetchColumn();
$check('CV text identical after restore (checksum)', $cvA === $cvR);
$ft = (int) $root->query("SELECT COUNT(*) FROM `$scratch`.cv_files WHERE MATCH(text) AGAINST ('GST')")->fetchColumn();
$hasGst = (int) $live->query("SELECT COUNT(*) FROM cv_files WHERE MATCH(text) AGAINST ('GST')")->fetchColumn();
$check('search index works in the restored copy' . ($hasGst ? '' : ' (skipped: no CVs mention GST yet)'), $ft === $hasGst);

echo "\nTampering\n";
$tmp = $app->storage('uploads-tmp/t-' . getmypid());
$out = "$tmp.out";
$try = function (string $file) use ($b, $out): string {
    try {
        $b->decryptFile($file, $out);
        return 'accepted';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        @unlink($out);
    }
};
$raw = (string) file_get_contents($enc);
file_put_contents($tmp, substr($raw, 0, (int) (strlen($raw) * 0.6)));
$check('a cut-off backup is refused  [' . $try($tmp) . ']', $try($tmp) !== 'accepted');
$flip = $raw;
$pos = (int) (strlen($raw) / 2);
$flip[$pos] = chr(ord($flip[$pos]) ^ 1);
file_put_contents($tmp, $flip);
$check('a backup with one changed byte is refused  [' . $try($tmp) . ']', $try($tmp) !== 'accepted');
try {
    $b->decryptFile($enc, $out, str_repeat('ab', 32));
    $wrong = 'accepted';
} catch (RuntimeException $e) {
    $wrong = $e->getMessage();
}
@unlink($out);
$check('the right file with the wrong key is refused  [' . $wrong . ']', $wrong !== 'accepted');
@unlink($tmp);

echo "\nJob descriptions, profiles and logos\n";
$store = new class {
    public array $files = [];
    public function put(string $k, string $body): void { $this->files[$k] = $body; }
    public function delete(string $k): void { unset($this->files[$k]); }
};
$savedSent = $app->setting('backup_files_sent');
$app->db->run("DELETE FROM settings WHERE k = 'backup_files_sent'");
$pre = [];
foreach (Backup::FILE_DIRS as $d) {
    foreach (glob($app->storage($d) . '/*') ?: [] as $p) { if (is_file($p)) { $pre[] = "$d/" . basename($p); } }
}
$jd = 'docs/zz-test-' . getmypid() . '.pdf';
$logo = 'logos/zz-test-' . getmypid() . '.png';
foreach ([$jd, $logo] as $rel) {
    if (!is_dir(dirname($app->storage($rel)))) { mkdir(dirname($app->storage($rel)), 0750, true); }
    file_put_contents($app->storage($rel), "%PDF-1.4 secret client text $rel");
}
[$c1] = $b->syncFiles(500, $store);
$check('a new JD and a new logo are copied off-site', $c1 === count($pre) + 2 && isset($store->files[Backup::fileKey($jd)], $store->files[Backup::fileKey($logo)]));
$check('the copies are encrypted', !str_contains($store->files[Backup::fileKey($jd)], 'secret client text'));
$b->decryptFile((function () use ($store, $jd, $tmp) { file_put_contents($tmp, $store->files[Backup::fileKey($jd)]); return $tmp; })(), $out);
$check('a copy decrypts back to the original file', file_get_contents($out) === file_get_contents($app->storage($jd)));
@unlink($out);
[$c2, $r2] = $b->syncFiles(500, $store);
$check('nothing is sent twice', $c2 === 0 && $r2 === 0);
unlink($app->storage($jd));
[$c3, $r3] = $b->syncFiles(500, $store);
$check('a deleted JD is removed off-site too', $r3 === 1 && !isset($store->files[Backup::fileKey($jd)]) && isset($store->files[Backup::fileKey($logo)]));
unlink($app->storage($logo));
$b->syncFiles(500, $store);
@unlink($tmp);
$savedSent === null ? $app->db->run("DELETE FROM settings WHERE k = 'backup_files_sent'")
    : $app->db->run("UPDATE settings SET v = ? WHERE k = 'backup_files_sent'", [$savedSent]);

$root->exec("DROP DATABASE `$scratch`");
echo "\nScratch database removed.\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
