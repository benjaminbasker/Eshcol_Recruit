<?php
/**
 * 20 Nov 2026 (Benjamin): clients sign in to the portal with their email and a 6-digit code emailed to them (not their
 * mobile number), and stay signed in 30 days; the owner can see the portal as a client sees it, read-only; and each
 * user chooses the app's colours. On a throwaway client. LOCAL ONLY. Run: php tests/portal-code-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\ClientPortal;

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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$themeWas = $db->col('SELECT theme FROM users WHERE id = ?', [$uid]);
$client = $db->insert('clients', ['name' => "ZZ Code Client $tag", 'is_active' => 1]);
register_shutdown_function(function () use ($db, $client, $tag, $uid, $themeWas, &$ok, &$bad) {
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $db->run('DELETE FROM email_queue WHERE to_email LIKE ?', ["%.$tag@clients.test"]);
    $db->run('UPDATE users SET theme = ? WHERE id = ?', [$themeWas, $uid]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$portal = new ClientPortal($app);
$email = "asha.code.$tag@clients.test";
$cid = $portal->addContact($client, 'Asha Code', $email, 'HR Head', '9876543210');
$other = $portal->addContact($client, 'No Access', "noaccess.$tag@clients.test", null, null);
$portal->invite((int) $cid, $uid);
$lastCode = function () use ($db, $email): string {
    $m = $db->one("SELECT subject, text FROM email_queue WHERE to_email = ? AND subject LIKE 'Your sign-in code:%' ORDER BY id DESC LIMIT 1", [$email]);
    preg_match('/(\d{6})/', (string) ($m['subject'] ?? ''), $x);
    return $x[1] ?? '';
};

echo "Asking for a code\n";
$check('the mobile number is not a way in: there is no password at all', !str_contains((string) file_get_contents(dirname(__DIR__) . '/views/portal/signin.php'), 'password"'));
$check('a known address gets a code', $portal->requestCode(strtoupper($email), '10.0.0.9') === true);
$code = $lastCode();
$check('…a 6-digit code, by email, with the code in the subject so it can be read at a glance', strlen($code) === 6 && ctype_digit($code));
$row = $db->one("SELECT * FROM client_logins WHERE contact_id = ? AND purpose = 'code' ORDER BY id DESC LIMIT 1", [$cid]);
$check('…only a hash of the code is kept', $row && !str_contains((string) $row['token_hash'], $code));
$check('…and it lasts 10 minutes', (int) $db->col('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM client_logins WHERE id = ?', [$row['id']]) >= 9
    && (int) $db->col('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM client_logins WHERE id = ?', [$row['id']]) <= 10);
$check('an unknown address gets nothing (the page looks the same either way)', $portal->requestCode("stranger.$tag@clients.test", null) === false);
$check('a contact without portal access gets nothing', $portal->requestCode("noaccess.$tag@clients.test", null) === false);

echo "\nEntering the code\n";
$wrong = $code === '000000' ? '111111' : '000000';
$check('a wrong code is refused', $portal->verifyCode($email, $wrong) === null);
$check('the right code for another address is refused', $portal->verifyCode("noaccess.$tag@clients.test", $code) === null);
$sess = $portal->verifyCode($email, substr($code, 0, 3) . ' ' . substr($code, 3), 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0');
$check('the right code signs in (a space in the middle is fine)', is_string($sess) && strlen($sess) >= 40);
$me = $portal->contactBySession($sess);
$check('…as that contact, at their client', ($me['name'] ?? '') === 'Asha Code' && (int) ($me['client_id'] ?? 0) === $client && empty($me['preview_by']));
$check('…for 30 days on that device', (int) $db->col('SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) FROM client_sessions WHERE token_hash = ?', [hash('sha256', $sess)]) >= 29);
$check('…and their last sign-in is recorded', (bool) $db->col('SELECT portal_last_login_at FROM client_contacts WHERE id = ?', [$cid]));
$check('a code works once only', $portal->verifyCode($email, $code) === null);

$portal->requestCode($email, null);
$code2 = $lastCode();
$bad2 = $code2 === '000000' ? '111111' : '000000';
for ($i = 0; $i < ClientPortal::CODE_TRIES; $i++) {
    $portal->verifyCode($email, $bad2);
}
$check('after 5 wrong tries even the right code stops working (no guessing)', $portal->verifyCode($email, $code2) === null);
$db->run("UPDATE client_logins SET created_at = NOW() - INTERVAL 1 HOUR WHERE contact_id = ?", [$cid]);
$check('no more than 3 codes in 15 minutes', $portal->requestCode($email, null) && $portal->requestCode($email, null) && $portal->requestCode($email, null)
    && $portal->requestCode($email, null) === false);
$db->run("UPDATE client_logins SET created_at = NOW() - INTERVAL 1 HOUR WHERE contact_id = ?", [$cid]);
$portal->requestCode($email, null);
$old = $lastCode();
$portal->requestCode($email, null);
$new = $lastCode();
$check('a new code replaces the one before it', $old === $new || $portal->verifyCode($email, $old) === null);
$check('…and the new one works', is_string($portal->verifyCode($email, $new)));
$portal->requestCode($email, null);
$late = $lastCode();
$db->run("UPDATE client_logins SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE contact_id = ? AND purpose = 'code' AND used_at IS NULL", [$cid]);
$check('an expired code is refused', $portal->verifyCode($email, $late) === null);

echo "\nSee what they see (the owner)\n";
$pv = $portal->previewSession((int) $other, $uid);
$pme = $portal->contactBySession((string) $pv);
$check('the owner can look as any contact, even one not yet given access', ($pme['name'] ?? '') === 'No Access' && (int) ($pme['preview_by'] ?? 0) === $uid);
$check('…the look lasts 2 hours, not 30 days', (int) $db->col('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM client_sessions WHERE token_hash = ?', [hash('sha256', (string) $pv)]) <= 120);
$check('…and does not count as the client signing in', !$db->col('SELECT portal_last_login_at FROM client_contacts WHERE id = ?', [$other]));
$ctl = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/PortalController.php');
$check('…nothing pressed in a preview is saved: decisions, interview times, withdrawn times, new positions', substr_count($ctl, '$this->notInPreview($me') === 4);
$check('…and it is not marked as seen by the client', str_contains($ctl, "if (empty(\$me['preview_by'])) {\n            \$svc->markViewed"));
$check('…the page says so on a banner', str_contains((string) file_get_contents(dirname(__DIR__) . '/views/layout/portal.php'), 'Nothing you press here is saved'));
$check('the client page has the button (owners only)', str_contains((string) file_get_contents(dirname(__DIR__) . '/views/clients/show.php'), 'value="preview" formtarget="_blank"'));
$portal->revoke((int) $cid);
$check('taking access away still ends their sign-ins', $portal->contactBySession($sess) === null && $portal->requestCode($email, null) === false);

echo "\nColours for each user\n";
$check('four themes: Eshcol brand first, then Orchid & Citrus, Ocean & Coral, Berry & Mint', array_keys(themes()) === ['eshcol', 'orchid', 'ocean', 'berry']);
$check('nobody has chosen: Eshcol brand', user_theme(['id' => $uid]) === ($themeWas && isset(themes()[$themeWas]) ? $themeWas : 'eshcol'));
$db->run('UPDATE users SET theme = ? WHERE id = ?', ['ocean', $uid]);
$check('a chosen theme is used', user_theme(['id' => $uid]) === 'ocean');
$db->run('UPDATE users SET theme = ? WHERE id = ?', ['neon', $uid]);
$check('an unknown theme falls back to Eshcol brand', user_theme(['id' => $uid]) === 'eshcol');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
$check('every theme has its colours in the stylesheet', count(array_filter(['orchid', 'ocean', 'berry'], fn ($t) => str_contains($css, "body[data-theme=\"$t\"]"))) === 3);
$check('the page carries the user\'s theme', str_contains((string) file_get_contents(dirname(__DIR__) . '/views/layout/app.php'), '<body data-theme="<?= e($theme) ?>">'));
$check('the client portal does not: it always shows the Eshcol brand', !str_contains((string) file_get_contents(dirname(__DIR__) . '/views/layout/portal.php'), 'data-theme'));
// Text on the coloured tiles is readable: 4.5:1 or better in every theme.
$lum = function (string $hex): float {
    $c = array_map(fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$worst = 99.0;
preg_match_all('/--m(\d): (#[0-9A-F]{6}); --m\1-i: (#[0-9A-F]{6})/i', $css, $mm, PREG_SET_ORDER);
foreach ($mm as [, , $bg, $fg]) {
    [$a, $b] = [$lum($bg), $lum($fg)];
    $worst = min($worst, (max($a, $b) + 0.05) / (min($a, $b) + 0.05));
}
$check('tile text is 4.5:1 or better in every theme (' . count($mm) . ' tiles, worst ' . round($worst, 2) . ':1)', count($mm) >= 20 && $worst >= 4.5);
