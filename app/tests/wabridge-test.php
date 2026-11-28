<?php
/**
 * WhatsApp to candidates through WABridge (update 082, 28 Nov 2026), against a stand-in for WABridge's API: nothing
 * while sending is off; the thank-you waits as long as the email; templates matched by name and held until approved;
 * nobody marked do not contact, asking for removal or with WhatsApp off is sent anything, even if queued earlier;
 * delivery reports. LOCAL ONLY: throwaway keys and "Zz" candidates. Run: php tests/whatsapp-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\Migrator;
use Eshcol\Ats\Services\WaBridge;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
(new Migrator($app))->run();
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$saved = ['app' => Secrets::get($app, 'wabridge_app_key'), 'auth' => Secrets::get($app, 'wabridge_auth_key'), 'dev' => $app->setting('wa_device_id', ''), 'live' => $app->setting('wa_live', '0')];
$set = fn (string $k, string $v) => $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
$db->run('DELETE FROM whatsapp_queue');
$db->run('DELETE FROM whatsapp_templates');
Secrets::put($app, 'wabridge_app_key', 'zz-app-key-0000000000000000');
Secrets::put($app, 'wabridge_auth_key', 'zzauthkey00000000000000000000');
$set('wa_device_id', 'zzdevice000000000000');
$calls = [];
$status = ['application_received' => 'APPROVED', 'interview_invite' => 'PENDING'];
WaBridge::$transport = function (string $endpoint, array $p) use (&$calls, &$status): array {
    $calls[] = [$endpoint, $p];
    return match ($endpoint) {
        'gettemplate' => [200, json_encode(['status' => true, 'data' => array_map(fn ($n, $s) => ['name' => $n, 'id' => 'T-' . $n, 'status' => $s, 'language' => 'en', 'components' => []], array_keys($status), $status)])],
        'createmessage' => [200, json_encode(['status' => true, 'data' => ['messageid' => 'M-' . mt_rand(1000, 9999)]])],
        'getdlr' => [200, json_encode(['status' => true, 'data' => ['status' => 'read']])],
        default => [404, ''],
    };
};
$mk = fn (string $name, string $phone) => $db->insert('candidates', ['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test', 'phone' => $phone, 'source' => 'manual']);
$a = $mk('Zz Wa Arun', '98765 12701');
$b = $mk('Zz Wa Bala', '+91 98765 12702');
$W = new WaBridge($app);

try {
    $set('wa_live', '0');
    $W->toCandidate($a, 'application_received', ['Arun', 'Trainee Technician']);
    $check('sending off: written to the outbox only', str_contains($W->processQueue(), 'outbox') && !$calls);

    $set('wa_live', '1');
    $W->syncTemplates();
    $q = $W->toCandidate($a, 'application_received', ['Arun', 'Trainee Technician'], 'ack', 1, date('Y-m-d H:i:s', time() + 600));
    $check('the thank-you waits until the email goes (not sent before its time)', $W->processQueue() === '' || !$db->col("SELECT 1 FROM whatsapp_queue WHERE id = ? AND status = 'sent'", [$q]));
    $db->run('UPDATE whatsapp_queue SET not_before = NOW() WHERE id = ?', [$q]);
    $W->processQueue();
    $sent = array_values(array_filter($calls, fn ($c) => $c[0] === 'createmessage'))[0][1] ?? [];
    $check('then sent to 919876512701 with the template ID and values', ($sent['destination_number'] ?? '') === '919876512701' && ($sent['template_id'] ?? '') === 'T-application_received' && ($sent['variables'] ?? []) === ['Arun', 'Trainee Technician']);
    $W->pollDelivery();
    $check('delivery report: read', $db->col('SELECT dlr_status FROM whatsapp_queue WHERE id = ?', [$q]) === 'read');

    $q2 = $W->toCandidate($b, 'interview_invite', ['Bala', 'Trainee Technician with HINE', 'Tuesday 2 December', '10:30 AM', 'Aranvoyal, Thiruvallur']);
    $W->processQueue();
    $check('a template still pending at Meta: held', $db->col('SELECT status FROM whatsapp_queue WHERE id = ?', [$q2]) === 'held');
    $db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$b]);
    $status['interview_invite'] = 'APPROVED';
    $W->syncTemplates();
    $W->release();
    $W->processQueue();
    $check('marked do not contact meanwhile: cancelled, never sent', $db->col('SELECT status FROM whatsapp_queue WHERE id = ?', [$q2]) === 'cancelled');
    $check('and nothing new is queued for them', $W->toCandidate($b, 'interview_reminder', ['x', 'y', 'z']) === null);
    $db->run('UPDATE candidates SET whatsapp_off = 1 WHERE id = ?', [$a]);
    $check('someone who turned WhatsApp off gets nothing', $W->toCandidate($a, 'interview_reminder', ['x', 'y', 'z']) === null);
    $check('a candidate with no usable mobile is skipped', $W->toCandidate($mk('Zz Wa Nophone', '044 2345 6789'), 'application_received', ['x', 'y']) === null);
} finally {
    WaBridge::$transport = null;
    foreach (['app' => 'wabridge_app_key', 'auth' => 'wabridge_auth_key'] as $k => $name) {
        $saved[$k] !== null ? Secrets::put($app, $name, $saved[$k]) : Secrets::forget($app, $name);
    }
    $set('wa_device_id', (string) $saved['dev']);
    $set('wa_live', (string) $saved['live']);
    $db->run('DELETE FROM whatsapp_queue');
    $db->run('DELETE FROM whatsapp_templates');
    $db->run("DELETE FROM candidates WHERE full_name LIKE 'Zz Wa%'");
    foreach (glob($app->storage('wa-outbox') . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
