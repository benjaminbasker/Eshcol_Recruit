<?php
/**
 * The one scheduled job. cPanel runs it every 5 minutes; it decides what is due.
 * Your host allows at most 5 cron jobs, so everything shares this single slot.
 *
 *   cPanel > Cron Jobs > Once Per Five Minutes:
 *   /usr/local/bin/php /home/benjaminbasker/eshcol-ats/bin/cron.php
 *
 * Usage locally: php bin/cron.php [--force=task]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Backup;
use Eshcol\Ats\Services\Mailer;
use Eshcol\Ats\Services\Retention;

$app = App::boot(cli: true);
$db = $app->db;
@set_time_limit(240);

// Never run two at once (a slow backup must not overlap the next tick).
$lock = fopen($app->storage('cron.lock'), 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$tickStart = microtime(true); // jobs late in the tick size their work to the time left

$force = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--force=')) {
        $force = substr($a, 8);
    }
}
$log = function (string $msg) use ($app): void {
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    file_put_contents($app->storage('logs/cron.log'), $line, FILE_APPEND);
    if (PHP_SAPI === 'cli' && stream_isatty(STDOUT)) {
        echo $line;
    }
};
$last = fn (string $task) => $app->setting("cron_last_$task");
$mark = fn (string $task) => $db->run('INSERT INTO settings (k, v) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE v = NOW()', ["cron_last_$task"]);
$dueDaily = fn (string $task, int $hour) => $force === $task || ((int) date('G') >= $hour && substr((string) $last($task), 0, 10) !== date('Y-m-d'));
$dueEvery = fn (string $task, int $minutes) => $force === $task || !$last($task) || strtotime((string) $last($task)) <= time() - $minutes * 60;

// Each task: [is it due?, what it does]. A failure is logged and never stops the others.
$tasks = [
    'backup' => [fn () => $dueDaily('backup', 2), function () use ($app) {
        $f = (new Backup($app))->runDatabase();
        return 'database backup ' . basename($f) . (Backup::remote($app) ? ' (copied off-site)' : ' (local only)');
    }],
    'cv_sync' => [fn () => $dueEvery('cv_sync', 60), function () use ($app) {
        $b = new Backup($app);
        $n = $b->syncCvs(200);
        [$docs, $gone] = $b->syncFiles(200);
        $gone += (new \Eshcol\Ats\Services\Migrator($app))->pending() ? 0 : $b->retryDeletions(); // deleted candidates' CV copies (audit R4)
        return Backup::remote($app) ? "$n CV file(s), $docs document/logo file(s) copied off-site" . ($gone ? ", $gone removed" : '') : 'skipped (off-site copy not set up)';
    }],
    // Waits while a database update is pending, so a message is never lost to a table not yet made.
    'mail_intake' => [fn () => \Eshcol\Ats\Services\MailIntake::enabled($app) && $dueEvery('mail_intake', 5) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        try {
            $s = (new \Eshcol\Ats\Services\MailIntake($app))->run(25);
            $app->db->run("INSERT INTO settings (k, v) VALUES ('mail_intake_last_error', '') ON DUPLICATE KEY UPDATE v = ''");
            return $s;
        } catch (\Throwable $e) {
            $app->db->run('INSERT INTO settings (k, v) VALUES (\'mail_intake_last_error\', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [date('Y-m-d H:i') . ' ' . mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }],
    // Claude reads new CVs (and any re-read in progress) before the thank-you emails go out.
    'ai_read' => [fn () => \Eshcol\Ats\Services\AiReading::enabled($app) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $ai = new \Eshcol\Ats\Services\AiReading($app);
        $once = '';
        if ($app->setting('cv_not_before_backfilled') === null && \Eshcol\Ats\Services\FitAssessor::enabled($app)) {
            $once = $ai->backfillNotBefore() . ' CV(s) dated from their current job; ';
            $app->db->run("INSERT INTO settings (k, v) VALUES ('cv_not_before_backfilled', '1') ON DUPLICATE KEY UPDATE v = '1'");
        }
        return $once . $ai->runBatch(40, 120);
    }],
    // Then each newly read CV is categorised for future searches (field, seniority, where they'd work):
    // Claude Haiku, about ₹0.03 each. In trial the new routing is only recorded; switched on, it routes.
    'categorise' => [fn () => \Eshcol\Ats\Services\Categoriser::mode($app) !== 'off' && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app, $tickStart) {
        // 60 seconds while Claude is still reading CVs; up to 150 once the reading queue is empty (a big upload
        // then categorises about twice as fast, at the same cost). Never past ~200 s into the 240 s tick.
        $readingIdle = !(int) $app->db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status IN ('pending','reading')");
        $left = (int) (200 - (microtime(true) - $tickStart));
        return (new \Eshcol\Ats\Services\Categoriser($app))->runBatch($readingIdle ? 80 : 40, max(15, min($readingIdle ? 150 : 60, $left)));
    }],
    // Is each application the same kind of work as its position? (Claude Haiku, the whole CV against the whole job description; 27 Nov 2026)
    'suitability' => [fn () => \Eshcol\Ats\Services\SuitabilityCheck::mode($app) !== 'off' && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        return (new \Eshcol\Ats\Services\SuitabilityCheck($app))->runBatch(40, 60);
    }],
    // Then Claude assesses the candidates the free check found relevant to their roles:
    // in half-price batches (results within the hour, usually), or one by one when batches are off.
    'fit' => [fn () => \Eshcol\Ats\Services\FitAssessor::enabled($app) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $fit = new \Eshcol\Ats\Services\FitAssessor($app);
        // The fair check of the top 10 (analyser 7) always goes in a half-price batch, whatever the setting.
        $fair = new \Eshcol\Ats\Services\FairCheck($app);
        $fairDone = \Eshcol\Ats\Services\Agreement::extrasOn($app) ? implode('; ', array_filter([$fair->collect(), $fair->submit()])) : '';
        if (!$fit->batchOn()) {
            return implode('; ', array_filter([$fit->runBatch(25, 90), $fairDone]));
        }
        return implode('; ', array_filter([$fit->collectBatches(), $fit->submitBatch(100), $fairDone]));
    }],
    'retention' => [fn () => $dueDaily('retention', 3), function () use ($app) {
        $n = (new Retention($app))->dueCount();
        $app->db->run('INSERT INTO settings (k, v) VALUES (\'retention_due\', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [(string) $n]);
        return "$n candidate(s) due for review";
    }],
    'mail' => [fn () => true, function () use ($app) {
        // Every tick: send what is due, within today's ceiling (once the email update is applied).
        if ((new \Eshcol\Ats\Services\Migrator($app))->pending()) {
            return '';
        }
        return (new Mailer($app))->processQueue();
    }],
    'cv_requests' => [fn () => $dueEvery('cv_requests', 15) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        // "Share your updated CV": reminders, then new emails, within 175 a day, 9 am to 7 pm.
        return (new \Eshcol\Ats\Services\CvRequests($app))->tick();
    }],
    'questionnaires' => [fn () => $dueEvery('questionnaires', 60), function () use ($app) {
        if ((new \Eshcol\Ats\Services\Migrator($app))->pending()) {
            return '';
        }
        // One reminder after a few days; links expire after two weeks.
        return (new \Eshcol\Ats\Services\Questionnaires($app))->housekeeping();
    }],
    'digest' => [fn () => $force === 'digest' || (date('N') === '1' && (int) date('G') >= 8 && substr((string) $last('digest'), 0, 10) !== date('Y-m-d')), function () use ($app) {
        if ((new \Eshcol\Ats\Services\Migrator($app))->pending() || $app->setting('digest_enabled', '1') !== '1') {
            return '';
        }
        // The Monday summary to the owners.
        return 'weekly summary queued for ' . (new \Eshcol\Ats\Services\Digest($app))->send() . ' owner(s)';
    }],
    // The 6 pm email to each client with the interviews booked that day (17 Nov 2026).
    'client_digest' => [fn () => $dueDaily('client_digest', \Eshcol\Ats\Services\Interviews::DIGEST_HOUR) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        return (new \Eshcol\Ats\Services\Interviews($app))->clientDigest();
    }],
    'interviews' => [fn () => true, function () use ($app) {
        if ((new \Eshcol\Ats\Services\Migrator($app))->pending()) {
            return '';
        }
        // The reminder before each interview (4 hours by default).
        return (new \Eshcol\Ats\Services\Interviews($app))->reminders();
    }],
    // Once, after update 026: LinkedIn profiles for the CVs already on file (free).
    'linkedin_backfill' => [fn () => $app->setting('linkedin_backfilled') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $n = (new \Eshcol\Ats\Services\CandidateImporter($app))->backfillLinkedin();
        $app->db->run("INSERT INTO settings (k, v) VALUES ('linkedin_backfilled', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        return "$n LinkedIn profile(s) found in CVs already on file";
    }],
    // Once, after the scoring change of 10 Nov 2026 (experience rises through the band; the qualification
    // ladder in the free score): every score worked out again. Free: Claude's stored answers are re-added.
    'rescore_20261110' => [fn () => $app->setting('rescored_20261110') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $app->db->run("INSERT INTO settings (k, v) VALUES ('rescored_20261110', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        $imp = new \Eshcol\Ats\Services\CandidateImporter($app);
        $n = 0;
        foreach ($app->db->all('SELECT id FROM applications') as $a) {
            $imp->scoreApplication((int) $a['id']);
            $n++;
        }
        [$c, $ch] = (new \Eshcol\Ats\Services\FitAssessor($app))->rescoreAll();
        \Eshcol\Ats\Audit::log('rescored', null, null, ['free' => $n, 'claude' => $c, 'claude_changed' => $ch]);
        return "$n free score(s) and $c Claude assessment(s) worked out again ($ch changed)";
    }],
    // Once, after the recency rule of 10 Nov 2026 (requirements met only in older jobs: ¾ points).
    // Free: Claude's stored answers are re-added.
    'rescore_20261111' => [fn () => $app->setting('rescored_20261110') !== null && $app->setting('rescored_20261111') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $app->db->run("INSERT INTO settings (k, v) VALUES ('rescored_20261111', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        [$c, $ch] = (new \Eshcol\Ats\Services\FitAssessor($app))->rescoreAll();
        \Eshcol\Ats\Audit::log('rescored', null, null, ['claude' => $c, 'claude_changed' => $ch, 'why' => 'recency']);
        return "$c Claude assessment(s) worked out again for recency ($ch changed)";
    }],
    // Once, after update 034: the words in CVs Claude has already read that the dictionary doesn't know (free).
    'new_terms_backfill' => [fn () => $app->setting('new_terms_backfilled') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $n = (new \Eshcol\Ats\Services\NewTerms($app))->backfill();
        // Marked done only once it has worked, so a failure is tried again on the next run.
        $app->db->run("INSERT INTO settings (k, v) VALUES ('new_terms_backfilled', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        $w = (int) $app->db->col("SELECT COUNT(*) FROM dictionary_suggestions WHERE status = 'new'");
        return "$n CV reading(s) checked for new words; $w waiting in Settings › New words";
    }],
    // Once, after the recency rule was taken out again (10 Nov 2026, Benjamin): full credit whenever the
    // experience was. Free: Claude's stored answers are re-added.
    'rescore_20261112' => [fn () => $app->setting('rescored_20261111') !== null && $app->setting('rescored_20261112') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $app->db->run("INSERT INTO settings (k, v) VALUES ('rescored_20261112', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        [$c, $ch] = (new \Eshcol\Ats\Services\FitAssessor($app))->rescoreAll();
        \Eshcol\Ats\Audit::log('rescored', null, null, ['claude' => $c, 'claude_changed' => $ch, 'why' => 'recency removed']);
        return "$c Claude assessment(s) worked out again without the recency rule ($ch changed)";
    }],
    // Once, after update 027: where candidates already on file came from (free).
    'channel_backfill' => [fn () => $app->setting('channels_backfilled') === null && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $n = (new \Eshcol\Ats\Services\CandidateImporter($app))->backfillChannels();
        $app->db->run("INSERT INTO settings (k, v) VALUES ('channels_backfilled', NOW()) ON DUPLICATE KEY UPDATE v = NOW()");
        return "source found for $n candidate(s) already on file";
    }],
    'cleanup' => [fn () => $dueEvery('cleanup', 60), function () use ($app) {
        $n = 0;
        foreach (glob($app->storage('uploads-tmp/*')) ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600 && @unlink($f)) {
                $n++;
            }
        }
        $app->db->run('DELETE FROM auth_throttle WHERE window_start < NOW() - INTERVAL 1 DAY');
        return "$n temporary file(s) removed";
    }],
    // The morning sweep (23 Nov 2026): "Add all relevant" for each position that has it on, so an application whose
    // email named no position is still found. Claude's assessments follow in the fit task.
    'db_sweep' => [fn () => $dueDaily('db_sweep', \Eshcol\Ats\Services\DatabaseSweep::HOUR) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        $uid = (int) $app->db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
        return $uid ? (new \Eshcol\Ats\Services\DatabaseSweep($app))->runDaily($uid) : 'no owner to add them as';
    }],
    // Reading back from the HRMS (28 Nov 2026): its statutory rates and the people leaving, once each morning.
    'hrms_feeds' => [fn () => $dueDaily('hrms_feeds', 6) && !(new \Eshcol\Ats\Services\Migrator($app))->pending(), function () use ($app) {
        return (new \Eshcol\Ats\Services\HrmsLink($app))->fetchFeeds();
    }],
    'audit_trim' => [fn () => $dueDaily('audit_trim', 4), function () use ($app) {
        // The activity log is kept for two years (India's rules ask for at least one).
        $n = $app->db->run('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 2 YEAR')->rowCount();
        return "$n activity log entries older than 2 years removed";
    }],
];

// Emails go before the slow work (reading CVs, Claude), so a long reading never delays them (audit F8, 14 Nov 2026).
$tasks = ['mail' => $tasks['mail']] + $tasks;
// Every run is recorded for Settings › System › Scheduled jobs (B8, 14 Nov 2026), once the table exists.
$runs = !(new \Eshcol\Ats\Services\Migrator($app))->pending();
foreach ($tasks as $name => [$isDue, $run]) {
    if (!$isDue()) {
        continue;
    }
    $t0 = microtime(true);
    $started = date('Y-m-d H:i:s');
    try {
        $out = (string) $run();
        if ($out !== '') {
            $log("$name: $out");
        }
        $mark($name);
        $runs && $db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, ?, ?, 1, ?)', [$name, $started, round(microtime(true) - $t0, 2), $out !== '' ? mb_substr($out, 0, 500) : null]);
    } catch (Throwable $e) {
        $log("$name FAILED: " . $e->getMessage());
        $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ["cron_error_$name", date('Y-m-d H:i:s') . ' ' . mb_substr($e->getMessage(), 0, 200)]);
        $runs && $db->run('INSERT INTO job_runs (task, started_at, seconds, ok, message) VALUES (?, ?, ?, 0, ?)', [$name, $started, round(microtime(true) - $t0, 2), mb_substr($e->getMessage(), 0, 500)]);
    }
}
$runs && $db->run('DELETE FROM job_runs WHERE started_at < NOW() - INTERVAL 14 DAY');
$db->run('INSERT INTO settings (k, v) VALUES (\'cron_heartbeat\', NOW()) ON DUPLICATE KEY UPDATE v = NOW()');
flock($lock, LOCK_UN);
