<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvRequests;
use Eshcol\Ats\Services\Questionnaires;
use Eshcol\Ats\View;

/**
 * "Share your updated CV" (14 Nov 2026). The candidate's personal page (/u/{token}): upload the updated CV and go on
 * to the details form; or "Not now: keep me for the future" (a CV is welcome there too); or "Remove my details".
 * Each answer is a button press on our page, never a bare link, so an email scanner can't answer for them.
 * Your page (/cv-requests): choose the uploads, see the three groups before anything is sent, start, and follow it.
 */
final class CvRequestController extends Controller
{
    // ---------------------------------------------------------------- the candidate's side

    public function show(string|int $token): never
    {
        $r = $this->usable((string) $token);
        $this->svc()->opened($r);
        $this->public('cvrequest/share', ['token' => (string) $token, 'first' => $this->first($r), 'error' => null]);
    }

    /** The upload on the main page: their updated CV, then on to the details form. */
    public function upload(string|int $token): never
    {
        $token = (string) $token;
        $r = $this->usable($token);
        $error = $this->takeCv($r);
        if ($error !== null) {
            $this->public('cvrequest/share', ['token' => $token, 'first' => $this->first($r), 'error' => $error]);
        }
        $svc = $this->svc();
        $svc->answer($r, 'share');
        Http::redirect((string) parse_url($svc->detailsLink($r), PHP_URL_PATH)); // same site, whatever address they came in on
    }

    public function keepPage(string|int $token): never
    {
        $r = $this->usable((string) $token);
        $this->svc()->opened($r);
        $this->public('cvrequest/keep', ['token' => (string) $token, 'first' => $this->first($r), 'error' => null]);
    }

    public function keep(string|int $token): never
    {
        $token = (string) $token;
        $r = $this->usable($token);
        $hasFile = ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $error = $hasFile ? $this->takeCv($r) : null;
        if ($error !== null) {
            $this->public('cvrequest/keep', ['token' => $token, 'first' => $this->first($r), 'error' => $error]);
        }
        $this->svc()->answer($r, 'keep');
        $first = $this->first($r);
        $this->public('cvrequest/done', [
            'heading' => 'Thank you' . ($first !== '' ? ", $first" : '') . '.',
            'body' => 'We will keep your details' . ($hasFile ? ' and your updated CV' : '') . ' and consider you when a suitable requirement comes up with one of our clients.',
            'linkedin' => true,
        ]);
    }

    public function removePage(string|int $token): never
    {
        $r = $this->usable((string) $token);
        $this->svc()->opened($r);
        $this->public('cvrequest/remove', ['token' => (string) $token, 'first' => $this->first($r)]);
    }

    public function remove(string|int $token): never
    {
        $r = $this->usable((string) $token);
        $this->svc()->answer($r, 'remove');
        $this->public('cvrequest/done', [
            'heading' => 'Thank you for letting us know.',
            'body' => 'We will delete your details and will not contact you again. If you apply for a role with us in future, we will start afresh.',
            'linkedin' => false,
        ]);
    }

    /** Reads the uploaded CV into their record. Null when it worked, otherwise what to tell them. */
    private function takeCv(array $r): ?string
    {
        $f = $_FILES['cv'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Please choose your CV file first.';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large. Please send a file under 10 MB.' : 'The upload did not finish. Please try again.';
        }
        $owner = (int) $this->db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
        $res = (new CandidateImporter($this->app))->importFor((int) $r['candidate_id'], $f['tmp_name'], (string) $f['name'], $owner);
        if ($res['outcome'] === 'failed') {
            return 'We could not read that file: ' . $res['message'] . ' Please send a PDF or Word file.';
        }
        if ((int) $res['candidate_id'] !== (int) $r['candidate_id']) {
            return 'We already have this exact file from someone else. Please check you chose your own CV.';
        }
        $this->svc()->cvReceived($r);
        return null;
    }

    private function usable(string $token): array
    {
        $r = $this->svc()->byToken($token);
        if (!$r) {
            $this->public('questionnaire/message', ['heading' => 'This link isn\'t valid any more',
                'body' => 'Please check you copied the whole link from the email. You can also simply reply to our email with your CV attached.'], 404);
        }
        if ($r['answer'] === 'remove') {
            $this->public('questionnaire/message', ['heading' => 'Thank you, we have noted it',
                'body' => 'You asked us on ' . nice_date($r['answered_at']) . ' to remove your details, so there is nothing more to do here. If you apply for a role with us in future, we will start afresh.']);
        }
        return $r;
    }

    private function first(array $r): string
    {
        return Questionnaires::firstName($this->db->one('SELECT first_name, full_name FROM candidates WHERE id = ?', [$r['candidate_id']]));
    }

    private function public(string $template, array $data, int $code = 200): never
    {
        http_response_code($code);
        echo View::render($template, $data + ['title' => 'Eshcol Global'], 'layout/public');
        exit;
    }

    private function svc(): CvRequests
    {
        return new CvRequests($this->app);
    }

    // ---------------------------------------------------------------- your side

    public function index(): never
    {
        $svc = $this->svc();
        $chosen = array_values(array_filter(array_map('intval', (array) ($_GET['batch'] ?? []))));
        $this->view('cvrequest/index', ['title' => 'Share your updated CV', 'svc' => $svc, 'summary' => $svc->summary(), 'batches' => $svc->batches(),
            'chosen' => $chosen, 'plan' => $chosen ? $svc->plan($chosen) : null, 'usedToday' => $svc->usedToday(),
            'owner' => (Auth::user()['role'] ?? '') === 'owner']);
    }

    public function start(): never
    {
        $this->ownerOnly();
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['batch'] ?? []))));
        if (!$ids) {
            Http::flash('error', 'Choose at least one upload first.');
            Http::redirect('/cv-requests');
        }
        $added = $this->svc()->start($ids, (int) Auth::user()['id']);
        $n = array_sum($added);
        Http::flash('ok', $n ? "$n people added: {$added[1]} in group 1, {$added[2]} in group 2, {$added[3]} in group 3. Sending starts within 15 minutes (9 am to 7 pm), at most " . $this->svc()->cap() . ' a day.'
            : 'Nobody to add: everyone in those uploads was left out or has already been asked.');
        Http::redirect('/cv-requests');
    }

    public function settings(): never
    {
        $this->ownerOnly();
        $days = fn (string $k) => implode(',', array_slice(array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) ($_POST[$k] ?? ''))), fn ($d) => $d > 0 && $d <= 60))), 0, 3));
        $sets = ['cvreq_daily_cap' => (string) max(1, min(1000, (int) ($_POST['cap'] ?? 175))), 'cvreq_reminders_g1' => $days('rem1'),
            'cvreq_reminders_other' => $days('rem2'), 'cvreq_paused' => isset($_POST['paused']) ? '1' : '0',
            'cvreq_topup_from' => isset($_POST['topup']) ? '17' : '0', 'cvreq_reserve' => (string) max(0, min(200, (int) ($_POST['reserve'] ?? 25)))];
        foreach ($sets as $k => $v) {
            $this->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        }
        Http::flash('ok', $sets['cvreq_paused'] === '1' ? 'Saved. Sending is paused.' : 'Saved.');
        Http::redirect('/cv-requests');
    }

    private function ownerOnly(): void
    {
        if ((Auth::user()['role'] ?? '') !== 'owner') {
            Http::abort(403, 'Only the owner can start or change this.');
        }
    }
}
