<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\View;
use Eshcol\Ats\Services\Questionnaires;

final class QuestionnaireController extends Controller
{
    // ---- The candidate's side (public, reached only through the personal link) ----

    public function show(string|int $token): never
    {
        $this->page((string) $token, [], []);
    }

    public function submit(string|int $token): never
    {
        $token = (string) $token;
        $svc = new Questionnaires($this->app);
        $q = $this->usable($svc, $token); // shows the right "not available" message when it isn't
        $errors = $svc->submit($q, $_POST);
        if ($errors) {
            $this->page($token, $errors, $_POST);
        }
        $first = $this->firstName((int) $q['candidate_id']);
        $removed = empty($_POST['keep']);
        $type = \Eshcol\Ats\Services\Engagement::forRoles($svc->openRoles((int) $q['id'])); // wording by type of position (15 Nov 2026)
        $this->public('questionnaire/message', [
            'heading' => 'Thank you' . ($first !== '' ? ", $first" : '') . '.',
            'body' => 'Your application is complete. We read every answer ourselves, and we will be in touch if your profile is a good match for ' . \Eshcol\Ats\Services\Engagement::matchFor($type) . '.',
            'extra' => $removed ? 'We have noted that you would like us to delete your details once this role is filled.' : 'You can close this page now.',
        ]);
    }

    /** "I'm no longer interested" (from the final email): a page with one button, so a link scanner can't click it for them. */
    public function decline(string|int $token): never
    {
        $token = (string) $token;
        $q = $this->usable(new Questionnaires($this->app), $token);
        $first = $this->firstName((int) $q['candidate_id']);
        $this->public('questionnaire/decline', ['token' => $token, 'first' => $first, 'title' => 'No longer interested']);
    }

    public function declineSave(string|int $token): never
    {
        $svc = new Questionnaires($this->app);
        $q = $this->usable($svc, (string) $token);
        $svc->decline($q);
        $type = \Eshcol\Ats\Services\Engagement::forRoles($svc->openRoles((int) $q['id']));
        $this->public('questionnaire/message', [
            'heading' => 'Thank you for letting us know.',
            'body' => 'We have closed your application and will not send you any more reminders about it. You are always welcome to apply for future roles ' . \Eshcol\Ats\Services\Engagement::otherRoles($type) . '.',
            'extra' => 'You can close this page now.',
        ]);
    }

    /** The open link behind a token, or the right "not available" page. */
    private function usable(Questionnaires $svc, string $token): array
    {
        $q = $svc->byToken($token);
        $msg = fn (string $h, string $b, int $code = 410) => $this->public('questionnaire/message', ['heading' => $h, 'body' => $b], $code);
        if (!$q) {
            $msg('This link isn\'t valid', 'Please check you copied the whole link from the email. If it still doesn\'t work, reply to the email and we will send you a new one.', 404);
        }
        if ($q['status'] === 'answered') {
            $msg('Thank you, we have your answers', 'Your answers reached us on ' . nice_date($q['answered_at']) . '. There is nothing more to do here.', 200);
        }
        if ($q['status'] === 'declined') {
            $msg('Thank you, we have noted it', 'You told us on ' . nice_date($q['declined_at']) . ' that you are no longer interested, so there is nothing more to do here. You are always welcome to apply for future roles.', 200);
        }
        if ($q['status'] === 'cancelled') {
            $msg('There is a newer link', 'We sent you a newer email. Please use the link in that one.');
        }
        if ($q['status'] === 'expired') {
            $msg('This link has expired', 'If you are still interested, reply to our email and we will send you a new link.');
        }
        if ($q['role_id'] && !$svc->openRoles((int) $q['id'])) {
            $msg('This role has closed', 'The ' . $q['title'] . ' role is no longer open, so there is no need to answer. Thank you for your interest; we will keep you in mind for other suitable roles.');
        }
        return $q;
    }

    private function page(string $token, array $errors, array $old): never
    {
        $svc = new Questionnaires($this->app);
        $q = $this->usable($svc, $token);
        $svc->markOpened($q);
        $roles = $svc->openRoles((int) $q['id']);
        $this->public('questionnaire/form', ['q' => $q, 'token' => $token, 'roles' => $roles,
            'questions' => $svc->questionsFor($roles, $this->db->one('SELECT * FROM candidates WHERE id = ?', [$q['candidate_id']])), 'errors' => $errors,
            'old' => $old, 'first' => $this->firstName((int) $q['candidate_id']), 'title' => 'Complete your application'], $errors ? 422 : 200);
    }

    private function firstName(int $candidateId): string
    {
        $c = $this->db->one('SELECT first_name, full_name FROM candidates WHERE id = ?', [$candidateId]);
        $f = trim((string) ($c['first_name'] ?: strtok((string) ($c['full_name'] ?? ''), ' ')));
        return $f !== '' ? mb_convert_case(mb_strtolower($f), MB_CASE_TITLE) : '';
    }

    private function public(string $template, array $data, int $code = 200): never
    {
        http_response_code($code);
        echo View::render($template, $data + ['title' => 'Eshcol Global'], 'layout/public');
        exit;
    }

    // ---- Your side ----

    /** Send the questionnaire to one candidate for one role. */
    public function sendOne(int $applicationId): never
    {
        $a = $this->db->one('SELECT candidate_id FROM applications WHERE id = ?', [$applicationId]);
        $a || Http::abort(404);
        $r = (new Questionnaires($this->app))->invite($applicationId, (int) Auth::user()['id']);
        Http::flash($r['status'] === 'skipped' ? 'error' : 'ok', $r['status'] === 'skipped' ? 'Not sent: ' . $r['message'] : $r['message']);
        Http::redirect("/candidates/{$a['candidate_id']}");
    }

    /** Send it to everyone on the role at or above the threshold who hasn't had one. */
    public function sendRole(int $roleId): never
    {
        $role = $this->db->one('SELECT * FROM roles WHERE id = ? AND is_pool = 0', [$roleId]);
        $role || Http::abort(404);
        $apps = $this->db->all("
            SELECT a.id FROM applications a JOIN stages s ON s.id = a.stage_id JOIN candidates c ON c.id = a.candidate_id
            WHERE a.role_id = ? AND s.kind = 'active' AND COALESCE(a.override_score, a.score, 0) >= ?
              AND c.email IS NOT NULL AND " . \Eshcol\Ats\Services\Contact::putForwardSql('c') . "
              AND NOT EXISTS (SELECT 1 FROM questionnaires q WHERE q.application_id = a.id AND q.status <> 'cancelled')
            ORDER BY COALESCE(a.override_score, a.score) DESC LIMIT 100", [$roleId, (int) $role['score_threshold']]);
        $svc = new Questionnaires($this->app);
        $sent = $held = 0;
        foreach ($apps as $a) {
            $r = $svc->invite((int) $a['id'], (int) Auth::user()['id']);
            $r['status'] === 'held' ? $held++ : ($r['status'] === 'queued' ? $sent++ : null);
        }
        Http::flash($apps ? 'ok' : 'info', $apps
            ? "Questionnaire sent to $sent candidate" . ($sent === 1 ? '' : 's') . ($held ? "; $held wait for you to check the address under Emails" : '') . '. Answers appear on this page as they come in.'
            : 'Nobody to send to: everyone above the threshold has already had one (or has no email address).');
        Http::redirect("/roles/$roleId");
    }

    /** The one-time request: everyone on open client roles, one email per person. */
    public function askPage(): never
    {
        $svc = new Questionnaires($this->app);
        $people = $svc->askList();
        $this->view('questionnaire/ask', ['title' => 'Candidate details: responses', 'people' => $people, 'responses' => $svc->responses(), 'dash' => $svc->dashboard(),
            'sentToday' => (new \Eshcol\Ats\Services\Mailer($this->app))->sentToday(), 'cap' => (new \Eshcol\Ats\Services\Mailer($this->app))->dailyCap()]);
    }

    public function askSend(): never
    {
        $svc = new Questionnaires($this->app);
        $picked = array_map('intval', (array) ($_POST['cand'] ?? []));
        $byPerson = [];
        foreach ($svc->askList() as $p) {
            $byPerson[$p['candidate']['id']] = $p;
        }
        $sent = $held = $skipped = 0;
        foreach (array_slice($picked, 0, 150) as $cid) {
            $p = $byPerson[$cid] ?? null;
            if (!$p || $p['candidate']['blocked']) {
                $skipped++;
                continue;
            }
            // Only the roles they are relevant to (all of them if none is, when you ticked them anyway).
            $apps = array_filter($p['apps'], fn ($a) => $a['relevant'] && $a['qstatus'] !== 'answered');
            $apps = $apps ?: array_filter($p['apps'], fn ($a) => $a['qstatus'] !== 'answered');
            if (!$apps) {
                $skipped++;
                continue;
            }
            $r = $svc->inviteCandidate($cid, array_column($apps, 'id'), (int) Auth::user()['id']);
            $r['status'] === 'held' ? $held++ : ($r['status'] === 'queued' ? $sent++ : $skipped++);
        }
        Audit::log('details_requested', null, null, ['sent' => $sent, 'held' => $held, 'skipped' => $skipped]);
        Http::flash($sent + $held ? 'ok' : 'info', "Request for details queued for $sent candidate" . ($sent === 1 ? '' : 's')
            . ($held ? "; $held wait for you to check the address under Emails" : '') . ($skipped ? "; $skipped skipped" : '')
            . '. The emails go out within 5 minutes. Reminders follow after 48 hours.');
        Http::redirect('/details-requests');
    }

    /** A message that asks us to remove them: you confirm it, or say it wasn't a removal request. */
    public function removal(int $messageId): never
    {
        $confirm = ($_POST['decision'] ?? '') === 'confirm';
        $cid = (new \Eshcol\Ats\Services\CandidateMessages($this->app))->resolveRemoval($messageId, $confirm);
        $cid || Http::abort(404);
        Http::flash('ok', $confirm ? 'Noted: they asked us not to keep their details. They get no more emails; delete them once no role in progress needs them.' : 'Noted: not a removal request.');
        Http::redirect("/candidates/$cid#messages");
    }

    /** You approve or turn down a new email or mobile the candidate gave. */
    public function contact(int $candidateId): never
    {
        $msg = (new Questionnaires($this->app))->resolveContact($candidateId, ($_POST['decision'] ?? '') === 'accept');
        Http::flash(str_starts_with($msg, 'Not changed') ? 'error' : 'ok', $msg);
        Http::redirect("/candidates/$candidateId");
    }

    public function editor(int $roleId): never
    {
        $role = $this->db->one('SELECT r.*, c.name AS client FROM roles r JOIN clients c ON c.id = r.client_id WHERE r.id = ?', [$roleId]);
        $role || Http::abort(404);
        $svc = new Questionnaires($this->app);
        $this->view('questionnaire/editor', ['title' => 'Screening questions · ' . $role['title'], 'role' => $role,
            'on' => $svc->standardOn($role), 'custom' => $this->db->all('SELECT * FROM role_questions WHERE role_id = ? ORDER BY sort, id', [$roleId]),
            'preview' => $svc->questions($role)]);
    }

    public function save(int $roleId): never
    {
        $role = $this->db->one('SELECT id FROM roles WHERE id = ?', [$roleId]);
        $role || Http::abort(404);
        $std = array_values(array_intersect(Questionnaires::STANDARD, (array) ($_POST['std'] ?? [])));
        $rows = [];
        foreach ((array) ($_POST['q'] ?? []) as $q) {
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if ($prompt === '' || count($rows) >= Questionnaires::MAX_CUSTOM) {
                continue;
            }
            $kind = in_array($q['kind'] ?? '', ['yesno', 'choice', 'text'], true) ? $q['kind'] : 'yesno';
            $options = $flag = [];
            if ($kind === 'choice') {
                // One option per line; a line starting with ! is flagged when chosen.
                foreach (preg_split('/\r?\n/', (string) ($q['options'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === '!') {
                        continue;
                    }
                    $label = mb_substr(ltrim($line, '! '), 0, 120);
                    $options[] = $label;
                    if ($line[0] === '!') {
                        $flag[] = $label;
                    }
                }
                if (count($options) < 2) {
                    Http::flash('error', "\"$prompt\" needs at least two options, one per line.");
                    Http::redirect("/roles/$roleId/questions");
                }
            } elseif ($kind === 'yesno' && in_array($q['flag'] ?? '', ['Yes', 'No'], true)) {
                $flag = [$q['flag']];
            }
            $rows[] = ['kind' => $kind, 'prompt' => mb_substr($prompt, 0, 255), 'options' => $options ? json_encode($options) : null, 'flag_answers' => $flag ? json_encode($flag) : null];
        }
        $this->db->tx(function () use ($roleId, $std, $rows) {
            $this->db->run('UPDATE roles SET screening = ? WHERE id = ?', [json_encode(['std' => $std]), $roleId]);
            $this->db->run('DELETE FROM role_questions WHERE role_id = ?', [$roleId]);
            foreach ($rows as $i => $r) {
                $this->db->insert('role_questions', $r + ['role_id' => $roleId, 'sort' => $i]);
            }
        });
        Audit::log('screening_questions_saved', 'role', $roleId, ['custom' => count($rows)]);
        Http::flash('ok', 'Questions saved. Questionnaires already answered keep the questions they were asked.');
        Http::redirect(isset($_POST['add']) ? "/roles/$roleId/questions#new" : "/roles/$roleId/questions");
    }
}
