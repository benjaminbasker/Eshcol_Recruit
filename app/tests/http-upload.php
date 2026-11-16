<?php
/**
 * End-to-end upload test over real HTTP: signs in (password + authenticator code),
 * then uploads the fixture CVs to a role exactly as the browser page does.
 * LOCAL ONLY. Usage: php tests/http-upload.php <role_id> [file ...]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Totp;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$base = 'http://127.0.0.1:8090';
$jar = tempnam(sys_get_temp_dir(), 'ats');
$roleId = (int) ($argv[1] ?? 0);
$files = array_slice($argv, 2) ?: glob(__DIR__ . '/fixtures/*');

function req(string $method, string $url, array $fields = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hsize), $loc);
    return ['code' => $code, 'body' => substr($raw, $hsize), 'location' => $loc[1] ?? null];
}
function csrfFrom(string $html): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"|name="csrf" content="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?: ($m[2] ?? '');
}

// 1. Password step
$page = req('GET', "$base/login", [], $jar);
$r = req('POST', "$base/login", ['_csrf' => csrfFrom($page['body']), 'email' => $app->config['dev_login']['email'], 'password' => $app->config['dev_login']['pass']], $jar);
echo "password step: {$r['code']} -> {$r['location']}\n";

// 2. Authenticator step (the test account's secret is read from the local database)
$secret = $app->db->col('SELECT totp_secret FROM users WHERE email = ?', [$app->config['dev_login']['email']]);
$page = req('GET', "$base/login/code", [], $jar);
$r = req('POST', "$base/login/code", ['_csrf' => csrfFrom($page['body']), 'code' => Totp::code($secret)], $jar);
echo "code step:     {$r['code']} -> {$r['location']}\n";

// 3. Upload, one file per request, as the page does
$page = req('GET', "$base/upload", [], $jar);
$csrf = csrfFrom($page['body']);
$b = json_decode(req('POST', "$base/upload/batch", ['_csrf' => $csrf, 'role_id' => (string) $roleId], $jar)['body'], true);
echo "batch: " . json_encode($b) . "\n\n";
foreach ($files as $f) {
    $res = req('POST', "$base/upload/file", ['_csrf' => $csrf, 'batch' => (string) $b['batch'], 'file' => new CURLFile($f, '', basename($f))], $jar);
    $data = json_decode($res['body'], true);
    if (!$data) {
        echo basename($f) . ": HTTP {$res['code']} " . substr(strip_tags($res['body']), 0, 300) . "\n";
        continue;
    }
    foreach ($data['results'] as $x) {
        printf("%-40s %-15s %-22s %s\n", mb_strimwidth($x['file'], 0, 40, '…'), $x['outcome'], mb_strimwidth((string) $x['name'], 0, 22, '…'), $x['message']);
    }
}

// 4. CSRF protection: a post without the token must be refused
$bad = req('POST', "$base/upload/batch", ['role_id' => (string) $roleId], $jar);
echo "\nPOST without CSRF token -> HTTP {$bad['code']} (expected 419)\n";
@unlink($jar);
