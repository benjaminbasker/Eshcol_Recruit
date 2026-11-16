<?php
/**
 * Runs a backup now: database (encrypted, kept locally, copied to R2 if set up)
 * plus any CV files, job descriptions, profiles and logos not yet copied off-site.
 * Usage: php bin/backup.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Backup;

$app = App::boot(cli: true);
$b = new Backup($app);
$file = $b->runDatabase();
echo 'Database backup: ' . basename($file) . ' (' . number_format(filesize($file) / 1024, 1) . " KB)\n";
echo Backup::remote($app) ? 'CV files copied off-site: ' . $b->syncCvs(10000) . "\nDocuments and logos copied off-site: " . $b->syncFiles(10000)[0] . "\n" :"Off-site copy: not set up (add the R2 details to the config file).\n";
