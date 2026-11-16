<?php
/**
 * LOCAL TESTING ONLY. Empties clients, roles, candidates, CV files and history so
 * testing starts from a clean app. Keeps your login, the stages, the skills
 * dictionary and settings. Refuses to run unless app.env is "local".
 * Usage: php bin/reset-local-data.php --yes
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Refused: this only runs on the local test copy.\n");
}
if (!in_array('--yes', $argv, true)) {
    exit("This empties all test clients, roles and candidates. Run again with --yes to confirm.\n");
}
$pdo = $app->db->pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['stage_history', 'applications', 'candidate_skills', 'cv_files', 'candidates', 'notes', 'role_criteria', 'roles', 'client_contacts', 'clients', 'upload_items', 'upload_batches', 'suppression', 'audit_log', 'auth_throttle'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("DELETE FROM settings WHERE k LIKE 'cron_%' OR k LIKE 'backup_%' OR k = 'retention_due'");

$removed = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app->storage('cv'), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $removed++);
}
foreach (glob($app->storage('backups/*')) ?: [] as $f) {
    @unlink($f);
}
echo "Test data cleared: $removed stored CV file(s) removed. Your login, stages, dictionary and settings are kept.\n";
