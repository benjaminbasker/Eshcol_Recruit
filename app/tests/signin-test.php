<?php
/**
 * Trusted devices (Benjamin, 13 Nov 2026): a sign-in on a trusted device lasts 7 days; "Forget devices"
 * (and a password change) ends every other sign-in of that user at once, a 7-day one on a lost device
 * included, but not the one doing it. Each check runs in its own PHP process, as a page load would.
 * LOCAL ONLY. Run: php tests/signin-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
$key = "signed_out_before_$uid";
$was = $app->setting($key);
register_shutdown_function(function () use ($db, $key, $was, &$ok, &$bad) {
    $was === null ? $db->run('DELETE FROM settings WHERE k = ?', [$key]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$was, $key]);
    echo "\n$ok passed, $bad failed\n";
});
// One page load: a session with these values; prints whether Auth::user() sees someone signed in.
$php = PHP_BINARY;
$tmp = $app->storage('uploads-tmp/zz-signin-load-' . getmypid() . '.php');
$load = function (array $session) use ($php, $tmp): string {
    file_put_contents($tmp, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; Eshcol\Ats\App::boot(cli: true); $_SESSION = '
        . var_export($session, true) . '; echo Eshcol\Ats\Auth::user() ? "in" : "out";');
    $out = trim((string) shell_exec('"' . $php . '" "' . $tmp . '"'));
    @unlink($tmp);
    return $out;
};

echo "Seven days on a trusted device\n";
$check('a sign-in on a trusted device now lasts 7 days', Http::TRUSTED_SESSION === 7 * 86400);
$check('the trusted-device mark lasts 7 days too', Auth::TRUST_DAYS === 7);
$db->run('DELETE FROM settings WHERE k = ?', [$key]);
$check('a trusted sign-in from 6 days ago is still signed in', $load(['uid' => $uid, 'login_at' => time() - 6 * 86400, 'trusted' => true]) === 'in');

echo "\n\"Forget devices\" ends the other sign-ins\n";
$_SESSION = ['uid' => $uid, 'login_at' => time() - 3 * 86400, 'trusted' => true];
Auth::forgetDevices($uid);
$check('the browser that pressed it stays signed in, but is no longer trusted', (int) $_SESSION['login_at'] >= time() - 1 && empty($_SESSION['trusted']));
$check('…and a page load there still sees you signed in', $load($_SESSION) === 'in');
$check('a 7-day sign-in on another (say, lost) device made before it has ended', $load(['uid' => $uid, 'login_at' => time() - 2 * 86400, 'trusted' => true]) === 'out');
$check('…and an ordinary one from earlier today too', $load(['uid' => $uid, 'login_at' => time() - 600]) === 'out');
$check('signing in again afterwards works', $load(['uid' => $uid, 'login_at' => time() + 1, 'trusted' => true]) === 'in');
