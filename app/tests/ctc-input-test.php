<?php
/**
 * Salary fields on the role and candidate forms: lakh, rupees, commas and nonsense.
 * Usage: php tests/ctc-input-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Controllers\Controller;

$app = App::boot(cli: true);
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$ctl = (new ReflectionClass(\Eshcol\Ats\Controllers\RoleController::class))->newInstanceWithoutConstructor();
$lpa = function (string $typed) use ($ctl): array {
    $_POST = ['x' => $typed];
    $errors = [];
    $v = (function () use (&$errors) { return $this->lpa('x', $errors); })->call($ctl);
    return [$v, $errors['x'] ?? ''];
};

echo "Salary typed into the forms\n";
foreach ([['6.5', 6.5], ['6.5 LPA', 6.5], ['6 lakh', 6.0], ['₹ 6.5 lakhs per annum', 6.5], ['600000', 6.0], ['6,00,000', 6.0], ['₹6,50,000', 6.5],
          ['Rs. 12,00,000', 12.0], ['0', 0.0], ['', null], ['4.25', 4.25]] as [$in, $want]) {
    [$v, $e] = $lpa($in);
    $check("\"$in\" is read as " . var_export($want, true) . ' LPA', $v === $want && $e === '');
}
foreach (['six lakh', '-3', '45000', '9999999999'] as $in) {
    [$v, $e] = $lpa($in);
    $check("\"$in\" is refused with a reason [$e]", $v === null && $e !== '');
}
echo "\n$ok passed, $bad failed\n";
