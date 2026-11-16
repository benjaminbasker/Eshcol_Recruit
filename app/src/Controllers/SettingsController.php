<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Secrets;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\Backup;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Migrator;
use Eshcol\Ats\Services\Retention;

final class SettingsController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $q = trim((string) ($_GET['q'] ?? ''));
        $area = trim((string) ($_GET['area'] ?? ''));
        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = '(canonical LIKE ? OR term LIKE ? OR area LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        if ($area !== '') {
            $where[] = 'canonical IN (SELECT canonical FROM skills_dictionary WHERE area = ?)';
            $args[] = $area;
        }
        // O*NET's job titles and software (about 9,800) show only when you search or pick an area.
        if ($q === '' && $area === '') {
            $where[] = "(source IS NULL OR source <> 'onet')";
        }
        $dict = $this->db->all('SELECT canonical, category, MAX(area) AS area, GROUP_CONCAT(term ORDER BY term SEPARATOR \', \') AS terms FROM skills_dictionary'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY canonical, category ORDER BY category, MAX(area), canonical', $args);
        $areas = array_column($this->db->all('SELECT area, COUNT(DISTINCT canonical) n FROM skills_dictionary WHERE area IS NOT NULL GROUP BY area ORDER BY area'), 'n', 'area');
        $this->view('settings/index', [
            'title' => 'Settings',
            'stages' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM applications a WHERE a.stage_id = s.id) AS used FROM stages s ORDER BY sort'),
            'dict' => $dict,
            'onet' => ['titles' => (int) $this->db->col("SELECT COUNT(*) FROM skills_dictionary WHERE source = 'onet' AND category = 'designation'"),
                'software' => (int) $this->db->col("SELECT COUNT(*) FROM skills_dictionary WHERE source = 'onet' AND category = 'skill'")],
            'newWords' => (int) $this->db->col("SELECT COUNT(*) FROM dictionary_suggestions WHERE status = 'new' AND origin = 'cv'"),
            'q' => $q,
            'area' => $area,
            'areas' => $areas,
            'users' => $this->db->all('SELECT id, name, email, role, totp_enabled, is_active, last_login_at FROM users ORDER BY is_active DESC, role, name'),
            'roleAccess' => (new Migrator($this->app))->pending() ? [] : array_reduce($this->db->all('SELECT user_id, role_id FROM role_access'), function ($c, $r) { $c[(int) $r['user_id']][] = (int) $r['role_id']; return $c; }, []),
            'assignable' => $this->db->all("SELECT r.id, r.title, r.code, r.status, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.is_pool = 0 AND r.status IN ('open','on_hold','draft') ORDER BY cl.name, r.title"),
            'newPassword' => $_SESSION['new_password'] ?? null,
            'retentionMonths' => (new Retention($this->app))->months(),
            'due' => (new Retention($this->app))->due(200),
            'pending' => (new Migrator($this->app))->pending(),
            'cron' => $this->app->setting('cron_heartbeat'),
            // 14 Nov 2026: scheduled jobs (B8), bounces (B1) and consent (B2), once update 037 is applied.
            'jobs' => (new Migrator($this->app))->pending() ? null : ['tasks' => (new \Eshcol\Ats\Services\JobsHealth($this->app))->tasks(), 'queues' => (new \Eshcol\Ats\Services\JobsHealth($this->app))->queues()],
            'emailHealth' => (new Migrator($this->app))->pending() ? null : (new \Eshcol\Ats\Services\EmailHealth($this->app))->summary() + ['url' => (new \Eshcol\Ats\Services\EmailHealth($this->app))->webhookUrl()],
            'consent' => (new Migrator($this->app))->pending() ? null : (new \Eshcol\Ats\Services\Consents($this->app))->summary(),
            'backupLast' => $this->app->setting('backup_last_ok'),
            'backupRemote' => Backup::remote($this->app) !== null,
            'offsite' => (function () { $s = json_decode((string) Secrets::get($this->app, 'backup_r2'), true); return is_array($s) ? ['account_id' => $s['account_id'], 'bucket' => $s['bucket'], 'access_key' => substr($s['access_key'], 0, 6) . '…'] : null; })(),
            'cvSynced' => (int) $this->db->col('SELECT COUNT(*) FROM cv_files WHERE backed_up_at IS NOT NULL'),
            'cvTotal' => (int) $this->db->col('SELECT COUNT(*) FROM cv_files'),
            'filesSynced' => count((array) json_decode((string) $this->app->setting('backup_files_sent'), true)),
            'inbox' => MailIntakeController::viewData($this->app),
            'ai' => AiController::viewData($this->app),
            'routingEnabled' => $this->app->setting('routing_enabled', '1') === '1',
            'autoSort' => $this->app->setting('auto_sort_unassigned', '1') === '1',
            'pools' => $this->app->setting('talent_pools_enabled', '1') === '1',
            'allowBelow' => $this->app->setting('exp_allow_below_default', '1'),
            'allowAbove' => $this->app->setting('exp_allow_above_default', '5'),
            'mail' => [
                'ack' => $this->app->setting('ack_enabled', '1') === '1',
                'ackQuestions' => $this->app->setting('ack_with_questions', '1') === '1',
                'digest' => $this->app->setting('digest_enabled', '1') === '1',
                'live' => $this->app->setting('mail_live', '0') === '1',
                'reallyLive' => (new Mailer($this->app))->live(),
                'cap' => $this->app->setting('mail_daily_cap', '250'),
                'fromEmail' => $this->app->setting('mail_from_email', 'resume@eshcolglobal.com'),
                'fromName' => $this->app->setting('mail_from_name', 'Eshcol Global'),
                'own' => $this->app->setting('mail_own_addresses', ''),
                'hasKey' => Secrets::has($this->app, 'brevo_api_key'),
                'problem' => (new Mailer($this->app))->keyProblem(),
                'lastOk' => $this->app->setting('mail_last_brevo_ok'),
                'local' => $this->app->isLocal(),
            ],
        ]);
    }

    public function saveRetention(): never
    {
        Auth::requireOwner();
        $m = (int) ($_POST['months'] ?? 24);
        if ($m < 6 || $m > 120) {
            Http::flash('error', 'Choose between 6 and 120 months.');
            Http::redirect('/settings#retention');
        }
        $this->db->run('INSERT INTO settings (k, v) VALUES (\'retention_months\', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [(string) $m]);
        $n = (new Retention($this->app))->recalculateAll();
        Audit::log('retention_changed', null, null, ['months' => $m]);
        Http::flash('ok', "Retention set to $m months, counted from each candidate's last contact. $n dates recalculated. Make sure your privacy policy and acknowledgement email say the same.");
        Http::redirect('/settings#retention');
    }

    public function retentionAction(): never
    {
        Auth::requireOwner();
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $retention = new Retention($this->app);
        $dueIds = array_column($retention->due(5000), 'id');
        $ids = array_values(array_intersect($ids, $dueIds));
        if (!$ids) {
            Http::flash('info', 'Nothing selected.');
            Http::redirect('/settings#retention');
        }
        if (($_POST['do'] ?? '') === 'extend') {
            $reason = $this->str('reason', 255) ?: 'Kept on review';
            foreach ($ids as $id) {
                $retention->extend($id, $reason);
            }
            Http::flash('ok', count($ids) . ' kept for another ' . $retention->months() . ' months.');
        } else {
            foreach ($ids as $id) {
                $retention->delete($id, 'expired');
            }
            Http::flash('ok', count($ids) . ' deleted permanently, with their CV files.');
        }
        Http::redirect('/settings#retention');
    }

    public function saveRouting(): never
    {
        Auth::requireOwner();
        foreach (['routing_enabled', 'auto_sort_unassigned', 'talent_pools_enabled'] as $k) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, isset($_POST[$k]) ? '1' : '0']);
        }
        foreach (['exp_allow_below_default', 'exp_allow_above_default'] as $k) {
            if (is_numeric($_POST[$k] ?? null)) {
                $v = (string) (round(max(0, min(20, (float) $_POST[$k])) * 2) / 2);
                $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
            }
        }
        Audit::log('routing_settings_changed');
        Http::flash('ok', 'Routing settings saved.');
        Http::redirect('/settings#routing');
    }

    public function saveEmail(): never
    {
        Auth::requireOwner();
        $set = fn (string $k, string $v) => $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        $from = mb_strtolower(trim((string) ($_POST['mail_from_email'] ?? '')));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !str_ends_with($from, '@eshcolglobal.com')) {
            Http::flash('error', 'The sending address must be an @eshcolglobal.com address (your domain is the one set up in Brevo).');
            Http::redirect('/settings#email');
        }
        $key = trim((string) ($_POST['brevo_api_key'] ?? ''));
        if ($key !== '' && !preg_match('/^xkeysib-[A-Za-z0-9\-]{20,}$/', $key)) {
            Http::flash('error', 'That does not look like a Brevo API key (they start with "xkeysib-"). Nothing was changed.');
            Http::redirect('/settings#email');
        }
        $own = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower((string) ($_POST['mail_own_addresses'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $a) {
            if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $own[] = $a;
            }
        }
        $set('ack_enabled', isset($_POST['ack_enabled']) ? '1' : '0');
        $set('ack_with_questions', isset($_POST['ack_with_questions']) ? '1' : '0');
        $set('digest_enabled', isset($_POST['digest_enabled']) ? '1' : '0');
        $set('mail_from_email', $from);
        $set('mail_from_name', mb_substr(trim((string) ($_POST['mail_from_name'] ?? '')) ?: 'Eshcol Global', 0, 80));
        $set('mail_daily_cap', (string) max(1, min(5000, (int) ($_POST['mail_daily_cap'] ?? 250))));
        $set('mail_own_addresses', implode(', ', array_unique($own)));
        if ($key !== '') {
            Secrets::put($this->app, 'brevo_api_key', $key);
            $this->db->run("DELETE FROM settings WHERE k = 'mail_key_problem'");
            Audit::log('brevo_key_saved');
        }
        $wantLive = isset($_POST['mail_live']);
        if ($wantLive && !Secrets::has($this->app, 'brevo_api_key')) {
            $wantLive = false;
            Http::flash('info', 'Saved, but emails stay in test mode until a Brevo API key is added.');
        } else {
            Http::flash('ok', 'Email settings saved.' . ($wantLive ? ' Emails now go out for real.' : ' Emails are in test mode.'));
        }
        $set('mail_live', $wantLive ? '1' : '0');
        Audit::log('email_settings_changed', null, null, ['live' => $wantLive]);
        Http::redirect('/settings#email');
    }

    /** Sends a sample thank-you email to you, straight away, to check how it looks and that sending works. */
    public function testEmail(): never
    {
        $u = Auth::requireOwner();
        [$subject, $html, $text] = (new Acknowledger($this->app))->render(['first_name' => strtok((string) $u['name'], ' '), 'full_name' => $u['name']], []);
        $m = new Mailer($this->app);
        $id = $m->enqueue(['kind' => 'test', 'to_email' => $u['email'], 'to_name' => $u['name'], 'subject' => "[Test] $subject",
            'html' => $html, 'text' => $text, 'created_by' => (int) $u['id']]);
        $ok = $m->sendRow($this->db->one('SELECT * FROM email_queue WHERE id = ?', [$id]));
        $row = $this->db->one('SELECT last_error FROM email_queue WHERE id = ?', [$id]);
        Http::flash($ok ? 'ok' : 'error', $ok
            ? ($m->live() ? "Test email sent to {$u['email']}. Check your inbox (and spam folder) in a minute." : 'Test mode: the email was written to the server, not sent. You can see it under Emails › Sent.')
            : 'Sending failed: ' . $row['last_error']);
        Http::redirect('/settings#email');
    }

    /** Sends this week's summary to you now, to see what Monday's will look like. */
    public function digestNow(): never
    {
        $u = Auth::requireOwner();
        (new \Eshcol\Ats\Services\Digest($this->app))->send($u);
        $m = new Mailer($this->app);
        $row = $this->db->one("SELECT * FROM email_queue WHERE kind = 'digest' AND to_email = ? ORDER BY id DESC LIMIT 1", [$u['email']]);
        $ok = $m->sendRow($row);
        Http::flash($ok ? 'ok' : 'error', $ok ? ($m->live() ? "This week's summary was sent to {$u['email']}." : "Test mode: the summary was written to the server, not sent. You can read it under Emails › Sent.")
            : 'Sending failed: ' . $this->db->col('SELECT last_error FROM email_queue WHERE id = ?', [$row['id']]));
        Http::redirect('/settings#email');
    }

    /** Saves the Cloudflare R2 details, but only after a test file has been written and removed with them. */
    public function saveOffsite(): never
    {
        Auth::requireOwner();
        if (isset($_POST['forget'])) {
            Secrets::forget($this->app, 'backup_r2');
            Audit::log('offsite_backup_removed');
            Http::flash('info', 'Off-site backup details removed. Backups stay on the server only.');
            Http::redirect('/settings#system');
        }
        $old = json_decode((string) Secrets::get($this->app, 'backup_r2'), true) ?: [];
        $acct = strtolower(trim((string) ($_POST['account_id'] ?? '')));
        // Accept the whole endpoint address too: https://<account id>.r2.cloudflarestorage.com
        if (preg_match('#([0-9a-f]{32})\.r2\.cloudflarestorage\.com#', $acct, $m)) {
            $acct = $m[1];
        }
        $bucket = strtolower(trim((string) ($_POST['bucket'] ?? '')));
        $access = trim((string) ($_POST['access_key'] ?? ''));
        $secret = trim((string) ($_POST['secret_key'] ?? '')) ?: (string) ($old['secret_key'] ?? '');
        $error = match (true) {
            !preg_match('/^[0-9a-f]{32}$/', $acct) => 'The Account ID is 32 letters and numbers (0-9, a-f). It is shown on the R2 overview page.',
            !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket) => 'The bucket name is lower-case letters, numbers and dashes, e.g. eshcol-recruit-backups.',
            !preg_match('/^[A-Za-z0-9]{20,64}$/', $access) => 'The Access Key ID does not look right. Copy it again from the R2 API token page.',
            !preg_match('/^[A-Za-z0-9]{40,128}$/', $secret) => 'The Secret Access Key does not look right. Copy it again from the R2 API token page.',
            default => null,
        };
        if (!$error) {
            // Prove it works before relying on it: write a small file, then remove it.
            try {
                $s3 = \Eshcol\Ats\Services\S3Client::forR2($acct, $bucket, $access, $secret);
                $key = 'test/connection-' . date('Ymd-His') . '.txt';
                $s3->put($key, 'Eshcol Recruit connection test', 'text/plain');
                $s3->delete($key);
            } catch (\Throwable $e) {
                $error = 'Cloudflare refused the details, so nothing was saved: ' . mb_substr($e->getMessage(), 0, 220)
                    . ' Check the bucket name, and that the API token has "Object Read & Write" for this bucket.';
            }
        }
        if ($error) {
            Http::flash('error', $error);
            Http::redirect('/settings#system');
        }
        Secrets::put($this->app, 'backup_r2', json_encode(['account_id' => $acct, 'bucket' => $bucket, 'access_key' => $access, 'secret_key' => $secret]));
        Audit::log('offsite_backup_saved', null, null, ['bucket' => $bucket]);
        Http::flash('ok', 'Connected to Cloudflare R2: a test file was written and removed. Click "Back up now" to send the first backup.');
        Http::redirect('/settings#system');
    }

    /** Runs a backup straight away (and copies CVs off-site), instead of waiting for 2 am. */
    public function backupNow(): never
    {
        Auth::requireOwner();
        @set_time_limit(300);
        try {
            $b = new Backup($this->app);
            $file = $b->runDatabase();
            $cvs = Backup::remote($this->app) ? $b->syncCvs(1000) : 0;
            [$docs] = Backup::remote($this->app) ? $b->syncFiles(1000) : [0];
            $this->db->run('INSERT INTO settings (k, v) VALUES (\'backup_last_ok\', NOW()) ON DUPLICATE KEY UPDATE v = NOW()');
            Audit::log('backup_now');
            Http::flash('ok', 'Backup done: ' . basename($file) . (Backup::remote($this->app) ? ", copied to Cloudflare R2, with $cvs CV file(s) and $docs document/logo file(s)." : ', kept on the server (no off-site copy set up).'));
        } catch (\Throwable $e) {
            Http::flash('error', 'Backup failed: ' . mb_substr($e->getMessage(), 0, 240));
        }
        Http::redirect('/settings#system');
    }

    /** Re-reads every CV with the current reader, filling only what is missing. */
    public function reread(): never
    {
        Auth::requireOwner();
        @set_time_limit(600);
        $r = (new \Eshcol\Ats\Services\Rereader($this->app))->run();
        $names = ['full_name' => 'names', 'email' => 'emails', 'phone' => 'phones', 'current_title' => 'positions', 'exp_months' => 'experience',
            'location' => 'cities', 'locality' => 'localities', 'qualifications' => 'qualifications', 'notice_days' => 'notice periods',
            'ctc_current_lpa' => 'current CTCs', 'ctc_expected_lpa' => 'expected CTCs'];
        $bits = [];
        foreach ($r['filled'] as $k => $n) {
            if (isset($names[$k])) {
                $bits[] = "$n " . $names[$k];
            }
        }
        Http::flash('ok', "Re-read {$r['candidates']} CVs. " . ($bits ? 'Filled in: ' . implode(', ', $bits) . '. ' : 'Nothing was missing that the CVs could tell us. ')
            . ($r['skills'] ? "{$r['skills']} more skills recognised. " : '') . 'Every role they are on has been re-scored. Nothing you or the candidates entered was changed.');
        Http::redirect('/settings#system');
    }

    public function migrate(): never
    {
        Auth::requireOwner();
        // A copy of the database first (B16, 14 Nov 2026): the same encrypted copy as the nightly backup, also sent
        // off-site, so an update can always be undone. If the copy fails, nothing is applied unless you say so.
        $copy = '';
        if ((new Migrator($this->app))->pending() && empty($_POST['without_copy'])) {
            try {
                $copy = basename((new \Eshcol\Ats\Services\Backup($this->app))->runDatabase());
            } catch (\Throwable $e) {
                Http::flash('error', 'Nothing applied: the safety copy of the database could not be made (' . mb_strimwidth($e->getMessage(), 0, 160, '…')
                    . '). Try again in a minute, or press "Apply without a copy" if the nightly backup is recent.');
                $_SESSION['offer_without_copy'] = true;
                Http::redirect('/settings#system');
            }
        }
        unset($_SESSION['offer_without_copy']);
        $applied = (new Migrator($this->app))->run();
        Audit::log('migrations_applied', null, null, ['versions' => $applied, 'copy' => $copy]);
        Http::flash('ok', $applied ? 'Applied ' . count($applied) . ' update(s): ' . implode(', ', $applied) . '.' . ($copy ? ' A copy of the database was saved first.' : '') : 'Already up to date.');
        Http::redirect('/settings#system');
    }

    public function saveStages(): never
    {
        Auth::requireOwner();
        foreach ((array) ($_POST['stage'] ?? []) as $id => $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->db->update('stages', [
                'name' => mb_substr($name, 0, 60),
                'sort' => (int) ($s['sort'] ?? 0),
                'kind' => in_array($s['kind'] ?? '', ['active', 'success', 'fail', 'hold'], true) ? $s['kind'] : 'active',
                'is_active' => isset($s['is_active']) ? 1 : 0,
            ], (int) $id);
        }
        $new = trim((string) ($_POST['new_stage'] ?? ''));
        if ($new !== '') {
            $this->db->insert('stages', ['name' => mb_substr($new, 0, 60), 'sort' => (int) $this->db->col('SELECT COALESCE(MAX(sort),0)+10 FROM stages WHERE kind = \'active\''), 'kind' => 'active']);
        }
        Audit::log('stages_updated');
        Http::flash('ok', 'Stages saved.');
        Http::redirect('/settings#stages');
    }

    public function addSkill(): never
    {
        Auth::requireOwner();
        $canonical = $this->str('canonical', 100);
        $category = in_array($_POST['category'] ?? '', ['skill', 'qualification', 'location', 'designation'], true) ? $_POST['category'] : 'skill';
        // The area the name already has, else the one chosen.
        $area = $this->db->col('SELECT MAX(area) FROM skills_dictionary WHERE canonical = ?', [$canonical]) ?: ($this->str('area', 60) ?: null);
        $added = 0;
        foreach (preg_split('/[\n,]+/', (string) ($_POST['terms'] ?? '')) as $t) {
            $t = mb_strtolower(trim($t));
            if ($canonical && $t !== '' && mb_strlen($t) <= 100) {
                $added += $this->db->run('INSERT IGNORE INTO skills_dictionary (term, canonical, category, area) VALUES (?, ?, ?, ?)', [$t, $canonical, $category, $area])->rowCount();
            }
        }
        if ($canonical) {
            $added += $this->db->run('INSERT IGNORE INTO skills_dictionary (term, canonical, category, area) VALUES (?, ?, ?, ?)', [mb_strtolower($canonical), $canonical, $category, $area])->rowCount();
        }
        Audit::log('dictionary_updated', null, null, ['canonical' => $canonical, 'added' => $added]);
        Http::flash($added ? 'ok' : 'info', $added
            ? "Added $added term" . ($added > 1 ? 's' : '') . " for $canonical. Re-score a role to apply it to CVs already on it."
            : 'Nothing new to add. Those terms are already in the dictionary.');
        Http::redirect('/settings?q=' . urlencode((string) $canonical) . '#dictionary');
    }

    public function addUser(): never
    {
        Auth::requireOwner();
        $name = $this->str('name', 120);
        $email = mb_strtolower((string) $this->str('email', 190));
        $role = in_array($_POST['role'] ?? '', ['owner', 'contractor'], true) ? $_POST['role'] : 'recruiter';
        if ($role === 'contractor' && !array_filter((array) ($_POST['positions'] ?? []))) {
            Http::flash('error', 'Tick the position(s) the contractor will work on.');
            Http::redirect('/settings#users');
        }
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::flash('error', 'Give a name and a valid email address.');
            Http::redirect('/settings#users');
        }
        if (!Mailer::deliverable($email)) {
            Http::flash('error', 'The domain of ' . $email . ' can\'t receive email. Check it for a typo.');
            Http::redirect('/settings#users');
        }
        if ($this->db->col('SELECT id FROM users WHERE email = ?', [$email])) {
            Http::flash('error', 'Someone already uses that email address.');
            Http::redirect('/settings#users');
        }
        $pass = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
        $id = $this->db->insert('users', ['name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => password_hash($pass, PASSWORD_ARGON2ID)]);
        Audit::log('user_created', 'user', $id, ['role' => $role]);
        if ($role === 'contractor') {
            \Eshcol\Ats\Access::assign($id, (array) $_POST['positions'], (int) Auth::user()['id']);
        }
        $_SESSION['new_password'] = ['email' => $email, 'pass' => $pass];
        Http::redirect('/settings#users');
    }

    /** A contractor's positions (B6, 14 Nov 2026). */
    public function userPositions(int $id): never
    {
        $me = Auth::requireOwner();
        $u = $this->findOr404('users', $id);
        if ($u['role'] !== 'contractor') {
            Http::abort(422, 'Only contractors have chosen positions.');
        }
        \Eshcol\Ats\Access::assign($id, (array) ($_POST['positions'] ?? []), (int) $me['id']);
        Http::flash('ok', $u['name'] . '\x27s positions are saved.');
        Http::redirect('/settings#users');
    }

    /** Switches an account off (they can no longer sign in; their history stays) or on again. Never your own. */
    public function userActive(int $id): never
    {
        $me = Auth::requireOwner();
        $u = $this->findOr404('users', $id);
        if ((int) $u['id'] === (int) $me['id']) {
            Http::abort(422, 'You cannot switch off your own account.');
        }
        $on = ($_POST['active'] ?? '') === '1';
        $this->db->run('UPDATE users SET is_active = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        if (!$on) {
            $this->db->run('DELETE FROM trusted_devices WHERE user_id = ?', [$id]);
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['signed_out_before_' . $id, (string) time()]);
        }
        Audit::log($on ? 'user_activated' : 'user_deactivated', 'user', $id);
        Http::flash('ok', $u['name'] . ($on ? ' can sign in again.' : ' can no longer sign in, and any sign-in of theirs has ended. Their history stays.'));
        Http::redirect('/settings#users');
    }

    public function dismissPassword(): never
    {
        Auth::requireOwner();
        unset($_SESSION['new_password']);
        Http::redirect('/settings#users');
    }

    public function account(): never
    {
        $this->view('settings/account', ['title' => 'Your account', 'error' => null, 'emailError' => null,
            'me' => $this->db->one('SELECT name, title, mobile, email FROM users WHERE id = ?', [Auth::user()['id']]),
            'devices' => $this->db->all('SELECT label, created_at, last_used_at, expires_at FROM trusted_devices WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC', [Auth::user()['id']])]);
    }

    /** Every browser you ticked "Trust this device" on will ask for the code again. */
    public function forgetDevices(): never
    {
        $u = Auth::user();
        $n = Auth::forgetDevices((int) $u['id']);
        Audit::log('devices_forgotten', 'user', (int) $u['id'], ['count' => $n]);
        Http::flash('ok', ($n ? "Done. $n trusted device" . ($n > 1 ? 's are' : ' is') . ' forgotten' : 'You had no trusted devices') . ', and every other sign-in of yours has ended. They will need your password and code again.');
        Http::redirect('/account');
    }

    /** Your name, title and mobile, used in signatures on emails, CVs and call letters. */
    public function saveProfile(): never
    {
        $u = Auth::user();
        $name = $this->str('name', 120);
        if (!$name) {
            Http::flash('error', 'Your name can\'t be empty.');
            Http::redirect('/account');
        }
        $this->db->run('UPDATE users SET name = ?, title = ?, mobile = ? WHERE id = ?', [$name, $this->str('title', 120), $this->str('mobile', 30), $u['id']]);
        Audit::log('profile_saved', 'user', (int) $u['id']);
        Http::flash('ok', 'Saved. Your signature is updated everywhere.');
        Http::redirect('/account');
    }

    /** Changes your own sign-in email. Needs your current password; the new address must be able to receive email. */
    public function changeEmail(): never
    {
        $u = Auth::user();
        $row = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        $new = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $error = match (true) {
            !password_verify((string) ($_POST['current'] ?? ''), $row['password_hash']) => 'Your current password is not right.',
            !filter_var($new, FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
            $new === mb_strtolower((string) $u['email']) => 'That is already your email address.',
            !Mailer::deliverable($new) => 'That address\'s domain can\'t receive email. Check it for a typo.',
            (bool) $this->db->col('SELECT id FROM users WHERE email = ? AND id <> ?', [$new, $u['id']]) => 'Someone else already uses that email address.',
            default => null,
        };
        if ($error) {
            $this->view('settings/account', ['title' => 'Your account', 'error' => null, 'emailError' => $error]);
        }
        $this->db->run('UPDATE users SET email = ? WHERE id = ?', [$new, $u['id']]);
        Audit::log('email_changed', 'user', (int) $u['id']);
        session_regenerate_id(true);
        Http::flash('ok', "Your sign-in email is now $new. Use it next time you sign in, and update your password manager.");
        Http::redirect('/account');
    }

    public function changePassword(): never
    {
        $u = Auth::user();
        $row = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $error = !password_verify($current, $row['password_hash']) ? 'Your current password is not right.'
            : (Auth::validPassword($new) ?? ($new !== (string) ($_POST['confirm'] ?? '') ? 'The two new passwords do not match.' : null));
        if ($error) {
            $this->view('settings/account', ['title' => 'Your account', 'error' => $error, 'emailError' => null]);
        }
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $u['id']]);
        // A new password also withdraws trust from every remembered browser.
        Auth::forgetDevices((int) $u['id']);
        Audit::log('password_changed', 'user', (int) $u['id']);
        session_regenerate_id(true);
        Http::flash('ok', 'Password changed. Any trusted devices will ask for your code again.');
        Http::redirect('/');
    }
}
