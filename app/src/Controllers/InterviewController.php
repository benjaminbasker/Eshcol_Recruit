<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\View;
use Eshcol\Ats\Services\Interviews;
use Eshcol\Ats\Services\Shortlists;

final class InterviewController extends Controller
{
    // ---- Your side ----

    /** Adds the times the client gave you, for a role's round. */
    public function addSlots(int $roleId): never
    {
        $this->db->col('SELECT id FROM roles WHERE id = ?', [$roleId]) || Http::abort(404);
        $n = (new Interviews($this->app))->addSlots($roleId, (int) ($_POST['round'] ?? 1), (string) ($_POST['kind'] ?? 'virtual'),
            (array) ($_POST['times'] ?? []), (int) ($_POST['duration'] ?? 30), 'you');
        Http::flash($n ? 'ok' : 'error', $n ? "$n time slot" . ($n === 1 ? '' : 's') . ' added. Now invite candidates to choose.' : 'No times added: enter at least one date and time in the future.');
        Http::redirect("/roles/$roleId#interviews");
    }

    public function deleteSlot(int $id): never
    {
        $s = $this->db->one('SELECT * FROM interview_slots WHERE id = ?', [$id]);
        $s || Http::abort(404);
        if ($s['interview_id']) {
            Http::flash('error', 'That time is already booked. Cancel the interview first.');
        } else {
            $this->db->run('DELETE FROM interview_slots WHERE id = ?', [$id]);
            Http::flash('ok', 'Time removed.');
        }
        Http::redirect('/roles/' . (int) $s['role_id'] . '#interviews');
    }

    /** Emails the candidate a link to choose one of the open times. */
    public function invite(int $appId): never
    {
        $a = $this->db->one('SELECT candidate_id, role_id FROM applications WHERE id = ?', [$appId]);
        $a || Http::abort(404);
        [$ok, $msg] = (new Interviews($this->app))->invite($appId, max(1, (int) ($_POST['round'] ?? 1)), (int) Auth::user()['id']);
        Http::flash($ok ? 'ok' : 'error', $msg);
        Http::redirect($this->back("/candidates/{$a['candidate_id']}#interviews"));
    }

    /** Invites everyone the client marked "Shortlist" on a role to choose a time. */
    public function inviteShortlisted(int $roleId): never
    {
        $svc = new Interviews($this->app);
        $round = max(1, (int) ($_POST['round'] ?? 1));
        $apps = $this->db->all("SELECT DISTINCT i.application_id FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id
                                WHERE s.role_id = ? AND i.decision = 'shortlist'
                                  AND NOT EXISTS (SELECT 1 FROM interviews v WHERE v.application_id = i.application_id AND v.round = ? AND v.status IN ('choosing','booked','done'))", [$roleId, $round]);
        $sent = 0;
        $last = '';
        foreach ($apps as $a) {
            [$ok, $last] = $svc->invite((int) $a['application_id'], $round, (int) Auth::user()['id']);
            $sent += $ok ? 1 : 0;
        }
        Http::flash($sent ? 'ok' : 'info', $sent ? "Sent to $sent candidate" . ($sent === 1 ? '' : 's') . '. Each can now choose a time.' : ($apps ? $last : 'Nobody to invite: no one the client shortlisted is waiting for this round.'));
        Http::redirect("/roles/$roleId#interviews");
    }

    /** Books a time agreed on the phone, and sends the call letter. */
    public function book(int $appId): never
    {
        $a = $this->db->one('SELECT candidate_id FROM applications WHERE id = ?', [$appId]);
        $a || Http::abort(404);
        [$ok, $msg] = (new Interviews($this->app))->book($appId, max(1, (int) ($_POST['round'] ?? 1)), (string) ($_POST['kind'] ?? 'virtual'),
            (string) ($_POST['when'] ?? ''), (int) ($_POST['duration'] ?? 30),
            ['meeting_link' => $_POST['meeting_link'] ?? '', 'address' => $_POST['address'] ?? '', 'maps_url' => $_POST['maps_url'] ?? ''], (int) Auth::user()['id']);
        Http::flash($ok ? 'ok' : 'error', $msg);
        Http::redirect($this->back("/candidates/{$a['candidate_id']}#interviews"));
    }

    public function outcome(int $id): never
    {
        $iv = $this->db->one('SELECT i.*, a.candidate_id FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = ?', [$id]);
        $iv || Http::abort(404);
        $u = Auth::user();
        $note = (new Interviews($this->app))->outcome($id, (string) ($_POST['outcome'] ?? ''), trim((string) ($_POST['feedback'] ?? '')), (int) $u['id'], ($u['role'] ?? '') === 'owner');
        $note ? Http::flash('error', $note) : Http::flash('ok', 'Saved: ' . (Interviews::OUTCOMES[$_POST['outcome'] ?? ''] ?? 'outcome') . '.');
        Http::redirect($this->back("/candidates/{$iv['candidate_id']}#interviews"));
    }

    public function cancel(int $id): never
    {
        $iv = $this->db->one('SELECT i.*, a.candidate_id FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = ?', [$id]);
        $iv || Http::abort(404);
        (new Interviews($this->app))->cancel($id, (int) Auth::user()['id']);
        Http::flash('ok', 'Interview cancelled' . ($iv['status'] === 'booked' ? '. Let the candidate and client know (WhatsApp or phone); the time is free again.' : '.'));
        Http::redirect($this->back("/candidates/{$iv['candidate_id']}#interviews"));
    }

    /** The call letter as it was (or will be) sent, for a quick look. */
    public function letter(int $id): never
    {
        $svc = new Interviews($this->app);
        $iv = $this->db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        ($iv && $iv['starts_at']) || Http::abort(404);
        [$subject, $body] = $svc->letter($iv, $svc->application((int) $iv['application_id']));
        Http::json(['subject' => $subject, 'body' => $body]);
    }

    private function back(string $default): string
    {
        $b = (string) ($_POST['back'] ?? '');
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $b) ? $b : $default;
    }

    // ---- The candidate's side (public, reached only through their link) ----

    public function choose(string|int $token): never
    {
        $this->page((string) $token, null);
    }

    public function pick(string|int $token): never
    {
        $svc = new Interviews($this->app);
        $iv = $svc->byToken((string) $token);
        if (!$iv) {
            $this->page((string) $token, null);
        }
        if (isset($_POST['none'])) {
            $svc->noneWork($iv, mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 1000));
            $this->publicPage('questionnaire/message', ['heading' => 'Thank you, we will be in touch', 'body' => 'We have told the recruiter that these times don\'t work for you. They will contact you shortly to find another time.']);
        }
        [$ok, $msg] = $svc->pick($iv, (int) ($_POST['slot'] ?? 0));
        $this->page((string) $token, $ok ? null : $msg);
    }

    public function ics(string|int $token): never
    {
        $svc = new Interviews($this->app);
        $iv = $svc->byToken((string) $token);
        if (!$iv || $iv['status'] !== 'booked') {
            Http::abort(404);
        }
        header_remove('Content-Security-Policy');
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="Interview.ics"');
        echo $svc->ics($iv, $svc->application((int) $iv['application_id']), false);
        exit;
    }

    /** The client's logo on the candidate's interview page. */
    public function logo(string|int $token): never
    {
        $svc = new Interviews($this->app);
        $iv = $svc->byToken((string) $token);
        $iv || Http::abort(404);
        $a = $svc->application((int) $iv['application_id']);
        (new \Eshcol\Ats\Services\ClientLogos($this->app))->send((int) $a['client_id']);
    }

    private function page(string $token, ?string $error): never
    {
        $svc = new Interviews($this->app);
        $iv = $svc->byToken($token);
        if (!$iv || $iv['status'] === 'cancelled') {
            $this->publicPage('questionnaire/message', ['heading' => 'This link isn\'t valid any more', 'body' => 'The interview may have been rearranged. Please check your latest email from us, or reply to it and we will help.'], 404);
        }
        $a = $svc->application((int) $iv['application_id']);
        $this->publicPage('interview/choose', ['iv' => $iv, 'a' => $a, 'slots' => $iv['status'] === 'choosing' ? $svc->openSlots((int) $a['role_id'], (int) $iv['round']) : [],
            'token' => $token, 'error' => $error, 'when' => $iv['starts_at'] ? $svc->when($iv) : null, 'title' => 'Your interview']);
    }

    private function publicPage(string $template, array $data, int $code = 200): never
    {
        http_response_code($code);
        echo View::render($template, $data + ['title' => 'Eshcol Global'], 'layout/public');
        exit;
    }

    // ---- The client suggests times from their shortlist page ----

    public function clientSlots(string|int $token): never
    {
        $s = (new Shortlists($this->app))->byToken((string) $token);
        if (!$s || !in_array($s['status'], ['sent', 'viewed', 'answered'], true) || strtotime($s['expires_at']) < time()) {
            Http::abort(404);
        }
        // A window and an interview length (13 Nov 2026): 5–6 pm at 20 minutes is three times. Single times still work.
        $len = (int) ($_POST['duration'] ?? 30);
        $len = in_array($len, \Eshcol\Ats\Services\ClientPortal::LENGTHS, true) ? $len : 30;
        // Several days at once (16 Nov 2026): date[], from[], to[] rows.
        $times = array_merge((array) ($_POST['times'] ?? []), \Eshcol\Ats\Services\ClientPortal::daysTimes($_POST, $len));
        $n = (new Interviews($this->app))->addSlots((int) $s['role_id'], max(1, (int) ($_POST['round'] ?? 1)), (string) ($_POST['kind'] ?? 'virtual'),
            $times, $len, 'client', (int) $s['id']);
        if ($n) {
            Audit::log('client_slots_added', 'shortlist', (int) $s['id'], ['count' => $n]);
            $this->notifySlots($s, $n, \Eshcol\Ats\Services\ClientPortal::timesSummary(\Eshcol\Ats\Services\ClientPortal::daysTimes($_POST, $len), $len));
        }
        http_response_code(200);
        echo View::render('questionnaire/message', ['title' => 'Eshcol Global', 'heading' => $n ? 'Thank you, we have your times' : 'No times were added',
            'body' => $n ? "We have $n time" . ($n === 1 ? '' : 's') . ' from you. We will offer them to the candidates you shortlisted and confirm each interview with you.' : 'Please go back and enter at least one date and time in the future.'], 'layout/public');
        exit;
    }

    private function notifySlots(array $s, int $n, string $when = ''): void
    {
        $url = rtrim((string) ($this->app->config['app']['url'] ?? ''), '/');
        $text = "{$s['client']} suggested $n interview time" . ($n === 1 ? '' : 's') . " for {$s['title']}" . ($when !== '' ? ": $when" : '') . ".\n\nOpen the role to invite the shortlisted candidates to choose: $url/roles/{$s['role_id']}#interviews\n";
        foreach ($this->db->all("SELECT DISTINCT email, name FROM users WHERE is_active = 1 AND (role = 'owner' OR id = ?)", [(int) $s['created_by']]) as $u) {
            (new \Eshcol\Ats\Services\Mailer($this->app))->enqueue(['kind' => 'client_reply', 'to_email' => $u['email'], 'to_name' => $u['name'],
                'subject' => "{$s['client']} suggested interview times for {$s['title']}", 'html' => '<p style="font-family:Arial, sans-serif; font-size:15px;">' . nl2br(htmlspecialchars($text)) . '</p>', 'text' => $text, 'priority' => 15]);
        }
    }
}
