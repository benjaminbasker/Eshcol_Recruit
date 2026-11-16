<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Placements;
use Eshcol\Ats\Services\Reports;

/** Reports (B10, 14 Nov 2026): the owner's only, because they include fees and Claude's cost. */
final class ReportController extends Controller
{
    public function index(): never
    {
        if ((Auth::user()['role'] ?? '') !== 'owner') {
            Http::abort(403, 'Reports are visible to the owner only.');
        }
        $period = isset(Reports::PERIODS[$_GET['period'] ?? '']) ? $_GET['period'] : 'quarter';
        [$from, $to] = Reports::range($period);
        $r = new Reports($this->app);
        $pl = new Placements($this->app);
        $this->view('reports/index', ['title' => 'Reports', 'period' => $period, 'from' => $from, 'to' => $to,
            'funnel' => $r->funnel($from, $to), 'sources' => $r->sources($from, $to), 'fill' => $r->timeToFill($from, $to),
            'claude' => $r->claudeByMonth(), 'claudeRoles' => $r->claudeByRole($from, $to), 'money' => $pl->totals($pl->all(), $from, $to)]);
    }
}
