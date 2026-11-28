<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Joinings;

/** Joinings (phase 3, 15 Nov 2026), owner only: people who joined Eshcol's payroll, their records for the HRMS. */
final class JoiningController extends Controller
{
    public function index(): never
    {
        Auth::requireOwner();
        $show = in_array($_GET['show'] ?? '', ['all', 'withdrawn'], true) ? $_GET['show'] : 'open';
        $where = match ($show) { 'all' => '1=1', 'withdrawn' => "status = 'withdrawn'", default => "status IN ('ready','downloaded')" };
        // Audit P2 (16 Nov 2026): only the fields the table shows are read from each record, 100 at a time; the full record
        // (histories, references) is opened only by "Full record".
        $per = 100;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) $this->db->col("SELECT COUNT(*) FROM joinings WHERE $where");
        $rows = Joinings::summaries($this->app, $where, $per, ($page - 1) * $per);
        $this->view('joinings/index', ['title' => 'Joinings', 'rows' => $rows, 'show' => $show, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)),
            'counts' => array_column($this->db->all('SELECT status, COUNT(*) n FROM joinings GROUP BY status'), 'n', 'status'),
            'hrmsLinked' => (new \Eshcol\Ats\Services\HrmsLink($this->app))->configured(),
            'feedsAt' => (string) $this->app->setting('hrms_feeds_at', ''),
            'leavers' => $this->db->all('SELECT x.*, c.full_name, cl.name AS client FROM hrms_exits x LEFT JOIN candidates c ON c.id = x.candidate_id LEFT JOIN clients cl ON cl.id = x.client_id
                WHERE x.handled_at IS NULL OR x.handled_at >= NOW() - INTERVAL 30 DAY ORDER BY x.handled_at IS NOT NULL, x.last_day')]);
    }

    /** Everyone ready (or the ones ticked) as a spreadsheet; they are then marked "Downloaded". */
    public function csv(): never
    {
        Auth::requireOwner();
        $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
        $rows = $ids ? $this->db->all('SELECT id, payload FROM joinings WHERE id IN (' . implode(',', $ids) . ')')
                     : $this->db->all("SELECT id, payload FROM joinings WHERE status IN ('ready','downloaded') ORDER BY joining_date, id");
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // so Excel reads ₹ and Tamil names correctly
        fputcsv($fh, Joinings::CSV_HEAD, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, Joinings::csvSafe(Joinings::csvRow(json_decode((string) $r['payload'], true) ?: [])), ',', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        if ($rows) {
            $this->db->run("UPDATE joinings SET status = 'downloaded', downloaded_at = NOW() WHERE status = 'ready' AND id IN (" . implode(',', array_map(fn ($r) => (int) $r['id'], $rows)) . ')');
        }
        Audit::log('joinings_downloaded', 'setting', null, ['n' => count($rows)]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="eshcol-joinings-' . date('Y-m-d') . '.csv"');
        echo $out;
        exit;
    }

    /** One person's full record, in the format the HRMS will import. */
    public function json(int $id): never
    {
        Auth::requireOwner();
        $r = $this->findOr404('joinings', $id);
        $this->db->run("UPDATE joinings SET status = 'downloaded', downloaded_at = NOW() WHERE id = ? AND status = 'ready'", [$id]);
        Audit::log('joining_downloaded', 'candidate', (int) $r['candidate_id'], ['joining' => $id]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="eshcol-joining-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $r['full_name']) . '-' . $id . '.json"');
        echo json_encode(json_decode((string) $r['payload'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** "In the HRMS" (set up there by hand for now), or back to ready. */
    public function status(int $id): never
    {
        Auth::requireOwner();
        $r = $this->findOr404('joinings', $id);
        $to = in_array($_POST['status'] ?? '', ['ready', 'imported'], true) ? $_POST['status'] : null;
        if ($to) {
            $this->db->run('UPDATE joinings SET status = ? WHERE id = ?', [$to, $id]);
            Audit::log('joining_status', 'candidate', (int) $r['candidate_id'], ['joining' => $id, 'status' => $to]);
            Http::flash('ok', $to === 'imported' ? $r['full_name'] . ' is marked as set up in the HRMS.' : 'Back to ready.');
        }
        Http::redirect('/joinings');
    }

    /** The link key made in the HRMS (typed in here by the owner, never sent anywhere), or remove it. */
    public function hrmsLink(): never
    {
        Auth::requireOwner();
        if (!empty($_POST['remove'])) {
            \Eshcol\Ats\Secrets::forget($this->app, 'hrms_link_key');
            Audit::log('hrms_link_removed', 'setting');
            Http::flash('ok', 'The link to the HRMS is switched off.');
            Http::redirect('/joinings#hrms-link');
        }
        $key = trim((string) ($_POST['key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $key)) {
            Http::flash('error', 'Paste the link key exactly as the HRMS showed it (Joinings from Recruit, Link to Recruit).');
            Http::redirect('/joinings#hrms-link');
        }
        \Eshcol\Ats\Secrets::put($this->app, 'hrms_link_key', $key);
        Audit::log('hrms_link_saved', 'setting');
        Http::flash('ok', 'Saved. The HRMS can now fetch joining records; press Fetch from Recruit there to try it.');
        Http::redirect('/joinings#hrms-link');
    }


    /** Fetch the HRMS's rates and leavers now (it also happens every morning). */
    public function fetchHrms(): never
    {
        Auth::requireOwner();
        $out = (new \Eshcol\Ats\Services\HrmsLink($this->app))->fetchFeeds();
        Http::flash(str_contains($out, 'could not') || str_contains($out, 'answered') || str_contains($out, 'said') || $out === 'not linked' ? 'error' : 'ok', 'From the HRMS: ' . $out . '.');
        Http::redirect('/joinings#leavers');
    }

    /** A leaver is dealt with: a replacement raised, or nothing needed. */
    public function leaverHandled(int $id): never
    {
        Auth::requireOwner();
        $this->findOr404('hrms_exits', $id);
        $this->db->run('UPDATE hrms_exits SET handled_at = NOW(), handled_note = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null, $id]);
        Audit::log('hrms_leaver_handled', 'setting', null, ['leaver' => $id]);
        Http::flash('ok', 'Marked as dealt with.');
        Http::redirect('/joinings#leavers');
    }

    /** Refresh a record that isn't in the HRMS yet (e.g. a document came in after joining). */
    public function refresh(int $id): never
    {
        $me = Auth::requireOwner();
        $r = $this->findOr404('joinings', $id);
        if ($r['application_id'] && $r['status'] !== 'imported') {
            (new Joinings($this->app))->record((int) $r['application_id'], (int) $me['id']);
            Http::flash('ok', 'Record updated from the latest details.');
        }
        Http::redirect('/joinings');
    }
}
