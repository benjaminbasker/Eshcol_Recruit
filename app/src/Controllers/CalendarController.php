<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Interviews;

/**
 * The interview calendar (Benjamin, 17 Nov 2026): a week of each client's interviews and free times, where a free
 * time is given to a candidate in one click and the client's new times are added once for all its positions.
 */
final class CalendarController extends Controller
{
    public function index(): never
    {
        $user = Auth::user();
        $monday = self::monday((string) ($_GET['week'] ?? ''));
        $clientId = (int) ($_GET['client'] ?? 0);
        $clients = $this->db->all("SELECT DISTINCT cl.id, cl.name FROM clients cl JOIN roles r ON r.client_id = cl.id
            WHERE r.is_pool = 0 AND r.status IN ('open','on_hold') AND " . \Eshcol\Ats\Access::roleFilter($user, 'r.id') . ' ORDER BY cl.name');
        if ($clientId && !in_array($clientId, array_map('intval', array_column($clients, 'id')), true)) {
            $clientId = 0;
        }
        $svc = new Interviews($this->app);
        $week = $svc->week($monday, $clientId, $user);
        $people = [];
        foreach (array_unique(array_column($week['open'], 'client_id')) as $cid) {
            $people[(int) $cid] = $svc->allottable((int) $cid, $user);
        }
        $this->view('calendar/index', ['title' => 'Interview calendar', 'monday' => $monday, 'clientId' => $clientId, 'clients' => $clients,
            'week' => $week, 'people' => $people, 'kinds' => Interviews::KINDS]);
    }

    /** Gives a free time to a candidate: the call letter and the client's confirmation go as with Book. */
    public function allot(): never
    {
        [$ok, $msg] = (new Interviews($this->app))->allot((int) ($_POST['slot'] ?? 0), (int) ($_POST['application'] ?? 0), (int) Auth::user()['id']);
        Http::flash($ok ? 'ok' : 'error', $msg);
        Http::redirect($this->back());
    }

    /** Adds times the client gave you: once, offered on all the client's open positions. */
    public function addTimes(): never
    {
        $clientId = (int) ($_POST['client'] ?? 0);
        $roleId = (int) $this->db->col("SELECT r.id FROM roles r WHERE r.client_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND "
            . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . ' ORDER BY r.id LIMIT 1', [$clientId]);
        if (!$roleId) {
            Http::flash('error', 'Choose a client with an open position first.');
            Http::redirect($this->back());
        }
        $date = trim((string) ($_POST['date'] ?? ''));
        $times = array_map(fn ($t) => $date . ' ' . trim((string) $t), array_filter((array) ($_POST['times'] ?? []), fn ($t) => preg_match('/^\d{1,2}:\d{2}$/', trim((string) $t))));
        $n = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? (new Interviews($this->app))->addSlots($roleId, max(1, (int) ($_POST['round'] ?? 1)), (string) ($_POST['kind'] ?? 'virtual'),
            $times, (int) ($_POST['duration'] ?? 30), 'client') : 0;
        Audit::log('calendar_times_added', 'client', $clientId, ['n' => $n]);
        Http::flash($n ? 'ok' : 'error', $n ? "$n time" . ($n === 1 ? '' : 's') . ' added, offered on all this client\'s open positions.' : 'No times added: choose a date and at least one time in the future.');
        Http::redirect($this->back());
    }

    /** Removes a free time from all the client's positions. */
    public function removeTime(): never
    {
        $n = (new Interviews($this->app))->removeClientTime((int) ($_POST['slot'] ?? 0));
        Http::flash($n ? 'ok' : 'error', $n ? 'Time removed.' : 'That time is booked or already gone.');
        Http::redirect($this->back());
    }

    /** The Monday of the week holding $d (default: this week). Pure. */
    public static function monday(string $d): string
    {
        $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? strtotime($d) : time();
        return date('Y-m-d', strtotime('monday this week', $ts ?: time()));
    }

    private function back(): string
    {
        $b = (string) ($_POST['back'] ?? '');
        return preg_match('#^/calendar(\?[^\s]*)?$#', $b) ? $b : '/calendar';
    }
}
