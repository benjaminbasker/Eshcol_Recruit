<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FitAssessor;
use Eshcol\Ats\Services\Matcher;

/** The role's Claude tab: requirements, qualification ladder, and running the assessments. */
final class FitController extends Controller
{
    public function save(int $id): never
    {
        $this->findOr404('roles', $id);
        $items = [];
        foreach ((array) ($_POST['req'] ?? []) as $i => $text) {
            $items[] = ['text' => (string) $text, 'critical' => isset($_POST['crit'][$i])];
        }
        $hist = (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? null : new \Eshcol\Ats\Services\RoleHistory($this->app);
        $before = $hist?->snapshot($id);
        $fit = new FitAssessor($this->app);
        $fit->saveRequirements($id, $items);
        $ladder = array_map(fn ($k) => trim(mb_substr((string) ($_POST["ladder_$k"] ?? ''), 0, 200)), ['base' => 'base', 'higher' => 'higher', 'top' => 'top']);
        $this->db->update('roles', ['qual_ladder' => array_filter($ladder) ? json_encode($ladder, JSON_UNESCAPED_UNICODE) : null], $id);
        $hist?->record($id, $before, $hist->snapshot($id), (int) \Eshcol\Ats\Auth::user()['id']); // position history (B13)
        Audit::log('fit_requirements_saved', 'role', $id);
        Http::flash('ok', 'Saved. Candidates assessed before this change are marked "role changed"; press Re-assess everyone when you are ready.');
        Http::redirect("/roles/$id#tab-claude");
    }

    /** Copy another role's requirements and ladder onto this one. */
    public function copy(int $id): never
    {
        $this->findOr404('roles', $id);
        $from = (int) ($_POST['from'] ?? 0);
        try {
            $r = (new FitAssessor($this->app))->copyFrom($id, $from);
            $src = $this->db->one('SELECT code, title FROM roles WHERE id = ?', [$from]);
            Audit::log('fit_requirements_copied', 'role', $id, ['from' => $from]);
            $what = array_filter([$r['reqs'] ? "{$r['reqs']} requirement(s)" : '', $r['ladder'] ? 'the qualification ladder' : '']);
            Http::flash('ok', 'Copied ' . implode(' and ', $what) . ' from ' . trim(($src['code'] ?? '') . ' ' . ($src['title'] ?? '')) . '. Check them for this role and edit if needed; they are already saved.');
        } catch (\RuntimeException $e) {
            Http::flash('error', $e->getMessage());
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    public function suggest(int $id): never
    {
        $this->findOr404('roles', $id);
        @set_time_limit(120);
        try {
            $n = count((new FitAssessor($this->app))->suggestRequirements($id));
            Http::flash('ok', "Claude drafted $n requirements from the JD. Check and edit them, mark the critical ones, then save.");
        } catch (\Throwable $e) {
            Http::flash('error', preg_replace('/^(busy|stop):\s*/', '', $e->getMessage()));
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    /** Assess the relevant candidates not assessed yet ($again: everyone relevant, again). */
    public function queue(int $id): never
    {
        $this->findOr404('roles', $id);
        $again = isset($_POST['again']);
        $fit = new FitAssessor($this->app);
        $r = $fit->queueRole($id, $again);
        Http::flash('ok', "{$r['queued']} relevant candidate(s) queued for Claude"
            . ($r['skipped'] ? "; {$r['skipped']} assessed for another role at this client instead" : '')
            . "; {$r['not_relevant']} not relevant by the free check. "
            . ($fit->batchOn() ? 'Within five minutes they go to Claude at half price (results usually within the hour), or press "Assess now" for results straight away at the full price.' : 'Press "Assess now", or leave it to the five-minute job.'));
        Http::redirect("/roles/$id#tab-claude");
    }

    /** "Assess for this role too": a candidate Claude assessed for another role at the same client. */
    public function force(int $id): never
    {
        $a = $this->findOr404('applications', $id);
        if (isset($_POST['now'])) {
            @set_time_limit(170);
            $why = (new FitAssessor($this->app))->assessNow($id);
            Audit::log('fit_assessed_now', 'candidate', (int) $a['candidate_id'], ['application' => $id]);
            Http::flash($why === '' ? 'ok' : 'error', $why === '' ? 'Assessed by Claude (full price).' : 'Claude could not assess them: ' . $why);
            $back = (string) ($_POST['back'] ?? '');
            Http::redirect(preg_match('#^/(?![/\\\\])[^\s]*$#', $back) ? $back : '/candidates/' . $a['candidate_id']);
        }
        $why = (new FitAssessor($this->app))->queue($id, false, true);
        Audit::log('fit_forced', 'candidate', (int) $a['candidate_id'], ['application' => $id]);
        Http::flash($why === '' || $why === 'reading' ? 'ok' : 'error', $why === '' || $why === 'reading'
            ? 'Queued: Claude will assess them for this role too (about ₹1). The result appears here, usually within the hour.'
            : 'Not sent to Claude: ' . $why . '.');
        $back = (string) ($_POST['back'] ?? '');
        Http::redirect(preg_match('#^/(?![/\\])[^\s]*$#', $back) ? $back : '/candidates/' . $a['candidate_id']);
    }

    /** One assessment from this role's queue, for the "Assess now" loop. */
    public function step(int $id): never
    {
        $this->findOr404('roles', $id);
        @set_time_limit(170);
        $fit = new FitAssessor($this->app);
        try {
            $done = $fit->processNext($id);
        } catch (\RuntimeException $e) {
            $busy = str_starts_with($e->getMessage(), 'busy:');
            Http::json(['ok' => false, 'busy' => $busy, 'stop' => !$busy, 'error' => trim(preg_replace('/^(busy|stop):/', '', $e->getMessage()))], $busy ? 503 : 200);
        }
        $left = (int) $this->db->col("SELECT COUNT(*) FROM fit_assessments WHERE role_id = ? AND status IN ('pending','reading')", [$id]);
        Http::json(['ok' => true, 'done' => $done === null, 'left' => $left, 'cost' => $fit->costThisMonth()]);
    }

    /** The extra analysers (6, 5b, 7) are hidden unless switched on (16 Nov 2026): their buttons' addresses refuse too. */
    private function extrasOr404(): void
    {
        if (!\Eshcol\Ats\Services\Agreement::extrasOn($this->app)) {
            Http::abort(404, 'This is switched off.');
        }
    }

    // ---------------------------------------------------------------- analyser 6: examples

    /** ☆ Example on a ranking row (or Remove on the examples card). */
    public function example(int $id, int $cid): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $ex = new \Eshcol\Ats\Services\Examples($this->app);
        if (isset($_POST['remove'])) {
            $ex->remove($id, $cid);
            Http::flash('ok', 'Removed from your examples. Assessments from now on use the examples left.');
        } else {
            $why = $ex->add($id, $cid, (int) Auth::user()['id']);
            Http::flash($why === '' ? 'ok' : 'error', $why === '' ? 'Marked as an example. Add a line on why they are right in "Your examples", if you like. Nobody is re-assessed unless you press the button there.' : $why);
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    public function exampleNote(int $id, int $cid): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $eid = (int) $this->db->col('SELECT id FROM role_examples WHERE role_id = ? AND candidate_id = ?', [$id, $cid]);
        if ($eid) {
            (new \Eshcol\Ats\Services\Examples($this->app))->setNote($id, $eid, (string) ($_POST['note'] ?? ''));
            Http::flash('ok', 'Saved. Assessments from now on read it.');
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    public function examplesFromClient(int $id): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $n = (new \Eshcol\Ats\Services\Examples($this->app))->fromClient($id, (int) Auth::user()['id']);
        Http::flash($n ? 'ok' : 'error', $n ? "$n of the client's yes answers added as examples." : 'None added: the examples are full, or their CVs have not been read.');
        Http::redirect("/roles/$id#tab-claude");
    }

    public function examplesReassess(int $id): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $n = (new \Eshcol\Ats\Services\Examples($this->app))->reassessBefore($id);
        Http::flash('ok', "$n candidate(s) queued to be assessed again against your examples. They go to Claude in the half-price batch within five minutes; results usually within the hour.");
        Http::redirect("/roles/$id#tab-claude");
    }

    // ---------------------------------------------------------------- analyser 5b: suggest rule changes

    public function rulesSuggest(int $id): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        @set_time_limit(120);
        try {
            $n = (new \Eshcol\Ats\Services\RuleSuggestions($this->app))->suggest($id, (int) Auth::user()['id']);
            Http::flash('ok', $n ? "Claude suggested $n change(s). Accept the ones you agree with; nothing changes until you do."
                : 'Claude found no pattern in your reasons to suggest a change from. Your reasons may be about single candidates.');
        } catch (\Throwable $e) {
            Http::flash('error', 'Claude could not suggest changes: ' . preg_replace('/^(busy|stop):\s*/', '', $e instanceof \Anthropic\Core\Exceptions\APIStatusException ? FitAssessor::apiError($e) : $e->getMessage()));
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    public function ruleDecide(int $id, int $sid): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $svc = new \Eshcol\Ats\Services\RuleSuggestions($this->app);
        if (isset($_POST['accept'])) {
            $why = $svc->accept($id, $sid, (int) Auth::user()['id']);
            Http::flash($why === '' ? 'ok' : 'error', $why === '' ? 'Changed in "What Claude checks". Candidates assessed before are marked "role changed"; nobody is re-assessed unless you press Re-assess everyone.' : $why);
        } else {
            $svc->ignore($id, $sid);
        }
        Http::redirect("/roles/$id#tab-claude");
    }

    // ---------------------------------------------------------------- analyser 7: fair check of the top 10

    public function fairCheck(int $id): never
    {
        $this->findOr404('roles', $id);
        $this->extrasOr404();
        $why = (new \Eshcol\Ats\Services\FairCheck($this->app))->start($id, (int) Auth::user()['id']);
        Http::flash($why === '' ? 'ok' : 'error', $why === '' ? 'Queued. Within five minutes it goes to Claude in a half-price batch; the result usually arrives within the hour.' : $why);
        Http::redirect("/roles/$id#tab-claude");
    }

    /** "Find in your database": add every relevant match to the role; Claude assesses them. */
    public function addAll(int $id): never
    {
        $role = $this->findOr404('roles', $id);
        $importer = new CandidateImporter($this->app);
        $uid = (int) Auth::user()['id'];
        $added = 0;
        foreach ((new Matcher($this->app))->forRole($role, 200) as $m) {
            $c = $m['cand'] ?? null;
            $must = $m['breakdown']['parts']['must'] ?? null;
            if (!$c || !\Eshcol\Ats\Services\Contact::canPutForward($c)) {
                continue;
            }
            // Only the ones the free check finds relevant: same field and experience inside the allowance.
            $nMust = $must ? count($must['matched']) + count($must['missing']) : 0;
            if (!\Eshcol\Ats\Services\RoleRouter::sameField(count($must['matched']), $nMust)) {
                continue;
            }
            $months = exp_now($c);
            if (($role['exp_min'] !== null || $role['exp_max'] !== null)
                && ($months === null ? (float) ($role['exp_min'] ?? 0) > 1 : !\Eshcol\Ats\Services\RoleRouter::inWindow($role, $months / 12))) {
                continue;
            }
            if ($importer->attach((int) $c['id'], $id, $uid, 'database')) {
                $added++;
            }
        }
        $q = FitAssessor::enabled($this->app) ? (new FitAssessor($this->app))->queueRole($id) : ['queued' => 0, 'skipped' => 0, 'not_relevant' => 0];
        Audit::log('added_all_from_database', 'role', $id, ['added' => $added]);
        Http::flash('ok', "$added candidate(s) added from your database. {$q['queued']} relevant candidate(s) are queued for Claude.");
        Http::redirect("/roles/$id#tab-claude");
    }
}
