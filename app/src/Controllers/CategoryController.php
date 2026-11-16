<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Categoriser;
use Eshcol\Ats\Services\CategoryTest;
use Eshcol\Ats\Services\CvReader;
use Eshcol\Ats\Services\FunctionRouting;

/**
 * Categories (13 Nov 2026): Settings › Categories (on/trial/off, the queue, unsure ones, the trial),
 * the category test, and a candidate's category card (correct it, confirm it, categorise now).
 */
final class CategoryController extends Controller
{
    public function settings(): never
    {
        Auth::requireOwner();
        $cat = new Categoriser($this->app);
        $this->view('settings/categories', [
            'title' => 'Categories',
            'mode' => Categoriser::mode($this->app), 'hasKey' => CvReader::available($this->app),
            'counts' => $cat->counts(), 'limit' => $cat->limit(), 'used' => $cat->usedThisMonth(), 'cost' => $cat->costThisMonth(),
            'review' => $this->db->all("SELECT cc.*, c.full_name, c.current_title FROM candidate_categories cc JOIN candidates c ON c.id = cc.candidate_id
                WHERE cc.is_current = 1 AND cc.needs_review = 1 AND cc.reviewed_at IS NULL ORDER BY cc.id DESC LIMIT 100"),
            'failed' => $this->db->all("SELECT q.candidate_id, q.error, c.full_name FROM categorise_queue q JOIN candidates c ON c.id = q.candidate_id WHERE q.status = 'failed' ORDER BY q.updated_at DESC LIMIT 30"),
            'byField' => $this->db->all('SELECT f.code, f.name, COUNT(cc.id) AS n, SUM(cc.secondary_code IS NOT NULL) AS with_second
                FROM functions f LEFT JOIN candidate_categories cc ON cc.function_code = f.code AND cc.is_current = 1 GROUP BY f.code ORDER BY f.sort'),
            'trial' => (new FunctionRouting($this->app, $cat))->trialSummary(),
            'real' => (new CategoryTest($this->app))->realCheck(),
            'tests' => $this->db->all('SELECT t.*, (SELECT COUNT(*) FROM category_test_items i WHERE i.test_id = t.id AND i.status = \'pending\') AS waiting FROM category_tests t ORDER BY t.id DESC LIMIT 10'),
        ]);
    }

    public function save(): never
    {
        Auth::requireOwner();
        $mode = isset(Categoriser::MODES[$_POST['mode'] ?? '']) ? $_POST['mode'] : 'trial';
        $limit = max(0, min(100000, (int) ($_POST['limit'] ?? 3000)));
        foreach (['category_mode' => $mode, 'category_monthly_limit' => (string) $limit] as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Audit::log('category_settings_saved', null, null, ['mode' => $mode, 'limit' => $limit]);
        Http::flash('ok', match ($mode) {
            'on' => 'Switched on: new CVs are routed by field, experience and location from now on. Positions already filled in are left as they are.',
            'trial' => 'Trial: CVs are categorised and the new routing is recorded beside the old one. Nothing on the pipelines changes.',
            default => 'Switched off: nothing is categorised.',
        });
        Http::redirect('/settings/categories');
    }

    /** Everyone on file without a category is queued (about ₹0.03 each). */
    public function queueAll(): never
    {
        Auth::requireOwner();
        $n = (new Categoriser($this->app))->queueEveryone();
        Http::flash('ok', "$n candidates queued. Press \"Categorise them now\" and keep this page open, or leave it: the five-minute job does 40 at a time.");
        Http::redirect('/settings/categories');
    }

    /** One from the queue, called repeatedly by categories.js. */
    public function step(): never
    {
        Auth::requireOwner();
        @set_time_limit(120);
        $cat = new Categoriser($this->app);
        try {
            $id = $cat->processNext();
        } catch (\RuntimeException $e) {
            $busy = str_starts_with($e->getMessage(), 'busy:');
            Http::json(['ok' => false, 'busy' => $busy, 'error' => trim(preg_replace('/^(busy|stop):/', '', $e->getMessage()))], $busy ? 503 : 200);
        }
        $c = $cat->counts();
        Http::json(['ok' => true, 'done' => $id === null, 'left' => $c['pending'], 'cost' => $cat->costThisMonth()]);
    }

    /** Works the trial out again for everyone (after a position changed). Free. */
    public function trial(): never
    {
        Auth::requireOwner();
        @set_time_limit(170);
        $n = (new FunctionRouting($this->app))->recordTrialForAll();
        Http::flash('ok', "The trial is up to date for $n candidates.");
        Http::redirect('/settings/categories#trial');
    }

    // ---------------------------------------------------------------- the category test

    public function testCreate(): never
    {
        Auth::requireOwner();
        $id = (new CategoryTest($this->app))->create(array_map('strval', (array) ($_POST['readers'] ?? [])), (int) Auth::user()['id']);
        Http::redirect("/settings/category-test/$id");
    }

    public function testShow(int $id): never
    {
        Auth::requireOwner();
        $t = $this->findOr404('category_tests', $id);
        $svc = new CategoryTest($this->app);
        $this->view('settings/category-test', ['title' => 'Category test', 'test' => $t, 'bench' => $svc->bench(), 'results' => $svc->results($id),
            'waiting' => (int) $this->db->col("SELECT COUNT(*) FROM category_test_items WHERE test_id = ? AND status = 'pending'", [$id])]);
    }

    public function testStep(int $id): never
    {
        Auth::requireOwner();
        @set_time_limit(120);
        $this->findOr404('category_tests', $id);
        try {
            $more = (new CategoryTest($this->app))->step($id);
        } catch (\RuntimeException $e) {
            $busy = str_starts_with($e->getMessage(), 'busy:');
            Http::json(['ok' => false, 'busy' => $busy, 'error' => trim(preg_replace('/^(busy|stop):/', '', $e->getMessage()))], $busy ? 503 : 200);
        }
        $left = (int) $this->db->col("SELECT COUNT(*) FROM category_test_items WHERE test_id = ? AND status = 'pending'", [$id]);
        $cost = (float) $this->db->col('SELECT COALESCE(SUM(cost_usd), 0) FROM category_test_items WHERE test_id = ?', [$id]);
        Http::json(['ok' => true, 'done' => !$more || $left === 0, 'left' => $left, 'cost' => $cost]);
    }

    public function testDelete(int $id): never
    {
        Auth::requireOwner();
        $this->db->run('DELETE FROM category_tests WHERE id = ?', [$id]);
        Http::flash('ok', 'Test deleted.');
        Http::redirect('/settings/categories#test');
    }

    // ---------------------------------------------------------------- a candidate's category

    /** Your correction: kept as its own row, wins until a new CV arrives. */
    public function candidateSave(int $id): never
    {
        $this->findOr404('candidates', $id);
        $codes = Categoriser::functions($this->app);
        $fn = (string) ($_POST['function'] ?? '');
        if (!isset($codes[$fn])) {
            Http::flash('error', 'Choose the field of work.');
            Http::redirect("/candidates/$id" . $this->fromList() . "#category");
        }
        $cat = new Categoriser($this->app);
        $prefs = array_values(array_filter(array_map('trim', preg_split('/[,;\/]+/', (string) ($_POST['preferred_cities'] ?? '')))));
        $cat->save($id, [
            'function' => $fn, 'secondary_function' => (string) ($_POST['secondary'] ?? ''), 'specialisation' => $this->str('specialisation', 160) ?? '',
            'seniority' => (string) ($_POST['seniority'] ?? ''), 'preferred_cities' => $prefs,
            'relocation' => (string) ($_POST['relocation'] ?? 'unknown'), 'remote_ok' => (string) ($_POST['remote_ok'] ?? 'unknown'),
            'employment_status' => '', 'evidence' => [], 'confidence' => 'high',
        ], 'recruiter', (int) $this->db->col('SELECT MAX(id) FROM cv_files WHERE candidate_id = ?', [$id]) ?: null, $this->jobs($id), [], (int) Auth::user()['id']);
        $routing = new FunctionRouting($this->app, $cat);
        if (Categoriser::mode($this->app) === 'on') {
            $routing->afterCategorised($id);
        } elseif (Categoriser::mode($this->app) === 'trial') {
            $routing->recordTrial($id);
        }
        Http::flash('ok', 'Category saved. It stays as you set it until a new CV arrives.');
        Http::redirect("/candidates/$id" . $this->fromList() . "#category");
    }

    public function candidateConfirm(int $id): never
    {
        $this->findOr404('candidates', $id);
        (new Categoriser($this->app))->confirm($id, (int) Auth::user()['id']);
        if (($_POST['back'] ?? '') === 'settings') {
            Http::flash('ok', 'Marked as right.');
            Http::redirect('/settings/categories#check');
        }
        Http::flash('ok', 'Marked as right.');
        Http::redirect("/candidates/$id" . $this->fromList() . "#category");
    }

    /** "Categorise now": Claude Haiku straight away (about ₹0.03). */
    public function candidateNow(int $id): never
    {
        $this->findOr404('candidates', $id);
        try {
            (new Categoriser($this->app))->categoriseNow($id);
            Http::flash('ok', 'Categorised.');
        } catch (\RuntimeException $e) {
            Http::flash('error', 'Not categorised: ' . preg_replace('/^(busy|stop):\s*/', '', $e->getMessage()));
        }
        Http::redirect("/candidates/$id" . $this->fromList() . "#category");
    }

    /** "?from=categories" when the candidate was opened from the list of unsure categories, so the page keeps its "Back to the list" and "Next one" buttons. */
    private function fromList(): string
    {
        return ($_POST['back'] ?? '') === 'categories' ? '?from=categories' : '';
    }

    private function jobs(int $id): array
    {
        $raw = $this->db->col("SELECT raw FROM cv_ai_reads WHERE candidate_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$id]);
        return $raw ? (array) (json_decode((string) $raw, true)['jobs'] ?? []) : [];
    }
}
