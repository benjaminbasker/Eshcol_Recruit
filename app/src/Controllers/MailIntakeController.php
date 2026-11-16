<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\MailIntake;

/** Settings › CV inbox: connect resume@ (Microsoft 365) so emailed CVs come in by themselves. */
final class MailIntakeController extends Controller
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Data for the card on the Settings page. */
    public static function viewData(\Eshcol\Ats\App $app): array
    {
        $cfg = MailIntake::config($app);
        return [
            'cfg' => $cfg ? ['tenant_id' => $cfg['tenant_id'], 'client_id' => $cfg['client_id'], 'mailbox' => $cfg['mailbox']] : null,
            'on' => $app->setting('mail_intake_on') === '1',
            'ack' => $app->setting('mail_intake_ack', '1') === '1',
            'lastOk' => $app->setting('mail_intake_last_ok'),
            'lastError' => $app->setting('mail_intake_last_error'),
            'since' => $app->setting('mail_intake_since'),
            'recent' => $app->db->all('SELECT m.*, r.title AS role_title FROM mail_intake m LEFT JOIN roles r ON r.id = m.role_id ORDER BY m.id DESC LIMIT 15'),
            'today' => (int) $app->db->col("SELECT COUNT(*) FROM mail_intake WHERE outcome = 'imported' AND created_at >= CURDATE()"),
        ];
    }

    public function save(): never
    {
        Auth::requireOwner();
        $old = MailIntake::config($this->app) ?? [];
        $tenant = strtolower(trim((string) ($_POST['tenant_id'] ?? '')));
        $client = strtolower(trim((string) ($_POST['client_id'] ?? '')));
        $secret = trim((string) ($_POST['client_secret'] ?? '')) ?: (string) ($old['client_secret'] ?? '');
        $box = mb_strtolower(trim((string) ($_POST['mailbox'] ?? '')));
        $from = trim((string) ($_POST['start_from'] ?? ''));
        $error = match (true) {
            !preg_match(self::GUID, $tenant) => 'The Directory (tenant) ID looks like 8-4-4-4-12 letters and numbers. Copy it from the app\'s Overview page.',
            !preg_match(self::GUID, $client) => 'The Application (client) ID looks like 8-4-4-4-12 letters and numbers. Copy it from the app\'s Overview page.',
            strlen($secret) < 20 || preg_match(self::GUID, $secret) => 'Paste the client secret\'s Value (shown once when you create it), not its Secret ID.',
            !filter_var($box, FILTER_VALIDATE_EMAIL) => 'Enter the mailbox address, e.g. resume@eshcolglobal.com.',
            $from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) => 'Pick a valid "bring in emails from" date.',
            default => null,
        };
        $cfg = ['tenant_id' => $tenant, 'client_id' => $client, 'client_secret' => $secret, 'mailbox' => $box];
        if (!$error) {
            try {
                $msg = (new MailIntake($this->app))->test($cfg);
            } catch (\Throwable $e) {
                $error = 'Not saved. ' . mb_substr($e->getMessage(), 0, 300);
            }
        }
        if ($error) {
            Http::flash('error', $error);
            Http::redirect('/settings#inbox');
        }
        Secrets::put($this->app, 'mail_intake', json_encode($cfg));
        $this->set('mail_intake_on', '1');
        $this->set('mail_intake_ack', isset($_POST['ack']) ? '1' : '0');
        if ($from !== '') {
            $this->set('mail_intake_since', gmdate('Y-m-d\TH:i:s\Z', (int) strtotime("$from 00:00:00")));
        } elseif (!$this->app->setting('mail_intake_since')) {
            $this->set('mail_intake_since', gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400));
        }
        $this->set('mail_intake_last_error', '');
        Audit::log('mail_intake_connected', null, null, ['mailbox' => $box]);
        Http::flash('ok', $msg . ' CVs emailed there will now come in every few minutes. Click "Check now" to bring them in straight away.');
        Http::redirect('/settings#inbox');
    }

    public function action(): never
    {
        Auth::requireOwner();
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'check') {
            @set_time_limit(300);
            try {
                $summary = (new MailIntake($this->app))->run(50);
                $this->set('mail_intake_last_error', '');
                Http::flash('ok', 'Checked the inbox: ' . $summary . '.');
            } catch (\Throwable $e) {
                $this->set('mail_intake_last_error', date('Y-m-d H:i') . ' ' . mb_substr($e->getMessage(), 0, 300));
                Http::flash('error', 'Could not check the inbox: ' . mb_substr($e->getMessage(), 0, 300));
            }
        } elseif ($do === 'pause' || $do === 'resume') {
            $this->set('mail_intake_on', $do === 'resume' ? '1' : '0');
            Audit::log('mail_intake_' . $do);
            Http::flash('ok', $do === 'resume' ? 'CV inbox switched on.' : 'CV inbox paused. Emails wait in the mailbox until you switch it back on.');
        } elseif ($do === 'remove') {
            Secrets::forget($this->app, 'mail_intake');
            $this->set('mail_intake_on', '0');
            Audit::log('mail_intake_removed');
            Http::flash('info', 'Disconnected. Also delete the client secret in Microsoft Entra if you no longer need it.');
        } elseif ($do === 'ack') {
            $this->set('mail_intake_ack', isset($_POST['ack']) ? '1' : '0');
            Http::flash('ok', 'Saved.');
        }
        Http::redirect('/settings#inbox');
    }

    private function set(string $k, string $v): void
    {
        $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
}
