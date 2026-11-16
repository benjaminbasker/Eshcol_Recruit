<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Scheduled jobs health (B8, 14 Nov 2026): the five-minute job's tasks, when each last ran, how long it
 * took, and whether it failed; plus the queues that build up when something is stuck. Shown in
 * Settings › System; a task that failed twice in a row also shows on My day.
 */
final class JobsHealth
{
    public const NAMES = [
        'mail' => 'Sending emails', 'mail_intake' => 'Reading the resume@ inbox', 'ai_read' => 'Claude reading CVs', 'categorise' => 'Categorising CVs',
        'fit' => "Claude's assessments (batches)", 'questionnaires' => 'Details-request reminders', 'cv_requests' => '"Share your updated CV" emails', 'interviews' => 'Interview reminders', 'backup' => 'Nightly backup',
        'cv_sync' => 'Off-site copy of CVs', 'retention' => 'Keeping-period check', 'digest' => 'Monday summary', 'cleanup' => 'Clean-up', 'audit_trim' => 'Activity log trim',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** Each task: its last run, its last failure, and failures in a row. */
    public function tasks(): array
    {
        $db = $this->app->db;
        $out = [];
        foreach ($db->all('SELECT task, MAX(id) AS last FROM job_runs GROUP BY task') as $r) {
            $last = $db->one('SELECT * FROM job_runs WHERE id = ?', [$r['last']]);
            $recent = $db->all('SELECT ok FROM job_runs WHERE task = ? ORDER BY id DESC LIMIT 5', [$r['task']]);
            $streak = 0;
            foreach ($recent as $x) {
                if ((int) $x['ok'] === 1) {
                    break;
                }
                $streak++;
            }
            $out[$r['task']] = ['name' => self::NAMES[$r['task']] ?? $r['task'], 'last' => $last, 'failing' => $streak,
                'last_fail' => $db->one('SELECT started_at, message FROM job_runs WHERE task = ? AND ok = 0 ORDER BY id DESC LIMIT 1', [$r['task']]),
                'runs_24h' => (int) $db->col('SELECT COUNT(*) FROM job_runs WHERE task = ? AND started_at > NOW() - INTERVAL 1 DAY', [$r['task']]),
                'slowest_24h' => (float) $db->col('SELECT MAX(seconds) FROM job_runs WHERE task = ? AND started_at > NOW() - INTERVAL 1 DAY', [$r['task']])];
        }
        uksort($out, fn ($a, $b) => array_search($a, array_keys(self::NAMES), true) <=> array_search($b, array_keys(self::NAMES), true));
        return $out;
    }

    /** What is waiting in each queue right now. */
    public function queues(): array
    {
        $db = $this->app->db;
        $n = fn (string $sql) => (int) $db->col($sql);
        return [
            'Emails waiting to send' => $n("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'"),
            'Emails failed (last 7 days)' => $n("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY"),
            'CVs waiting for Claude to read' => $n("SELECT COUNT(*) FROM cv_ai_reads WHERE status IN ('pending','reading')"),
            'CVs waiting to be categorised' => $n("SELECT COUNT(*) FROM categorise_queue WHERE status IN ('pending','working')"),
            'Assessments waiting' => $n("SELECT COUNT(*) FROM fit_assessments WHERE status = 'pending'"),
            'Assessments with Claude (batch)' => $n("SELECT COUNT(*) FROM fit_assessments WHERE status = 'batched'"),
        ];
    }

    /** Tasks that failed at least twice in a row, for My day. */
    public function failing(): array
    {
        return array_filter($this->tasks(), fn ($t) => $t['failing'] >= 2);
    }
}
