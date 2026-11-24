<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Candidates deleting their own details (24 Nov 2026, Benjamin: "Yes, build it").
 *
 * From the "Remove my details" link, after one confirm step:
 *  - not in a live process (only Applied, Screening, Not suitable, Future requirement, the talent pool...): deleted at
 *    once, as you would delete them ("they asked to be removed": CV files, details, notes and scores go; only the
 *    one-way marker stays, so a later CV from them is flagged). A short "your details have been deleted" email goes
 *    first, from a copy of the address that is wiped once it is sent.
 *  - shortlisted or further (sent to a client, an interview, an offer, placed or joining): kept for now. All contact
 *    stops, consent is recorded as withdrawn, and you are asked to close it within 30 days.
 *
 * You can also delete the ticked people on the removal list (owner only), with the same check.
 */
final class SelfRemoval
{
    public const CLOSE_WITHIN_DAYS = 30;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Why this person can't simply be deleted now: a list of plain-English reasons, empty when nothing is in progress.
     * @return list<string>
     */
    public function liveProcess(int $candidateId): array
    {
        $db = $this->app->db;
        $why = [];
        // An active stage from Shortlisted onwards, or Joined.
        $from = (int) ($db->col("SELECT sort FROM stages WHERE name = 'Shortlisted' LIMIT 1") ?: 30);
        foreach ($db->all("SELECT r.title, s.name FROM applications a JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = a.role_id
                           WHERE a.candidate_id = ? AND ((s.kind = 'active' AND s.sort >= ?) OR s.kind = 'success') ORDER BY a.id", [$candidateId, $from]) as $r) {
            $why[] = "{$r['name']} for {$r['title']}";
        }
        // Their profile is with a client on a position still in progress (whatever the stage says).
        $n = (int) $db->col("SELECT COUNT(*) FROM shortlist_items i JOIN applications a ON a.id = i.application_id JOIN stages s ON s.id = a.stage_id
                             WHERE a.candidate_id = ? AND s.kind IN ('active','success') AND (i.decision IS NULL OR i.decision <> 'reject')", [$candidateId]);
        if ($n) {
            $why[] = 'Profile sent to a client';
        }
        $n = (int) $db->col("SELECT COUNT(*) FROM interviews v JOIN applications a ON a.id = v.application_id WHERE a.candidate_id = ? AND v.status IN ('choosing','booked')", [$candidateId]);
        if ($n) {
            $why[] = 'An interview is being arranged or booked';
        }
        if ($db->col("SELECT 1 FROM placements WHERE candidate_id = ? AND status IN ('offer','joined') LIMIT 1", [$candidateId])) {
            $why[] = 'An offer or placement is recorded';
        }
        if ($db->col("SELECT 1 FROM joinings WHERE candidate_id = ? AND status <> 'withdrawn' LIMIT 1", [$candidateId])) {
            $why[] = 'A joining is being prepared';
        }
        return array_values(array_unique($why));
    }

    /**
     * Their confirmed request from the link. Returns 'deleted', 'held' (in a live process: kept, all contact stopped),
     * or 'already' (a second press of the same button).
     */
    public function fromLink(array $request, string $token): string
    {
        $db = $this->app->db;
        $cid = (int) $request['candidate_id'];
        // Claim the request, so two presses can't both act (or send two emails).
        $claimed = $db->run("UPDATE cv_requests SET status = 'answered', answer = 'remove', answered_at = NOW(), next_at = NULL WHERE id = ? AND (answer IS NULL OR answer <> 'remove')",
            [$request['id']])->rowCount();
        if ($claimed !== 1) {
            return 'already';
        }
        $live = $this->liveProcess($cid);
        if ($live) {
            // As before: flagged, nothing more sent, consent withdrawn. You close it within 30 days.
            (new CvRequests($this->app))->answer($request, 'remove');
            $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $cid,
                'body' => 'Asked to delete their details through the "Remove my details" link on ' . date('j M Y') . '. Not deleted yet because they are in a live process (' . implode('; ', $live)
                    . '). All contact has stopped. Tell the client they have withdrawn, then delete them within ' . self::CLOSE_WITHIN_DAYS . ' days.']);
            Audit::log('candidate_removal_held', 'candidate', $cid, ['request' => (int) $request['id']]);
            return 'held';
        }
        $this->confirmEmail($cid);
        $this->rememberLink($token);
        (new Retention($this->app))->delete($cid, 'requested', true);
        Audit::log('candidate_self_deleted', 'candidate', $cid, ['request' => (int) $request['id']]);
        return 'deleted';
    }

    /**
     * Deletes the ticked people on the removal list (you, owner only). Those in a live process are skipped.
     * @param list<int> $ids
     * @return array{deleted:int, skipped:list<array{id:int,name:string,why:list<string>}>}
     */
    public function bulk(array $ids, int $userId): array
    {
        $db = $this->app->db;
        $deleted = 0;
        $skipped = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 200) as $cid) {
            $c = $db->one('SELECT id, full_name, removal_requested_at FROM candidates WHERE id = ?', [$cid]);
            if (!$c || !$c['removal_requested_at']) {
                continue; // only people who asked: this is not a general delete
            }
            if ($why = $this->liveProcess($cid)) {
                $skipped[] = ['id' => $cid, 'name' => (string) ($c['full_name'] ?: ref_candidate($cid)), 'why' => $why];
                continue;
            }
            (new Retention($this->app))->delete($cid, 'requested', true);
            $deleted++;
        }
        Audit::log('removals_bulk_deleted', 'user', $userId, ['deleted' => $deleted, 'skipped' => count($skipped)]);
        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /** People who asked to be removed but are held because of a live process, oldest first, with the date to close by. */
    public function heldList(): array
    {
        $out = [];
        foreach ($this->app->db->all('SELECT id, full_name, removal_requested_at FROM candidates WHERE removal_requested_at IS NOT NULL ORDER BY removal_requested_at, id LIMIT 500') as $c) {
            if ($this->liveProcess((int) $c['id'])) {
                $c['close_by'] = date('Y-m-d', strtotime((string) $c['removal_requested_at']) + self::CLOSE_WITHIN_DAYS * 86400);
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Was this link used to delete someone's details? */
    public function linkUsed(string $token): ?string
    {
        if (!$this->hasTable()) {
            return null;
        }
        $v = $this->app->db->col('SELECT removed_at FROM removed_links WHERE token_hash = ?', [hash('sha256', $token)]);
        return $v ? (string) $v : null;
    }

    private function rememberLink(string $token): void
    {
        if ($this->hasTable()) {
            $this->app->db->run('INSERT IGNORE INTO removed_links (token_hash) VALUES (?)', [hash('sha256', $token)]);
        }
    }

    private function hasTable(): bool
    {
        return !in_array('077_self_removal', (new Migrator($this->app))->pending(), true);
    }

    /** "Your details have been deleted": queued without a candidate link (the record is about to go), address wiped once sent. */
    private function confirmEmail(int $candidateId): void
    {
        $c = $this->app->db->one('SELECT email, first_name, full_name FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c || !filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        [$subject, $html, $text] = self::email(Questionnaires::firstName($c));
        (new Mailer($this->app))->enqueue(['kind' => 'removal_done', 'to_email' => (string) $c['email'], 'to_name' => null,
            'subject' => $subject, 'html' => $html, 'text' => $text, 'priority' => 5, 'meta' => ['forget' => true]]);
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    public static function email(string $first): array
    {
        $hello = $first !== '' ? "Dear $first," : 'Hello,';
        [$html, $text] = CandidateEmail::render([
            'title' => 'Your details have been deleted',
            'preheader' => 'As you asked, we have deleted your details and will not contact you again.',
            'eyebrow' => 'Your details',
            'headline' => 'Your details have been deleted',
            'sub_html' => CandidateEmail::h($hello) . ' as you asked, we have deleted your CV and the details we held about you, and we will not contact you again.',
            'sub_text' => "$hello as you asked, we have deleted your CV and the details we held about you, and we will not contact you again.",
            'after' => [
                ['We keep only a one-way code made from your email address and phone number, which cannot be turned back into them. It lets us respect your request if your CV reaches us again.',
                    'We keep only a one-way code made from your email address and phone number, which cannot be turned back into them. It lets us respect your request if your CV reaches us again.'],
                ['If you apply for a role with us in future, we will start afresh. Thank you for your interest in Eshcol Global.',
                    'If you apply for a role with us in future, we will start afresh. Thank you for your interest in Eshcol Global.'],
            ],
            'signature' => ['lines' => ['Director', 'Eshcol Global Services (P) Ltd']],
            'footer_reason' => 'You are receiving this email because you asked us to delete your details.',
        ]);
        return ['Your details have been deleted', $html, $text];
    }
}
