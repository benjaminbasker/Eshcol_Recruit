<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Placements;

/** Placements (B9): the owner's only, because they hold the money. */
final class PlacementController extends Controller
{
    public function index(): never
    {
        $this->ownerOnly();
        $svc = new Placements($this->app);
        $rows = $svc->all();
        $show = (string) ($_GET['show'] ?? 'all');
        $list = match ($show) {
            'offers' => array_filter($rows, fn ($r) => $r['status'] === 'offer'),
            'unpaid' => array_filter($rows, fn ($r) => in_array($r['money'], ['to_invoice', 'invoiced', 'overdue'], true)),
            default => $rows,
        };
        $fy = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1; // Indian financial year, April to March
        $this->view('placements/index', ['title' => 'Placements', 'svc' => $svc, 'rows' => array_values($list), 'show' => $show,
            'month' => $svc->totals($rows, date('Y-m-01'), date('Y-m-t')),
            'year' => $svc->totals($rows, "$fy-04-01", ($fy + 1) . '-03-31'), 'fy' => $fy]);
    }

    public function save(int $id): never
    {
        $p = $this->mine($id);
        (new Placements($this->app))->save($id, $_POST);
        Http::flash('ok', 'Placement saved.');
        $this->back($p);
    }

    public function checkin(int $id, int $day): never
    {
        $p = $this->mine($id);
        (new Placements($this->app))->checkin($id, $day);
        Http::flash('ok', "$day-day check-in recorded.");
        $this->back($p);
    }

    public function left(int $id): never
    {
        $p = $this->mine($id);
        $svc = new Placements($this->app);
        $svc->left($id, (string) ($_POST['left_on'] ?? ''), $this->str('left_note', 255));
        $now = $this->db->one('SELECT * FROM placements WHERE id = ?', [$id]);
        $reopened = $this->db->col('SELECT 1 FROM roles WHERE id = ? AND replacement_for = ?', [$p['role_id'], $id]);
        Http::flash('ok', $reopened ? 'Recorded. The position is open again as a replacement: the next person to join carries no fee.' : 'Recorded.');
        $this->back($now);
    }

    private function mine(int $id): array
    {
        $this->ownerOnly();
        $p = $this->db->one('SELECT * FROM placements WHERE id = ?', [$id]);
        $p || Http::abort(404);
        return $p;
    }

    private function back(array $p): never
    {
        Http::redirect($p['candidate_id'] ? '/candidates/' . (int) $p['candidate_id'] . '#placement-' . (int) $p['id'] : '/placements');
    }

    private function ownerOnly(): void
    {
        if ((Auth::user()['role'] ?? '') !== 'owner') {
            Http::abort(403, 'Placements and fees are visible to the owner only.');
        }
    }
}
