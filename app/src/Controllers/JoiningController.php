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
        $rows = $this->db->all("SELECT id, application_id, candidate_id, full_name, engagement, joining_date, status, payload, created_at, downloaded_at FROM joinings WHERE $where ORDER BY joining_date IS NULL, joining_date DESC, id DESC");
        foreach ($rows as &$r) {
            $r['p'] = json_decode((string) $r['payload'], true) ?: [];
        }
        unset($r);
        $this->view('joinings/index', ['title' => 'Joinings', 'rows' => $rows, 'show' => $show,
            'counts' => array_column($this->db->all('SELECT status, COUNT(*) n FROM joinings GROUP BY status'), 'n', 'status')]);
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
