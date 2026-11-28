<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\WaBridge;
use Eshcol\Ats\Services\WaInbox;

/** Settings › WhatsApp: the WABridge keys (typed by the owner), the device, sending on or off, templates, a test. */
final class WaBridgeController extends Controller
{
    private function back(?string $err, string $ok): never
    {
        Http::flash($err ? 'error' : 'ok', $err ?? $ok);
        Http::redirect('/settings/whatsapp');
    }

    public function index(): never
    {
        Auth::requireOwner();
        $W = new WaBridge($this->app);
        $this->view('settings/whatsapp', ['title' => 'WhatsApp', 'wa' => ['has_keys' => Secrets::has($this->app, 'wabridge_app_key') && Secrets::has($this->app, 'wabridge_auth_key'),
            'device' => (string) $this->app->setting('wa_device_id', ''), 'configured' => $W->configured(), 'live' => $this->app->setting('wa_live', '0') === '1',
            'templates' => $W->templates(), 'recent' => $W->recent(25), 'synced' => $this->db->col('SELECT MAX(synced_at) FROM whatsapp_templates'),
            'auto' => ['selected' => $this->app->setting('wa_auto_selected', '1') === '1', 'not_selected' => $this->app->setting('wa_auto_not_selected', '1') === '1',
                'joining_time' => date('H:i', strtotime('2000-01-01 ' . $this->app->setting('joining_report_time', '9:00 AM')))],
            'queued' => (int) $this->db->col("SELECT COUNT(*) FROM whatsapp_queue WHERE status = 'queued'"), 'held' => (int) $this->db->col("SELECT COUNT(*) FROM whatsapp_queue WHERE status = 'held'"),
            'details' => $this->detailsBox(), 'webhook' => (new WaInbox($this->app))->webhookUrl(), 'replies' => (new WaInbox($this->app))->recent(25)]]);
    }

    public function save(): never
    {
        Auth::requireOwner();
        $app = trim((string) ($_POST['app_key'] ?? ''));
        $auth = trim((string) ($_POST['auth_key'] ?? ''));
        $dev = trim((string) ($_POST['device_id'] ?? ''));
        if ($app !== '' && !preg_match('/^[A-Za-z0-9-]{20,80}$/', $app)) {
            $this->back('The App Key does not look right. Copy it again from WABridge, Profile Settings, API & Integrations.', '');
        }
        if ($auth !== '' && !preg_match('/^[A-Za-z0-9]{20,100}$/', $auth)) {
            $this->back('The Auth Key does not look right. Copy it again from the same page.', '');
        }
        if ($dev !== '' && !preg_match('/^[A-Za-z0-9]{10,40}$/', $dev)) {
            $this->back('The Device ID is the long code on WABridge, My Devices (not the phone ID).', '');
        }
        $app !== '' && Secrets::put($this->app, 'wabridge_app_key', $app);
        $auth !== '' && Secrets::put($this->app, 'wabridge_auth_key', $auth);
        foreach (['wa_device_id' => $dev, 'wa_live' => !empty($_POST['live']) ? '1' : '0'] as $k => $v) {
            if ($k === 'wa_live' || $v !== '') {
                $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
            }
        }
        Audit::log('whatsapp_settings_saved', 'setting', null, ['live' => !empty($_POST['live']), 'keys_changed' => $app !== '' || $auth !== '']);
        $this->back(null, !empty($_POST['live']) ? 'Saved. Approved templates now go out on WhatsApp.' : 'Saved. Messages stay in the outbox until you switch sending on.');
    }

    /** Which stage changes send a WhatsApp on their own, and the reporting time in the joining reminder. */
    public function auto(): never
    {
        Auth::requireOwner();
        $time = trim((string) ($_POST['joining_time'] ?? ''));
        $time = preg_match('/^\d{1,2}:\d{2}$/', $time) ? date('g:i A', strtotime("2000-01-01 $time")) : '9:00 AM';
        foreach (['wa_auto_selected' => !empty($_POST['selected']) ? '1' : '0', 'wa_auto_not_selected' => !empty($_POST['not_selected']) ? '1' : '0', 'joining_report_time' => $time] as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Audit::log('whatsapp_auto_saved', 'setting', null, ['selected' => !empty($_POST['selected']), 'not_selected' => !empty($_POST['not_selected'])]);
        $this->back(null, 'Saved.');
    }

    /** "Remind about the details form": queue questions_request for everyone still waiting on the ticked positions, at the chosen time. */
    public function detailsReminders(): never
    {
        Auth::requireOwner();
        $roles = array_map('intval', (array) ($_POST['roles'] ?? []));
        $when = strtotime(str_replace('T', ' ', (string) ($_POST['when'] ?? '')));
        if (!$roles) {
            $this->back('Tick at least one position.', '');
        }
        if (!$when || $when < time() - 60) {
            $this->back('Choose a time from now on.', '');
        }
        $n = (new \Eshcol\Ats\Services\WaMessages($this->app))->queueDetailsReminders($roles, date('Y-m-d H:i:s', $when));
        $this->back(null, $n ? "$n reminder(s) queued for " . date('D j M, g:i a', $when) . '. Cancel them below if you change your mind.' : 'Nobody to remind.');
    }

    public function cancelDetailsReminders(): never
    {
        Auth::requireOwner();
        $n = (new \Eshcol\Ats\Services\WaMessages($this->app))->cancelDetailsReminders();
        $this->back(null, "$n reminder(s) cancelled. Nothing was sent.");
    }
    public function sync(): never
    {
        Auth::requireOwner();
        $W = new WaBridge($this->app);
        [$n, $err] = $W->syncTemplates();
        $released = $err ? 0 : $W->release();
        $this->back($err, "$n template(s) from WABridge." . ($released ? " $released waiting message(s) released." : ''));
    }

    /** Send one approved template to a number, with values typed in, to see it arrive. Sent straight away. */
    public function test(): never
    {
        Auth::requireOwner();
        $W = new WaBridge($this->app);
        if (!$W->live()) {
            $this->back('Switch sending on first (and sync the templates).', '');
        }
        $to = WaBridge::number((string) ($_POST['to'] ?? ''));
        if (!$to) {
            $this->back('Enter a 10-digit Indian mobile number.', '');
        }
        $vars = array_values(array_filter(array_map('trim', explode('|', (string) ($_POST['values'] ?? ''))), fn ($v) => $v !== ''));
        $btn = trim((string) ($_POST['button'] ?? ''));
        $id = $W->enqueue($to, (string) ($_POST['template'] ?? ''), $vars, $btn !== '' ? [$btn] : [], 'test', null);
        $out = $W->processQueue(5);
        $row = $this->db->one('SELECT status, last_error FROM whatsapp_queue WHERE id = ?', [$id]);
        $this->back(($row['status'] ?? '') === 'sent' ? null : ('Not sent: ' . ($row['last_error'] ?? $out)), 'Sent. Check the phone; the delivery report follows within a few minutes.');
    }

    /** WABridge's webhook. A GET (a check that the address works) echoes any challenge; a POST is stored. */
    public function hook(string|int $token): never
    {
        $in = new WaInbox($this->app);
        if (!$in->validToken((string) $token)) {
            Http::json(['ok' => false], 404);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $ch = (string) ($_GET['hub_challenge'] ?? $_GET['challenge'] ?? '');
            if ($ch !== '' && preg_match('/^[A-Za-z0-9_.-]{1,200}$/', $ch)) {
                header('Content-Type: text/plain; charset=utf-8');
                echo $ch;
                exit;
            }
            Http::json(['ok' => true]);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 262144);
        Http::json(['ok' => true, 'recorded' => $in->record($raw)]);
    }

    /** The open positions with how many are waiting on the details form, and what is already queued. */
    private function detailsBox(): array
    {
        $M = new \Eshcol\Ats\Services\WaMessages($this->app);
        $roles = $this->db->all("SELECT r.id, r.title, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.status = 'open' AND r.is_pool = 0 ORDER BY r.title");
        foreach ($roles as &$r) {
            $r['waiting'] = count($M->detailsWaiting([(int) $r['id']]));
        }
        unset($r);
        [$n, $t] = $M->detailsRemindersQueued();
        return ['roles' => $roles, 'queued' => $n, 'at' => $t];
    }
}
