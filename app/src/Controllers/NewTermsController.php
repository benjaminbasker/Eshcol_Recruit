<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\NewTerms;

/** Settings › New words (owner), and other wordings for a role's must-haves (role's Claude tab). */
final class NewTermsController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $svc = new NewTerms($this->app);
        $this->view('settings/new-words', [
            'title' => 'New words from CVs',
            'words' => $svc->pending('cv'),
            'canonicals' => $this->db->all("SELECT DISTINCT canonical, category FROM skills_dictionary WHERE category IN ('designation','skill') AND (source IS NULL OR source <> 'onet') ORDER BY canonical"),
            'areas' => array_column($this->db->all('SELECT DISTINCT area FROM skills_dictionary WHERE area IS NOT NULL ORDER BY area'), 'area'),
        ]);
    }

    /** One word added (as itself, or as another wording of an existing name), or the ticked ones ignored or added as they are. */
    public function save(): never
    {
        Auth::requireOwner();
        $svc = new NewTerms($this->app);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'scan') {
            @set_time_limit(120);
            $n = $svc->backfill();
            $w = count($svc->pending('cv'));
            Http::flash('ok', "Checked $n CV reading(s). $w word(s) waiting.");
        } elseif ($action === 'add') {
            $as = $svc->accept((int) ($_POST['id'] ?? 0), trim(mb_substr((string) ($_POST['canonical'] ?? ''), 0, 100)) ?: null, trim((string) ($_POST['area'] ?? '')) ?: null);
            Http::flash($as !== '' ? 'ok' : 'error', $as !== '' ? "Added to the dictionary as \"$as\"." : 'That word was already dealt with.');
        } elseif ($action === 'ignore' || $action === 'add_ticked') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            if ($action === 'ignore') {
                $n = $svc->ignore($ids);
                Http::flash('ok', "$n word(s) ignored. They won't be suggested again.");
            } else {
                $n = 0;
                foreach ($ids as $id) {
                    $n += $svc->accept($id) !== '' ? 1 : 0;
                }
                Http::flash('ok', "$n word(s) added to the dictionary as they are.");
            }
        }
        Http::redirect('/settings/new-words');
    }

    /** Claude suggests other wordings for the role's must-haves (about ₹1). */
    public function suggest(int $id): never
    {
        $this->findOr404('roles', $id);
        @set_time_limit(120);
        try {
            $n = (new NewTerms($this->app))->suggestForRole($id);
            Http::flash('ok', $n ? "Claude suggested $n other wording(s). Tick the right ones and add them." : 'Claude found no other wordings beyond those already known.');
        } catch (\Throwable $e) {
            Http::flash('error', preg_replace('/^(busy|stop):\s*/', '', $e->getMessage()));
        }
        Http::redirect("/roles/$id#wordings");
    }

    /** The ticked wordings join the dictionary under their must-have; the rest are ignored. */
    public function acceptForRole(int $id): never
    {
        $this->findOr404('roles', $id);
        $svc = new NewTerms($this->app);
        $ticked = array_map('intval', (array) ($_POST['ids'] ?? []));
        $all = array_map('intval', array_column($svc->pending('role', $id), 'id'));
        $n = 0;
        foreach (array_intersect($ticked, $all) as $sid) {
            $n += $svc->accept($sid) !== '' ? 1 : 0;
        }
        $ignored = $svc->ignore(array_diff($all, $ticked));
        Http::flash('ok', "$n wording(s) added to the dictionary" . ($ignored ? "; $ignored left out" : '') . '. The free check now finds them in CVs.');
        Http::redirect("/roles/$id#wordings");
    }
}
