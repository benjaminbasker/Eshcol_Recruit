<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The thank-you email every applicant gets (design doc §2.5a; reworked 7 Nov 2026).
 *
 * - It carries "Complete your application": the personal link for the details we need
 *   (salary, lowest acceptable salary, joining time, a check of what we read from the CV).
 *   Every CV gets it, with or without a role: Benjamin needs these details for any CV.
 * - Once per candidate per role. The same CV arriving twice sends nothing more.
 * - Roles applied for close together share one email (and one link): an acknowledgement waits
 *   10 minutes before it goes, and any other role applied for meanwhile is added to it.
 * - Only the role they applied for is named, never the roles the app routed them to.
 *   With no role, it says "a role with an Eshcol Global client": the job is the client's.
 * - When in doubt about the address, it doesn't send: the email is held for you to check.
 *   A wrong address tells a stranger that someone applied for a job.
 */
final class Acknowledger
{
    private const DELAY_MINUTES = 10;

    /** @var callable|null DNS check, replaceable in tests */
    public $dns = null;

    private ?array $own = null;

    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return $this->app->setting('ack_enabled', '1') === '1';
    }

    /**
     * Queues (or adds to) the acknowledgement for this application.
     * @return array{status:string, message:string, email_id?:int}
     *   status: queued | held | merged | skipped
     */
    public function acknowledge(int $candidateId, ?int $roleId, ?int $userId = null): array
    {
        $db = $this->app->db;
        $roleId = $roleId ?: 0;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c) {
            return $this->r('skipped', 'Candidate not found.');
        }
        if ($roleId && $db->col('SELECT is_pool FROM roles WHERE id = ?', [$roleId])) {
            return $this->r('skipped', 'Talent pools are never named to candidates.');
        }
        if (Contact::silenced($c)) {
            return $this->r('skipped', 'Marked do not contact (or asked not to be kept): no thank-you email.');
        }
        if ($db->col('SELECT 1 FROM acknowledgements WHERE candidate_id = ? AND role_id = ?', [$candidateId, $roleId])) {
            return $this->r('skipped', 'Already thanked for this role.');
        }
        if (!$c['email']) {
            return $this->r('skipped', 'No email address found, so no thank-you email.');
        }

        // An acknowledgement still waiting to go? Add this role to it rather than sending a second email.
        $open = $db->one("SELECT * FROM email_queue WHERE candidate_id = ? AND kind = 'ack' AND status IN ('pending','held')
                          AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1", [$candidateId]);
        if ($open) {
            $meta = json_decode((string) $open['meta'], true) ?: [];
            $roles = array_values(array_unique(array_merge($meta['roles'] ?? [], [$roleId])));
            $lead = self::leadOf($meta);
            if ($lead && $roleId) {
                $this->attachRole($lead, $candidateId, $roleId);
            } elseif (!$lead) {
                $lead = $this->leadFor($candidateId, $roleId, $userId);
            }
            [$subject, $html, $text] = $this->render($c, $roles, $lead);
            $db->run('UPDATE email_queue SET subject = ?, html = ?, text = ?, meta = ?, not_before = GREATEST(not_before, NOW() + INTERVAL 2 MINUTE) WHERE id = ?',
                [$subject, $html, $text, json_encode(['roles' => $roles, 'lead' => $lead]), $open['id']]);
            if ($lead) {
                $db->run('UPDATE questionnaires SET email_id = ? WHERE id = ? OR parent_id = ?', [$open['id'], $lead, $lead]);
            }
            $db->run('INSERT IGNORE INTO acknowledgements (candidate_id, role_id, email_id) VALUES (?, ?, ?)', [$candidateId, $roleId, $open['id']]);
            return $this->r('merged', 'Added to the thank-you email already waiting.', (int) $open['id']);
        }

        $hold = $this->addressDoubt($c);
        $lead = $this->leadFor($candidateId, $roleId, $userId);
        [$subject, $html, $text] = $this->render($c, [$roleId], $lead);
        $id = (new Mailer($this->app))->enqueue([
            'kind' => 'ack', 'candidate_id' => $candidateId, 'to_email' => $c['email'], 'to_name' => $c['full_name'],
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => ['roles' => [$roleId], 'lead' => $lead],
            'status' => $hold ? 'held' : 'pending', 'hold_reason' => $hold,
            'not_before' => date('Y-m-d H:i:s', time() + self::DELAY_MINUTES * 60), 'created_by' => $userId,
        ]);
        $db->run('INSERT IGNORE INTO acknowledgements (candidate_id, role_id, email_id) VALUES (?, ?, ?)', [$candidateId, $roleId, $id]);
        if ($lead) {
            // The reminders count from when this email goes.
            $db->run('UPDATE questionnaires SET email_id = ? WHERE id = ? OR parent_id = ?', [$id, $lead, $lead]);
        }
        Audit::log('ack_queued', 'candidate', $candidateId, ['email' => $id, 'held' => (bool) $hold, 'details_link' => (bool) $lead]);
        // The consent record (B2): the thank-you email tells them we may keep their CV for other roles, and how to say no.
        (new Consents($this->app))->record($candidateId, 'notice_sent', 'ack_email', $id);
        return $hold ? $this->r('held', "Thank-you email waits for you to check the address: $hold", $id)
                     : $this->r('queued', 'Thank-you email queued.', $id);
    }

    /** Re-renders a held acknowledgement after you correct the address or name. */
    public function rerender(int $emailId): void
    {
        $db = $this->app->db;
        $e = $db->one("SELECT * FROM email_queue WHERE id = ? AND kind = 'ack'", [$emailId]);
        $c = $e ? $db->one('SELECT * FROM candidates WHERE id = ?', [$e['candidate_id']]) : null;
        if ($e && $c) {
            $meta = json_decode((string) $e['meta'], true) ?: [];
            [$subject, $html, $text] = $this->render($c, $meta['roles'] ?? [0], self::leadOf($meta));
            $db->run('UPDATE email_queue SET subject = ?, html = ?, text = ?, to_name = ? WHERE id = ?', [$subject, $html, $text, $c['full_name'], $emailId]);
        }
    }

    /** The details link an acknowledgement carries (older emails kept one per role). */
    private static function leadOf(array $meta): ?int
    {
        if (!empty($meta['lead'])) {
            return (int) $meta['lead'];
        }
        $old = array_values($meta['questionnaires'] ?? []);
        return $old ? (int) $old[0] : null;
    }

    /**
     * The "Complete your application" link for this thank-you email. Re-uses the person's open
     * link if they have one. Null when the setting is off, this role's link was already answered,
     * or (with no role) they told us everything in the last 30 days.
     */
    private function leadFor(int $candidateId, int $roleId, ?int $userId): ?int
    {
        $db = $this->app->db;
        if ($this->app->setting('ack_with_questions', '1') !== '1') {
            return null;
        }
        $svc = new Questionnaires($this->app);
        $open = $svc->openLead($candidateId);
        $appId = $roleId ? (int) $db->col('SELECT a.id FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND a.role_id = ? AND r.is_pool = 0', [$candidateId, $roleId]) : 0;
        if ($appId) {
            if ($db->col("SELECT 1 FROM questionnaires WHERE application_id = ? AND status = 'answered'", [$appId])) {
                return null;
            }
            if ($open) {
                $svc->attach((int) $open['id'], $appId);
                return (int) $open['id'];
            }
            return $svc->create($appId, $userId);
        }
        if ($open) {
            return (int) $open['id'];
        }
        $c = $db->one('SELECT last_confirmed_at FROM candidates WHERE id = ?', [$candidateId]);
        if ($c && $c['last_confirmed_at'] && strtotime($c['last_confirmed_at']) > time() - 30 * 86400) {
            return null;
        }
        return $svc->create(null, $userId, $candidateId);
    }

    private function attachRole(int $lead, int $candidateId, int $roleId): void
    {
        $db = $this->app->db;
        $appId = (int) $db->col('SELECT a.id FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.candidate_id = ? AND a.role_id = ? AND r.is_pool = 0', [$candidateId, $roleId]);
        if ($appId && !$db->col("SELECT 1 FROM questionnaires WHERE application_id = ? AND status = 'answered'", [$appId])) {
            (new Questionnaires($this->app))->attach($lead, $appId);
        }
    }

    /** Why the address might be wrong, or null when it looks right. */
    public function addressDoubt(array $c): ?string
    {
        $email = mb_strtolower((string) $c['email']);
        if (in_array($email, $this->ownAddresses(), true)) {
            return 'this is one of your own addresses, not the candidate\'s.';
        }
        // A CV with two email addresses is not held any more (Benjamin, 13 Nov 2026): the email goes to the
        // one on record (the first the CV gives), without asking.
        if (!Mailer::deliverable($email, $this->dns)) {
            return 'the address doesn\'t look able to receive email (check for a typo).';
        }
        return null;
    }

    /** Your own addresses (users, the sending address, and the forwarding accounts listed in Settings). */
    private function ownAddresses(): array
    {
        if ($this->own === null) {
            $listed = preg_split('/[\s,;]+/', mb_strtolower((string) $this->app->setting('mail_own_addresses', '')), -1, PREG_SPLIT_NO_EMPTY);
            $users = array_map('mb_strtolower', array_column($this->app->db->all('SELECT email FROM users'), 'email'));
            $this->own = array_values(array_unique(array_merge($listed, $users, [mb_strtolower((string) $this->app->setting('mail_from_email', 'resume@eshcolglobal.com'))])));
        }
        return $this->own;
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    public function render(array $c, array $roleIds, ?int $lead = null): array
    {
        $roles = [];
        foreach (array_filter($roleIds) as $rid) {
            $r = $this->app->db->one('SELECT r.*, cl.name AS client_name FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ? AND r.is_pool = 0', [$rid]);
            if ($r) {
                $roles[] = $r;
            }
        }
        $first = Questionnaires::firstName($c);
        $link = null;
        if ($lead && $this->app->db->col("SELECT 1 FROM questionnaires WHERE id = ? AND status IN ('sent','opened')", [$lead])) {
            $link = (new Questionnaires($this->app))->link($lead);
        }
        // Trainee Technicians hiring: its own Gen Z email (lilac, LinkedIn side quest), 22 Nov 2026.
        if ($this->app->setting('ack_trainee_style', '1') === '1' && TraineeTechAck::applies($roles)) {
            return TraineeTechAck::render($first, $link, Engagement::forRoles($roles));
        }
        $st = Questionnaires::subjectTitle($roles);
        // The job they asked for on the website, when it isn't a position here (17 Nov 2026): named, not "your CV".
        $applied = !$roles ? trim((string) ($c['applied_for'] ?? '')) : '';
        if ($applied !== '') {
            $st = $applied;
        }
        if ($link) {
            $subject = $st ? "Next step for your $st application" : ($roles ? 'Next step for your application' : "We've received your CV: one step left");
        } else {
            $subject = $st ? "We've received your application for $st" : ($roles ? "We've received your application for our roles" : "We've received your CV");
        }
        $phraseH = $applied !== '' ? 'the <b style="color:inherit;">' . CandidateEmail::h($applied) . '</b> position' : Questionnaires::rolePhrase($roles, true);
        $phraseT = $applied !== '' ? "the $applied position" : Questionnaires::rolePhrase($roles, false);
        $type = Engagement::forRoles($roles); // wording by type of position (15 Nov 2026)
        $critical = 'These details are critical for evaluating your application, and we can only put forward profiles that are complete.';
        $privacy = CandidateEmail::LINKS['privacy'];
        $p = [
            'title' => $subject,
            'preheader' => $link ? 'Thank you for applying. One step left: about two minutes.' : 'Thank you for applying. Here is what happens next.',
            'eyebrow' => $roles || $applied !== '' ? 'Application received' : 'CV received',
            'headline' => $first !== '' ? "Thank you, $first." : 'Thank you.',
            'sub_html' => 'Your application for ' . $phraseH . ' has reached us safely' . ($link ? ', and we have read your CV.' : '.'),
            'sub_text' => "Your application for $phraseT has reached us safely" . ($link ? ', and we have read your CV.' : '.'),
            'steps' => (bool) $link,
            'after' => [[
                'Because we receive many applications, we may not be able to write to everyone individually after this. Whichever way it goes, we hope the right opportunity finds you soon.',
                'Because we receive many applications, we may not be able to write to everyone individually after this. Whichever way it goes, we hope the right opportunity finds you soon.',
            ]],
            'cards' => true,
            'about' => [
                'If this role turns out not to be the right fit, we may consider you for other suitable roles ' . Engagement::otherRoles($type) . ' over the next five years, as our <a href="' . $privacy . '" target="_blank" style="color:#7946B2;">privacy policy</a> explains. If you would rather we didn\'t, just reply to this email with "Please remove me" and we will delete your details.',
                "If this role turns out not to be the right fit, we may consider you for other suitable roles " . Engagement::otherRoles($type) . " over the next five years, as our privacy policy explains:\n$privacy\nIf you would rather we didn't, just reply to this email with \"Please remove me\" and we will delete your details.",
            ],
        ];
        if ($link) {
            $p['action'] = [
                'kicker' => 'One step to complete your application · about 2 minutes',
                'paras' => [[
                    "Before we can match your CV with " . Engagement::requirements($type) . ", we need a few details that CVs rarely include. <strong style=\"color:#320A67;\">$critical</strong>",
                    "Before we can match your CV with " . Engagement::requirements($type) . ", we need a few details that CVs rarely include. $critical",
                ]],
                'list' => ['Your current salary and the lowest salary you would accept', 'How soon you could join', 'A quick check that the details we read from your CV are correct'],
                'buttons' => [['Complete my application', $link]],
                'small' => 'Works on your phone, with nothing to sign up for. The link is personal to you.',
            ];
        } else {
            array_unshift($p['after'], [
                'Our team reviews every application against ' . Engagement::askedFor($type) . '. If your experience is a good match, we will contact you to talk about next steps.',
                'Our team reviews every application against ' . Engagement::askedFor($type) . '. If your experience is a good match, we will contact you to talk about next steps.',
            ]);
        }
        $p += ['footer_reason' => Engagement::footerReason($type), 'requirements' => Engagement::requirements($type)];
        [$html, $text] = CandidateEmail::render($p);
        return [$subject, $html, $text];
    }

    private function r(string $status, string $message, ?int $id = null): array
    {
        return array_filter(['status' => $status, 'message' => $message, 'email_id' => $id], fn ($v) => $v !== null);
    }
}
