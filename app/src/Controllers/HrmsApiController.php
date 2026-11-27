<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Http;
use Eshcol\Ats\Services\HrmsLink;
use Eshcol\Ats\Services\Migrator;

/**
 * The HRMS's server-to-server link (25 Nov 2026), under /hooks/ so it carries its own signature instead of a form
 * token. Every request must be signed with the link key (HrmsLink); nothing here is reachable by a browser user.
 */
final class HrmsApiController extends Controller
{
    private function guard(): HrmsLink
    {
        header('Cache-Control: no-store');
        if ((new Migrator($this->app))->pending()) {
            Http::json(['error' => 'Recruit is being updated. Try again in a few minutes.'], 503);
        }
        $L = new HrmsLink($this->app);
        if (!$L->configured()) {
            Http::json(['error' => 'The link to the HRMS is not set up in Recruit.'], 503);
        }
        $body = (string) file_get_contents('php://input');
        if (!$L->verify(Http::method(), Http::path(), $_SERVER['HTTP_X_ESHCOL_TIME'] ?? null, $_SERVER['HTTP_X_ESHCOL_SIGNATURE'] ?? null, $body)) {
            // Wrong key, an old request or a stranger: slow down repeated failures from one address.
            Http::throttle('hrmslink:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600) || Http::json(['error' => 'Too many tries.'], 429);
            Http::json(['error' => 'Bad signature.'], 401);
        }
        return $L;
    }

    public function joinings(): never
    {
        Http::json(['joinings' => $this->guard()->joinings()]);
    }

    public function imported(int $id): never
    {
        $L = $this->guard();
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $err = $L->markImported($id, (string) ($in['employee_no'] ?? ''));
        Http::json($err ? ['error' => $err] : ['ok' => true], $err ? 409 : 200);
    }
}
