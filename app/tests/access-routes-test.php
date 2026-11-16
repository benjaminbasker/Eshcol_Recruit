<?php
/**
 * Audit S2 (16 Nov 2026): every signed-in page with a number in its address says what that number is (Access::KINDS),
 * so the private-position rule can check it. Fails when a route is added without an entry. No database.
 * Run: php tests/access-routes-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Access;

$ok = 0;
$bad = 0;
$src = (string) file_get_contents(dirname(__DIR__) . '/routes.php');
preg_match_all("#\\\$router->(?:get|post)\\('([^']+)',\\s*\\[([\\\\\\w]+)::class,\\s*'(\\w+)'\\](.*?)\\);#", $src, $m, PREG_SET_ORDER);
$seen = 0;
foreach ($m as [, $path, $class, $method, $rest]) {
    if (!str_contains($path, '{') || str_contains($rest, 'public: true')) {
        continue;
    }
    $seen++;
    $key = substr(strrchr('\\' . $class, '\\'), 1) . '::' . $method;
    if (isset(Access::KINDS[$key])) {
        $ok++;
    } else {
        $bad++;
        echo "  FAIL $path ($key) is not in Access::KINDS\n";
    }
}
if ($seen < 100) {
    $bad++;
    echo "  FAIL only $seen routes read from routes.php: the pattern no longer matches\n";
}
echo "  checked $seen routes with a number\n\n$ok passed, $bad failed\n";
