<?php
/**
 * Applies any database updates that have not run yet.
 * Usage: php bin/migrate.php   (on the live site, Settings > System does the same)
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Migrator;

$applied = (new Migrator(App::boot(cli: true)))->run();
foreach ($applied as $v) {
    echo "applied  $v\n";
}
echo $applied ? 'Done: ' . count($applied) . " migration(s).\n" : "Nothing to apply.\n";
