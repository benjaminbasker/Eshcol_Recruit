<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Services\WaMessages;
use Eshcol\Ats\View;

/**
 * The "No longer interested" button in the call_request WhatsApp (28 Nov 2026): the candidate's own signed link,
 * /n/<application id>-<signature>. A confirm page, then the application moves to Withdrew with a note.
 */
final class NoLongerController extends Controller
{
    private function public(string $template, array $data, int $code = 200): never
    {
        http_response_code($code);
        echo View::render($template, $data + ['title' => 'Eshcol Global'], 'layout/public');
        exit;
    }

    private function application(string $token): array
    {
        $appId = (new WaMessages($this->app))->appFromToken($token);
        $a = $appId ? $this->db->one('SELECT a.id, a.stage_id, c.first_name, c.full_name, r.title FROM applications a JOIN candidates c ON c.id = a.candidate_id
            JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$appId]) : null;
        if (!$a) {
            $this->public('questionnaire/message', ['heading' => 'This link is not valid.', 'body' => 'Please reply to our WhatsApp message instead, and we will take care of it.'], 404);
        }
        return $a;
    }

    public function show(string|int $token): never
    {
        $token = (string) $token;
        $a = $this->application($token);
        $this->public('questionnaire/no_longer', ['token' => $token, 'first' => \Eshcol\Ats\Services\Questionnaires::firstName($a), 'role' => (string) $a['title'], 'title' => 'No longer interested']);
    }

    public function confirm(string|int $token): never
    {
        $token = (string) $token;
        $a = $this->application($token);
        $err = (new WaMessages($this->app))->withdraw((int) $a['id']);
        $this->public('questionnaire/message', $err ? ['heading' => 'Something went wrong.', 'body' => $err]
            : ['heading' => 'Thank you for letting us know.', 'body' => 'We have closed your application for ' . $a['title'] . '. You are always welcome to apply for future roles with Eshcol Global.']);
    }
}
