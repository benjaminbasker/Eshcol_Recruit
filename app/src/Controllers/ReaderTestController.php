<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CvReader;
use Eshcol\Ats\Services\ReaderTest;

/** Settings › Reader test (owner only): free reader vs Claude Haiku vs Claude Sonnet. */
final class ReaderTestController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $tests = $this->db->all("SELECT t.*, (SELECT COUNT(*) FROM reader_test_items i WHERE i.test_id = t.id AND i.reader = 'free') AS cvs,
            (SELECT COUNT(*) FROM reader_test_items i WHERE i.test_id = t.id AND i.reader = 'truth') AS checked,
            (SELECT COALESCE(SUM(cost_usd), 0) FROM reader_test_items i WHERE i.test_id = t.id) AS cost FROM reader_tests t ORDER BY t.id DESC");
        $this->view('settings/reader-test/index', ['tests' => $tests, 'hasKey' => CvReader::available($this->app)]);
    }

    public function create(): never
    {
        Auth::requireOwner();
        $max = max(5, min(80, (int) ($_POST['max'] ?? 50)));
        $id = (new ReaderTest($this->app))->create((int) Auth::user()['id'], $max);
        Http::flash('ok', 'Test created. The free reader has read every CV; now run Claude Haiku and Sonnet.');
        Http::redirect("/settings/reader-test/$id");
    }

    public function show(int $id): never
    {
        Auth::requireOwner();
        $test = $this->findOr404('reader_tests', $id);
        $rt = new ReaderTest($this->app);
        $this->view('settings/reader-test/show', ['test' => $test, 'items' => $rt->items($id), 'score' => $rt->score($id),
            'pending' => $rt->pending($id), 'spent' => $rt->spent($id), 'hasKey' => CvReader::available($this->app)]);
    }

    /** One AI run, called repeatedly by reader-test.js. JSON in, JSON out. */
    public function run(int $id): never
    {
        Auth::requireOwner();
        $this->findOr404('reader_tests', $id);
        @set_time_limit(170);
        $cid = (int) ($_POST['candidate_id'] ?? 0);
        $reader = (string) ($_POST['reader'] ?? '');
        if (!isset(CvReader::MODELS[$reader])) {
            Http::json(['ok' => false, 'error' => 'Unknown reader.'], 400);
        }
        $rt = new ReaderTest($this->app);
        try {
            $row = $rt->runAi($id, $cid, $reader);
        } catch (\RuntimeException $e) {
            $busy = str_starts_with($e->getMessage(), 'busy:');
            Http::json(['ok' => false, 'busy' => $busy, 'stop' => !$busy, 'error' => trim(preg_replace('/^(busy|stop):/', '', $e->getMessage()))], $busy ? 503 : 200);
        }
        Http::json(['ok' => $row['error'] === null, 'error' => $row['error'], 'cost' => (float) ($row['cost_usd'] ?? 0), 'spent' => $rt->spent($id)]);
    }

    /** One CV: the CV beside what each reader found, and the answer key to check. */
    public function review(int $id, int $cid): never
    {
        Auth::requireOwner();
        $test = $this->findOr404('reader_tests', $id);
        $rt = new ReaderTest($this->app);
        $items = $rt->items($id);
        if (!isset($items[$cid])) {
            Http::abort(404);
        }
        $ids = array_keys($items);
        $pos = array_search($cid, $ids, true);
        $this->view('settings/reader-test/review', ['test' => $test, 'cid' => $cid, 'item' => $items[$cid], 'suggest' => $rt->suggestTruth($items[$cid]),
            'prev' => $ids[$pos - 1] ?? null, 'next' => $ids[$pos + 1] ?? null, 'pos' => $pos + 1, 'total' => count($ids)]);
    }

    public function saveTruth(int $id, int $cid): never
    {
        Auth::requireOwner();
        $this->findOr404('reader_tests', $id);
        $rt = new ReaderTest($this->app);
        $items = $rt->items($id);
        if (!isset($items[$cid])) {
            Http::abort(404);
        }
        $rt->saveTruth($id, $cid, (array) ($_POST['truth'] ?? []));
        // On to the next CV not yet checked.
        foreach ($rt->items($id) as $k => $it) {
            if ($it['truth'] === null && count($it['readers']) === count(ReaderTest::READERS)) {
                Http::flash('ok', 'Saved. Next CV to check.');
                Http::redirect("/settings/reader-test/$id/cv/$k");
            }
        }
        Http::flash('ok', 'Saved. Every CV is checked: the scorecard is complete.');
        Http::redirect("/settings/reader-test/$id");
    }

    public function delete(int $id): never
    {
        Auth::requireOwner();
        $this->findOr404('reader_tests', $id);
        (new ReaderTest($this->app))->delete($id);
        Http::flash('info', 'Test deleted. No candidate was changed by it.');
        Http::redirect('/settings/reader-test');
    }
}
