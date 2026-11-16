<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * "Share your updated CV" (approved by Benjamin 14 Nov 2026): one email to people whose CV we hold from earlier
 * applications, asking for their updated CV and details, or whether to keep or remove their details.
 *
 * Who goes first (his order):
 *   1. Likely to make it: on an open position and assessed Strong or Possible, best first;
 *   2. the right field for an open position, but another city (or not assessed as a fit), newest CV first;
 *   3. everyone else (other fields), newest CV first.
 * At most 175 a day (its own allowance, lowest priority, so other email always goes first), between 9 am and 7 pm.
 * Reminders: day 3 for everyone, and day 7 for group 1; they go before new emails. A week after the last one, an
 * unanswered request is marked "no answer": we don't contact them again until they apply themselves, and from
 * 14 May 2027 (DPDP consent rules) their details are due for deletion 30 days later.
 */
final class CvRequests
{
    public const KIND = 'cv_request';
    public const GROUPS = [1 => 'Likely to make it (Strong or Possible on an open position)', 2 => 'Right field (another city, or not yet a likely fit)', 3 => 'Other fields'];
    public const NO_ANSWER_AFTER_DAYS = 7;
    public const DPDP_FROM = '2027-05-14';
    public const LINK_DAYS = 90;
    public const HOURS = [9, 19];

    /** @var callable|null fixes "now" in tests: fn(): int */
    public $clock = null;

    public function __construct(private readonly App $app)
    {
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }

    public function cap(): int
    {
        return max(1, min(1000, (int) $this->app->setting('cvreq_daily_cap', '175')));
    }

    /** Reminder days after the first email, for a group: [3, 7] for group 1, [3] for the others (Settings). */
    public function reminderDays(int $grp): array
    {
        $raw = (string) $this->app->setting($grp === 1 ? 'cvreq_reminders_g1' : 'cvreq_reminders_other', $grp === 1 ? '3,7' : '3');
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn ($d) => $d > 0 && $d <= 60)));
        sort($days);
        return array_slice($days, 0, 3);
    }

    public function paused(): bool
    {
        return $this->app->setting('cvreq_paused', '0') === '1';
    }

    // ---------------------------------------------------------------- choosing who, and in what order

    /** Upload batches that can be chosen: the latest 60 with files, newest first. */
    public function batches(): array
    {
        return $this->app->db->all('SELECT b.id, b.created_at, b.label, b.channel, r.title AS role, COUNT(DISTINCT i.candidate_id) AS people
            FROM upload_batches b LEFT JOIN roles r ON r.id = b.role_id JOIN upload_items i ON i.batch_id = b.id AND i.candidate_id IS NOT NULL
            GROUP BY b.id ORDER BY b.id DESC LIMIT 60');
    }

    /**
     * Sorts the people from the chosen uploads into the three groups, best first, and says who is left out and why.
     * @return array{groups: array<int, int[]>, excluded: array<string,int>, waiting: array<string,int>, roles: array}
     */
    public function plan(array $batchIds): array
    {
        $db = $this->app->db;
        $ids = array_values(array_filter(array_map('intval', $batchIds)));
        $out = ['groups' => [1 => [], 2 => [], 3 => []], 'excluded' => [], 'waiting' => ['reading' => 0, 'categorising' => 0, 'assessing' => 0], 'roles' => []];
        if (!$ids) {
            return $out;
        }
        $in = implode(',', $ids);
        $people = $db->all("SELECT DISTINCT c.id, c.email, c.do_not_contact, c.removal_requested_at, c.unreliable_at, c.created_at
            FROM upload_items i JOIN candidates c ON c.id = i.candidate_id WHERE i.batch_id IN ($in)");

        // Open positions, with the fields of work they take.
        $cat = new Categoriser($this->app);
        $fields = [];
        foreach ($db->all("SELECT * FROM roles WHERE status = 'open' AND is_pool = 0") as $r) {
            $out['roles'][] = ['id' => (int) $r['id'], 'title' => $r['title'], 'location' => $r['location']];
            foreach ($cat->roleFunctions($r) as $code) {
                $fields[$code] = true;
            }
        }
        $health = new EmailHealth($this->app);
        $already = array_flip(array_map('intval', array_column($db->all("SELECT DISTINCT candidate_id FROM cv_requests WHERE status <> 'cancelled'"), 'candidate_id')));
        $since = date('Y-m-d H:i:s', $this->now() - 30 * 86400);
        $emailedRecently = array_flip(array_map('intval', array_column($db->all("SELECT DISTINCT candidate_id FROM email_queue
            WHERE candidate_id IS NOT NULL AND status = 'sent' AND sent_at >= ? AND kind IN ('questionnaire','message','interview','ack')", [$since]), 'candidate_id')));

        $ranked = [1 => [], 2 => [], 3 => []];
        foreach ($people as $p) {
            $cid = (int) $p['id'];
            $why = $this->leaveOut($p, $health, isset($already[$cid]), isset($emailedRecently[$cid]));
            if ($why) {
                $out['excluded'][$why] = ($out['excluded'][$why] ?? 0) + 1;
                continue;
            }
            if ($db->col("SELECT 1 FROM cv_ai_reads WHERE candidate_id = ? AND status IN ('pending','reading')", [$cid])) {
                $out['waiting']['reading']++;
            }
            if ($db->col("SELECT 1 FROM categorise_queue WHERE candidate_id = ? AND status IN ('pending','working')", [$cid])) {
                $out['waiting']['categorising']++;
            }
            $best = $db->one("SELECT f.score, f.band, f.application_id, f.role_id FROM fit_assessments f JOIN applications a ON a.id = f.application_id
                JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = f.role_id
                WHERE f.candidate_id = ? AND f.status = 'done' AND f.band IN ('Strong','Possible') AND s.kind = 'active' AND r.status = 'open' AND r.is_pool = 0
                ORDER BY f.band = 'Strong' DESC, f.score DESC LIMIT 1", [$cid]);
            if ($best) {
                $ranked[1][] = [$cid, ($best['band'] === 'Strong' ? 200 : 100) + (int) $best['score'], (int) $best['role_id'], (int) $best['application_id']];
                continue;
            }
            if ($db->col("SELECT 1 FROM fit_assessments f JOIN roles r ON r.id = f.role_id WHERE f.candidate_id = ? AND f.status IN ('pending','reading') AND r.status = 'open'", [$cid])) {
                $out['waiting']['assessing']++;
            }
            $codes = $db->all('SELECT function_code, secondary_code FROM candidate_categories WHERE candidate_id = ? AND is_current = 1', [$cid]);
            $mine = [];
            foreach ($codes as $c) {
                $mine[] = $c['function_code'];
                if ($c['secondary_code']) {
                    $mine[] = $c['secondary_code'];
                }
            }
            $right = (bool) array_intersect_key(array_flip($mine), $fields);
            $ranked[$right ? 2 : 3][] = [$cid, (int) strtotime((string) $p['created_at']), null, null];
        }
        foreach ($ranked as $g => $rows) {
            usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
            $out['groups'][$g] = $rows;
        }
        return $out;
    }

    /** Why someone is left out of the campaign, or null. */
    private function leaveOut(array $c, EmailHealth $health, bool $asked, bool $recent): ?string
    {
        return match (true) {
            Contact::silenced($c) => 'Do not contact, or asked to be removed',
            !$c['email'] || !filter_var($c['email'], FILTER_VALIDATE_EMAIL) => 'No email address',
            $health->stopReason((string) $c['email'], (int) $c['id']) !== null => 'Email address does not work',
            (bool) $c['unreliable_at'] => 'Marked unreliable',
            $asked => 'Already asked in an earlier send',
            $recent => 'Emailed by us in the last 30 days',
            default => null,
        };
    }

    /** Puts the planned people in the queue, in order. Returns how many were added per group. */
    public function start(array $batchIds, int $userId): array
    {
        $plan = $this->plan($batchIds);
        $db = $this->app->db;
        $added = [1 => 0, 2 => 0, 3 => 0];
        $db->tx(function () use ($db, $plan, $userId, &$added) {
            $base = (int) $db->col('SELECT COALESCE(MAX(rank_key), 0) FROM cv_requests');
            $n = 0;
            foreach ($plan['groups'] as $g => $rows) {
                foreach ($rows as [$cid, , $roleId, $appId]) {
                    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                    $db->insert('cv_requests', ['candidate_id' => $cid, 'grp' => $g, 'rank_key' => $base + ++$n, 'role_id' => $roleId, 'application_id' => $appId,
                        'token_hash' => hash('sha256', $token), 'token_enc' => Secrets::seal($this->app, $token, 'cv_request'), 'created_by' => $userId]);
                    $added[$g]++;
                }
            }
        });
        Audit::log('cv_requests_started', 'campaign', null, ['batches' => array_map('intval', $batchIds), 'added' => $added]);
        return $added;
    }

    // ---------------------------------------------------------------- sending (every 15 minutes, from cron)

    /** Campaign emails sent today, plus any still waiting in the email queue (so a busy day doesn't pile them up). */
    public function usedToday(): int
    {
        return (int) $this->app->db->col("SELECT COUNT(*) FROM email_queue WHERE kind = ? AND ((status = 'sent' AND sent_at >= ?) OR status IN ('pending','held'))",
            [self::KIND, date('Y-m-d 00:00:00', $this->now())]);
    }

    /**
     * How many campaign emails may be queued now: what is left of the 175, or, from 5 pm (the evening top-up,
     * Benjamin 14 Nov), whatever is left of the overall daily limit less 25 kept spare for evening email.
     */
    public function room(): int
    {
        $room = $this->cap() - $this->usedToday();
        if ((int) date('G', $this->now()) >= $this->topupFrom()) {
            $mailer = new Mailer($this->app);
            $allUsed = (int) $this->app->db->col("SELECT COUNT(*) FROM email_queue WHERE (status = 'sent' AND sent_at >= ?) OR status IN ('pending','held')",
                [date('Y-m-d 00:00:00', $this->now())]);
            $room = max($room, $mailer->dailyCap() - $this->reserve() - $allUsed);
        }
        return max(0, $room);
    }

    /** The hour the evening top-up starts (0 = never). */
    public function topupFrom(): int
    {
        $h = (int) $this->app->setting('cvreq_topup_from', '17');
        return $h > 0 && $h < 24 ? $h : 99;
    }

    public function reserve(): int
    {
        return max(0, (int) $this->app->setting('cvreq_reserve', '25'));
    }

    public function tick(): string
    {
        $db = $this->app->db;
        $now = $this->now();
        // Unanswered after the last reminder: no answer (costs no email).
        $closed = 0;
        foreach ($db->all("SELECT * FROM cv_requests WHERE status = 'sent' AND next_at IS NOT NULL AND next_at <= ? AND reminders >= 0", [date('Y-m-d H:i:s', $now)]) as $r) {
            if ((int) $r['reminders'] >= count($this->reminderDays((int) $r['grp']))) {
                $this->noAnswer($r);
                $closed++;
            }
        }
        if ($this->paused()) {
            return $closed ? "$closed marked no answer; sending paused" : '';
        }
        $hour = (int) date('G', $now);
        if ($hour < self::HOURS[0] || $hour >= self::HOURS[1]) {
            return $closed ? "$closed marked no answer" : '';
        }
        $room = $this->room();
        $sent = $reminded = $skipped = 0;
        if ($room > 0) {
            // Reminders first, then new emails; each in group order, best first.
            $due = $db->all("SELECT * FROM cv_requests WHERE status = 'sent' AND next_at <= ? ORDER BY grp, rank_key LIMIT " . (int) $room, [date('Y-m-d H:i:s', $now)]);
            foreach ($due as $r) {
                $this->send($r, 'reminder') ? $reminded++ : $skipped++;
            }
            $room -= $reminded;
            if ($room > 0) {
                foreach ($db->all("SELECT * FROM cv_requests WHERE status = 'queued' ORDER BY grp, rank_key LIMIT " . (int) $room) as $r) {
                    $this->send($r, 'first') ? $sent++ : $skipped++;
                }
            }
        }
        $bits = array_filter([$sent ? "$sent sent" : '', $reminded ? "$reminded reminders" : '', $skipped ? "$skipped left out" : '', $closed ? "$closed no answer" : '']);
        return implode(', ', $bits);
    }

    /** Queues the first email or a reminder for one request. False when the person is left out instead. */
    public function send(array $r, string $step): bool
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$r['candidate_id']]);
        $why = $c ? $this->leaveOut($c, new EmailHealth($this->app), false, false) : 'Candidate deleted';
        if ($why) {
            $db->run("UPDATE cv_requests SET status = 'skipped', skip_reason = ?, next_at = NULL WHERE id = ?", [$why, $r['id']]);
            return false;
        }
        [$subject, $html, $text] = $this->render($c, $r, $step);
        $emailId = (new Mailer($this->app))->enqueue(['kind' => self::KIND, 'candidate_id' => (int) $c['id'], 'to_email' => $c['email'], 'to_name' => $c['full_name'],
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => ['cv_request' => (int) $r['id'], 'step' => $step], 'priority' => 95]);
        $sentAt = $step === 'first' ? $this->now() : strtotime((string) $r['sent_at']);
        $reminders = $step === 'first' ? 0 : (int) $r['reminders'] + 1;
        $db->run('UPDATE cv_requests SET status = ?, email_id = ?, sent_at = ?, reminders = ?, next_at = ? WHERE id = ?',
            ['sent', $emailId, date('Y-m-d H:i:s', $sentAt), $reminders, $this->nextAt((int) $r['grp'], $sentAt, $reminders), $r['id']]);
        if ($step === 'first') {
            (new Consents($this->app))->record((int) $c['id'], 'notice_sent', 'cv_request', $emailId);
        }
        return true;
    }

    /** When the next step is due: the next reminder, or (after the last one) the no-answer mark a week later. */
    public function nextAt(int $grp, int $sentAt, int $remindersSent): string
    {
        $days = $this->reminderDays($grp);
        $d = $days[$remindersSent] ?? ((end($days) ?: 0) + self::NO_ANSWER_AFTER_DAYS);
        return date('Y-m-d H:i:s', $sentAt + $d * 86400);
    }

    private function noAnswer(array $r): void
    {
        $db = $this->app->db;
        $db->run("UPDATE cv_requests SET status = 'no_answer', next_at = NULL WHERE id = ? AND status = 'sent'", [$r['id']]);
        if (date('Y-m-d', $this->now()) >= self::DPDP_FROM) {
            $due = date('Y-m-d', $this->now() + 30 * 86400);
            $db->run('UPDATE candidates SET purge_after = LEAST(COALESCE(purge_after, ?), ?) WHERE id = ?', [$due, $due, $r['candidate_id']]);
        }
    }

    /** True when this candidate didn't answer and hasn't applied since: don't contact them about new roles. */
    public static function silenced(App $app, int $candidateId): bool
    {
        return (bool) $app->db->col("SELECT 1 FROM cv_requests WHERE candidate_id = ? AND status = 'no_answer'", [$candidateId]);
    }

    /** They applied again (a new CV came in by upload or email): the "no answer" no longer holds. */
    public static function reapplied(App $app, int $candidateId): void
    {
        $app->db->run("UPDATE cv_requests SET status = 'cancelled', skip_reason = 'Applied again' WHERE candidate_id = ? AND status = 'no_answer'", [$candidateId]);
    }

    // ---------------------------------------------------------------- the email

    public function link(array $r): string
    {
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/u/' . Secrets::open($this->app, (string) $r['token_enc'], 'cv_request');
    }

    /** [subject, html, text] for the first email or a reminder. Group 1 says the CV matches a current requirement. */
    public function render(array $c, array $r, string $step): array
    {
        $first = Questionnaires::firstName($c);
        // A doubtful name ("Good Communication") is never used: "Thank you." is better than a wrong name.
        $file = (string) $this->app->db->col('SELECT original_name FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]);
        if (NameCheck::doubtful($c['full_name'] ?? null, $file !== '' ? $file : null)) {
            $first = '';
        }
        $site = isset(CHANNELS[$c['channel'] ?? '']) && !in_array($c['channel'], ['email', 'database', 'other', 'referral', 'walk_in'], true) ? CHANNELS[$c['channel']] : null;
        $where = $site ? "on $site" : '';
        $link = $this->link($r);
        $why = (int) $r['grp'] === 1 ? 'it matches a current requirement of one of our clients' : 'we would like to consider you for current and upcoming requirements of our clients';
        $b = fn (string $s) => '<strong style="color:#320A67;">' . $s . '</strong>';
        $subject = $step === 'first' ? 'Thank you for your application' . ($site ? " on $site" : '') : 'Reminder: please share your updated CV';
        $applied = 'A while ago you applied to one of our job postings' . ($where ? " $where" : '') . '.';
        $p = [
            'title' => $subject,
            'preheader' => $step === 'first' ? 'Share your updated CV in a few minutes, or tell us to keep or remove your details.' : 'A gentle reminder: share your updated CV, or let us know either way.',
            'eyebrow' => $step === 'first' ? 'Your application' : 'A gentle reminder',
            'headline' => $first !== '' ? "Thank you, $first." : 'Thank you.',
            'sub_html' => CandidateEmail::h($applied) . " We have kept your CV, and $why.",
            'sub_text' => "$applied We have kept your CV, and $why.",
            'action' => [
                'kicker' => 'Open to a job change? About 3 minutes',
                'paras' => [[
                    ($step === 'first' ? '' : 'We wrote a few days ago and haven\'t heard back yet. ') . 'If you are open to a change, please ' . $b('share your updated CV') . ' and a few details that CVs rarely include. Press the button below: it opens a short page where you upload your CV and fill in:',
                    ($step === 'first' ? '' : "We wrote a few days ago and haven't heard back yet. ") . 'If you are open to a change, please share your updated CV and a few details that CVs rarely include. Press the link below: it opens a short page where you upload your CV and fill in:',
                ]],
                'list' => ['Your current CTC and expected CTC, in lakhs per annum', 'How soon you could join', 'The city and locality you live in'],
                'buttons' => [['Share my updated CV', $link], ['Not now: keep me for the future', "$link/keep", false]],
                'small' => 'Works on your phone, with nothing to sign up for. The link is personal to you. You can also simply reply to this email with your CV attached.',
            ],
            'after' => [
                [$b('Not looking right now, but may be in future?') . ' Press "Not now: keep me for the future". You can still upload your updated CV there, and we will consider you when a suitable requirement comes up.',
                    'Not looking right now, but may be in future? Use "Not now: keep me for the future". You can still upload your updated CV there, and we will consider you when a suitable requirement comes up.'],
                [$b('No longer interested?') . ' <a href="' . CandidateEmail::h("$link/remove") . '" target="_blank" style="color:#7946B2;">Remove my details</a>, and we will delete them and not contact you again.',
                    "No longer interested? Remove my details, and we will delete them and not contact you again:\n$link/remove"],
            ],
            'about' => ['We keep your details only to consider you for suitable roles with our clients, as our <a href="' . CandidateEmail::LINKS['privacy'] . '" target="_blank" style="color:#7946B2;">privacy policy</a> explains. When we put your profile forward to a client, we share your CV and contact details with them.',
                'We keep your details only to consider you for suitable roles with our clients, as our privacy policy explains: ' . CandidateEmail::LINKS['privacy'] . "\nWhen we put your profile forward to a client, we share your CV and contact details with them."],
            'footer_reason' => 'You are receiving this email because you applied to a job posting by Eshcol Global' . ($where ? " $where" : '') . '.',
        ];
        [$html, $text] = CandidateEmail::render($p);
        return [$subject, $html, $text];
    }

    // ---------------------------------------------------------------- the candidate's side

    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $r = $this->app->db->one('SELECT * FROM cv_requests WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$r || in_array($r['status'], ['queued', 'cancelled', 'skipped'], true) || !$r['sent_at'] || strtotime((string) $r['sent_at']) < $this->now() - self::LINK_DAYS * 86400) {
            return null;
        }
        return $r;
    }

    public function opened(array $r): void
    {
        $this->app->db->run('UPDATE cv_requests SET opened_at = COALESCE(opened_at, NOW()) WHERE id = ?', [$r['id']]);
    }

    /** Records their answer (share / keep / remove). A later answer replaces an earlier one. */
    public function answer(array $r, string $answer): void
    {
        $db = $this->app->db;
        $cid = (int) $r['candidate_id'];
        $db->run("UPDATE cv_requests SET status = 'answered', answer = ?, answered_at = NOW(), next_at = NULL WHERE id = ?", [$answer, $r['id']]);
        $consents = new Consents($this->app);
        if ($answer === 'remove') {
            // As "Please remove me" today: flagged for you to delete; nothing more is sent to them.
            $db->run('UPDATE candidates SET removal_requested_at = COALESCE(removal_requested_at, NOW()) WHERE id = ?', [$cid]);
            $db->run("UPDATE email_queue SET status = 'cancelled' WHERE candidate_id = ? AND status IN ('pending','held')", [$cid]);
            $consents->record($cid, 'withdrawn', 'cv_request');
        } else {
            $consents->record($cid, 'given', 'cv_request', null, $answer === 'keep' ? 'Keep me for the future' : 'Shared an updated CV');
        }
        Audit::log('cv_request_' . $answer, 'candidate', $cid, ['request' => (int) $r['id']]);
    }

    /** Their updated CV arrived through the page. */
    public function cvReceived(array $r): void
    {
        $this->app->db->run('UPDATE cv_requests SET cv_at = NOW() WHERE id = ?', [$r['id']]);
    }

    /** A details link for them: on their position for group 1, otherwise a general one. Returns the link. */
    public function detailsLink(array $r): string
    {
        $q = new Questionnaires($this->app);
        $open = $q->openLead((int) $r['candidate_id']);
        $appOk = $r['application_id'] && $this->app->db->col("SELECT 1 FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ? AND r.status = 'open'", [$r['application_id']]);
        $id = $open ? (int) $open['id'] : $q->create($appOk ? (int) $r['application_id'] : null, null, (int) $r['candidate_id']);
        return $q->link($id);
    }

    // ---------------------------------------------------------------- progress

    /** Per group: in the queue, sent, opened, CVs received, keep, remove, no answer, left out. */
    public function summary(): array
    {
        $rows = $this->app->db->all("SELECT grp, COUNT(*) AS total, SUM(status = 'queued') AS queued, SUM(sent_at IS NOT NULL) AS sent, SUM(opened_at IS NOT NULL) AS opened,
            SUM(cv_at IS NOT NULL) AS cvs, SUM(answer = 'share') AS share, SUM(answer = 'keep') AS keep_, SUM(answer = 'remove') AS remove_,
            SUM(status = 'no_answer') AS no_answer, SUM(status = 'skipped') AS skipped, SUM(reminders) AS reminders FROM cv_requests GROUP BY grp ORDER BY grp");
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['grp']] = array_map('intval', $r);
        }
        return $out;
    }

    /** The latest request for a candidate (for the candidate page). */
    public function forCandidate(int $candidateId): ?array
    {
        return $this->app->db->one('SELECT * FROM cv_requests WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$candidateId]);
    }
}
