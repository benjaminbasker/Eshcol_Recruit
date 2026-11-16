<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CvReader;

/** Settings › AI reading (owner only): Claude reads every CV; re-read all; approve changes. */
final class AiReadingController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $ai = new AiReading($this->app);
        $this->view('settings/ai-reading', [
            'hasKey' => CvReader::available($this->app),
            'on' => $this->app->setting('ai_read_enabled', '0') === '1',
            'cheap' => $this->app->setting('ai_read_cheap', '0') === '1',
            'cache' => ['read' => (int) $this->app->setting('ai_cache_read_' . date('Ym'), '0'), 'write' => (int) $this->app->setting('ai_cache_write_' . date('Ym'), '0'),
                'saved' => (float) $this->app->setting('ai_cache_saved_' . date('Ym'), '0')],
            'batch' => $this->app->setting('fit_batch', '0') === '1',
            'limit' => $ai->limit(),
            'used' => $ai->usedThisMonth(),
            'cost' => $ai->costThisMonth(),
            'counts' => $ai->counts(),
            'waiting' => $ai->waitingForApproval(),
            'names' => $ai->nameChecks(),
            'failed' => $this->db->all("SELECT r.id, r.candidate_id, r.error, c.full_name FROM cv_ai_reads r JOIN candidates c ON c.id = r.candidate_id WHERE r.status = 'failed' ORDER BY r.id DESC LIMIT 50"),
        ]);
    }

    /** "Names to check": applies the corrections you ticked (14 Nov 2026). */
    public function names(): never
    {
        Auth::requireOwner();
        $n = (new AiReading($this->app))->applyNames((array) ($_POST['fix'] ?? []), (int) Auth::user()['id']);
        \Eshcol\Ats\Http::flash('ok', $n ? "$n name" . ($n > 1 ? 's' : '') . ' corrected.' : 'Nothing was ticked.');
        \Eshcol\Ats\Http::redirect('/settings/ai-reading#names');
    }

    public function save(): never
    {
        Auth::requireOwner();
        $limit = max(0, min(10000, (int) ($_POST['limit'] ?? 600)));
        $fitLimit = max(0, min(10000, (int) ($_POST['fit_limit'] ?? $this->app->setting('fit_monthly_limit', '600'))));
        foreach (['ai_read_enabled' => isset($_POST['enabled']) ? '1' : '0', 'ai_read_monthly_limit' => (string) $limit, 'fit_monthly_limit' => (string) $fitLimit,
                'ai_read_cheap' => isset($_POST['cheap']) ? '1' : '0', 'fit_batch' => isset($_POST['batch']) ? '1' : '0'] as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Audit::log('ai_reading_settings_saved', null, null, ['on' => isset($_POST['enabled']), 'limit' => $limit, 'cheap' => isset($_POST['cheap']), 'batch' => isset($_POST['batch'])]);
        Http::flash('ok', 'Saved.');
        Http::redirect('/settings/ai-reading');
    }

    public function queueAll(): never
    {
        Auth::requireOwner();
        $n = (new AiReading($this->app))->queueAll();
        Http::flash('ok', "$n CVs are queued for Claude. Press \"Read them now\" and keep this page open, or leave it: the five-minute job reads about 40 at a time.");
        Http::redirect('/settings/ai-reading');
    }

    /** One CV from the queue, called repeatedly by ai-reading.js. */
    public function step(): never
    {
        Auth::requireOwner();
        @set_time_limit(170);
        $ai = new AiReading($this->app);
        try {
            $id = $ai->processNext();
        } catch (\RuntimeException $e) {
            $busy = str_starts_with($e->getMessage(), 'busy:');
            Http::json(['ok' => false, 'busy' => $busy, 'stop' => !$busy, 'error' => trim(preg_replace('/^(busy|stop):/', '', $e->getMessage()))], $busy ? 503 : 200);
        }
        $c = $ai->counts();
        Http::json(['ok' => true, 'done' => $id === null, 'left' => $c['pending'] + $c['reading'], 'cost' => $ai->costThisMonth()]);
    }

    /** The approval form: ticked changes are applied, the rest of each listed reading is left as it was. */
    public function apply(): never
    {
        Auth::requireOwner();
        $ai = new AiReading($this->app);
        $take = (array) ($_POST['take'] ?? []);
        $changed = 0;
        $people = 0;
        foreach (array_map('intval', (array) ($_POST['reads'] ?? [])) as $readId) {
            $fields = array_values(array_filter(array_map('strval', (array) ($take[$readId] ?? [])), fn ($f) => isset(AiReading::FIELDS[$f])));
            if ($fields) {
                $done = $ai->apply($readId, $fields);
                $changed += count($done);
                $people += $done ? 1 : 0;
            } else {
                $ai->dismiss($readId);
            }
        }
        Http::flash('ok', "Applied $changed change(s) for $people candidate(s). Their roles have been re-scored. Nothing you or a candidate confirmed was changed.");
        Http::redirect('/settings/ai-reading');
    }

    public function retry(): never
    {
        Auth::requireOwner();
        $n = $this->db->run("UPDATE cv_ai_reads SET status = 'pending', error = NULL WHERE status = 'failed'")->rowCount();
        Http::flash('ok', "$n CV(s) queued again.");
        Http::redirect('/settings/ai-reading');
    }
}
