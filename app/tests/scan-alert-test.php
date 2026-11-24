<?php
/**
 * The My day note about scanned CVs (24 Nov 2026): no more "use Read scanned CV" (that button went on 15 Nov);
 * a plain note with a "See who" list, and a stronger one for the owner when this month's reading limit is reached.
 * Run: php tests/scan-alert-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Services\AiReading as A;

$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$all = fn (array $a) => implode(' | ', array_column($a, 1));

$a = A::scanAlerts(5, 3, 120, 3000, true, true);
$check('under the limit: one plain note', count($a) === 1 && $a[0][0] === 'info' && str_contains($a[0][1], '5 CVs are scans or photos waiting for Claude to write out their text.'));
$check('never sends you to the removed "Read scanned CV" button', !str_contains($all($a), 'Read scanned CV'));
$check('links to the list of those people', str_contains($a[0][1], 'href="/candidates?scans=1"'));
$check('one CV: singular wording', str_contains(A::scanAlerts(1, 0, 0, 3000, true, true)[0][1], '1 CV is a scan or photo waiting'));

$a = A::scanAlerts(82, 82, 2000, 2000, true, true);
$check('limit reached (2000 of 2000, 82 waiting): a red note first, for the owner', count($a) === 2 && $a[0][0] === 'error'
    && str_contains($a[0][1], "This month's limit of 2,000 CV readings is reached: 82 CVs are waiting.")
    && str_contains($a[0][1], 'href="/settings/ai-reading"'));
$check('a recruiter is not shown the limit note (only the owner can raise it)', count(A::scanAlerts(82, 82, 2000, 2000, true, false)) === 1);
$check('limit reached but nothing waiting: no red note', count(A::scanAlerts(0, 0, 2000, 2000, true, true)) === 0);
$check('reading switched off: says to turn it on in Settings › AI reading', str_contains(A::scanAlerts(2, 0, 0, 3000, false, true)[0][1], 'Turn on Claude reading in <a href="/settings/ai-reading">'));
$check('nothing to say: no note', A::scanAlerts(0, 0, 10, 3000, true, true) === []);
$check('no em dashes', !str_contains($all(A::scanAlerts(82, 82, 2000, 2000, true, true)) . $all(A::scanAlerts(2, 0, 0, 3000, false, false)), '—'));

echo "\n$ok passed, $bad failed\n";
