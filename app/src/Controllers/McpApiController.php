<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\McpLink;
use Eshcol\Ats\Services\Migrator;

/**
 * Claude's read-only link (28 Nov 2026). GET only, under /hooks/mcp/, each request signed with the link key (McpLink).
 * Nothing here changes any data. The owner makes or switches off the key on /connector.
 */
final class McpApiController extends Controller
{
    private function guard(): McpLink
    {
        header('Cache-Control: no-store');
        if (Http::method() !== 'GET') {
            Http::json(['error' => 'Read only.'], 405);
        }
        if ((new Migrator($this->app))->pending()) {
            Http::json(['error' => 'Recruit is being updated. Try again in a few minutes.'], 503);
        }
        $L = new McpLink($this->app);
        if (!$L->configured()) {
            Http::json(['error' => 'The link for Claude is switched off in Recruit.'], 503);
        }
        // The signature covers the path and the query, so a request cannot be re-aimed.
        $path = Http::path() . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        if (!$L->verify('GET', $path, $_SERVER['HTTP_X_ESHCOL_TIME'] ?? null, $_SERVER['HTTP_X_ESHCOL_SIGNATURE'] ?? null, '')) {
            Http::throttle('mcplink:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600) || Http::json(['error' => 'Too many tries.'], 429);
            Http::json(['error' => 'Bad signature.'], 401);
        }
        return $L;
    }

    public function positions(): never
    {
        Http::json(['positions' => $this->guard()->positions()]);
    }

    public function position(int $id): never
    {
        $r = $this->guard()->position($id);
        Http::json($r ?? ['error' => 'No such position.'], $r ? 200 : 404);
    }

    public function candidates(int $id): never
    {
        $L = $this->guard();
        Http::json(['candidates' => $L->candidates($id, $_GET['stage'] ?? null, $_GET['kind'] ?? null, (int) ($_GET['limit'] ?? 25), (int) ($_GET['offset'] ?? 0))]);
    }

    public function candidate(int $id): never
    {
        $r = $this->guard()->candidate($id);
        Http::json($r ?? ['error' => 'No such candidate.'], $r ? 200 : 404);
    }

    public function search(): never
    {
        Http::json(['candidates' => $this->guard()->search((string) ($_GET['q'] ?? ''), (int) ($_GET['limit'] ?? 20))]);
    }

    /** Owner page: make a key (shown once) or switch the link off. */
    public function page(): never
    {
        Auth::requireOwner();
        $this->view('connector/index', ['title' => 'Connect Claude (read only)', 'on' => (new McpLink($this->app))->configured(), 'key' => null]);
    }

    public function save(): never
    {
        Auth::requireOwner();
        $L = new McpLink($this->app);
        if (!empty($_POST['remove'])) {
            \Eshcol\Ats\Secrets::forget($this->app, McpLink::KEY);
            Audit::log('mcp_link_removed', 'setting');
            Http::flash('ok', 'The link for Claude is switched off.');
            Http::redirect('/connector');
        }
        $key = $L->newKey();
        Audit::log('mcp_link_saved', 'setting');
        $this->view('connector/index', ['title' => 'Connect Claude (read only)', 'on' => true, 'key' => $key]);
    }
}
