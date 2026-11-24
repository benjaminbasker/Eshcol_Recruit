<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * Interviews, the way Eshcol runs them (proposal of 6 Nov 2026, §1.5):
 *  1. The client offers time slots for a round (on their shortlist page, or you type in what
 *     they told you). Each slot can be taken by one candidate.
 *  2. The candidate gets a link (email + WhatsApp), picks a slot, and it's booked. Or you book
 *     a time you agreed on the phone.
 *  3. The call letter goes out in Benjamin's format for the round type: virtual (meeting link,
 *     preparation tips, JD) or in person (address, map link, company profile and JD), with a
 *     calendar invite. The client gets a confirmation too.
 *  4. A reminder 4 hours before. After the interview you record the outcome.
 * Usually round 1 is virtual and round 2 in person, so those are the defaults.
 */
final class Interviews
{
    public const KINDS = ['virtual' => 'Virtual', 'in_person' => 'In person', 'phone' => 'Phone'];
    public const OUTCOMES = ['next_round' => 'Through to the next round', 'selected' => 'Selected', 'not_selected' => 'Not selected', 'on_hold' => 'On hold', 'no_show' => 'Did not turn up'];

    /** A booked interview at the same client (any position) overlapping the slot "s" (roles "r"). */
    private const CLASH_SQL = "SELECT 1 FROM interviews i2 JOIN applications a2 ON a2.id = i2.application_id JOIN roles r2 ON r2.id = a2.role_id
                WHERE r2.client_id = r.client_id AND i2.status = 'booked' AND i2.starts_at IS NOT NULL
                  AND i2.starts_at < s.starts_at + INTERVAL s.duration_min MINUTE AND i2.starts_at + INTERVAL i2.duration_min MINUTE > s.starts_at";

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Who is already booked with this client at an overlapping time, on any of its positions (17 Nov 2026).
     * Null when the time is free. $exceptInterview: the interview being booked or moved.
     */
    public function clashAt(int $clientId, string $startsAt, int $minutes, int $exceptInterview = 0): ?array
    {
        return $this->app->db->one("SELECT i.id, i.starts_at, c.full_name, r.title FROM interviews i JOIN applications a ON a.id = i.application_id
            JOIN roles r ON r.id = a.role_id JOIN candidates c ON c.id = a.candidate_id
            WHERE r.client_id = ? AND i.status = 'booked' AND i.starts_at IS NOT NULL AND i.id <> ?
              AND i.starts_at < ? + INTERVAL ? MINUTE AND i.starts_at + INTERVAL i.duration_min MINUTE > ? LIMIT 1",
            [$clientId, $exceptInterview, $startsAt, $minutes, $startsAt]) ?: null;
    }

    // ---------------------------------------------------------------- slots

    /**
     * Adds time slots for a role's round. $times: 'Y-m-d H:i' strings (local time).
     * @return int how many were added (past and duplicate times are skipped)
     */
    public function addSlots(int $roleId, int $round, string $kind, array $times, int $duration, string $source, ?int $shortlistId = null): int
    {
        $db = $this->app->db;
        // One set of times per client (Benjamin, 17 Nov 2026): a time the client gives is offered on all its open
        // positions (one interviewer); a time taken by one candidate is then gone for all (clashAt / openSlots).
        $clientId = (int) $db->col('SELECT client_id FROM roles WHERE id = ?', [$roleId]);
        $roleIds = array_values(array_unique(array_merge([$roleId], array_map('intval', array_column($db->all(
            "SELECT id FROM roles WHERE client_id = ? AND is_pool = 0 AND status IN ('open','on_hold') ORDER BY id", [$clientId]), 'id')))));
        $n = 0;
        foreach ($times as $t) {
            $ts = strtotime(str_replace('T', ' ', trim((string) $t)));
            if (!$ts || $ts < time() + 1800) {
                continue;
            }
            $at = date('Y-m-d H:i:00', $ts);
            $added = false;
            foreach ($roleIds as $rid) {
                if ($db->col('SELECT 1 FROM interview_slots WHERE role_id = ? AND round = ? AND starts_at = ?', [$rid, $round, $at])) {
                    continue;
                }
                $db->insert('interview_slots', ['role_id' => $rid, 'round' => max(1, min(9, $round)), 'kind' => isset(self::KINDS[$kind]) ? $kind : 'virtual',
                    'starts_at' => $at, 'duration_min' => max(15, min(240, $duration)), 'source' => $source === 'client' ? 'client' : 'you', 'shortlist_id' => $rid === $roleId ? $shortlistId : null]);
                $added = true;
            }
            $n += $added ? 1 : 0;
        }
        return $n;
    }

    /** Free future slots for a role's round. */
    public function openSlots(int $roleId, int $round): array
    {
        // A time already booked for another of this client's positions is gone too (17 Nov 2026: AdvantEdge gave the same
        // times on Associate and Manager, and two candidates were booked for 18 Nov 4 pm with the same interviewer).
        return $this->app->db->all('SELECT s.* FROM interview_slots s JOIN roles r ON r.id = s.role_id
            WHERE s.role_id = ? AND s.round = ? AND s.interview_id IS NULL AND s.starts_at > NOW() + INTERVAL 30 MINUTE
              AND NOT EXISTS (' . self::CLASH_SQL . ')
            ORDER BY s.starts_at', [$roleId, $round]);
    }

    // ---------------------------------------------------------------- the calendar (17 Nov 2026)

    /**
     * One week of interviews for the calendar: 'booked' (with candidate, position, client, link), 'open' (a client's free
     * times, one per client and time, with the positions that offer it) and 'choosing' (invited, no time picked yet).
     * $clientId 0 = all clients. Contractors see only their own positions.
     */
    public function week(string $monday, int $clientId, ?array $user): array
    {
        $db = $this->app->db;
        $from = date('Y-m-d 00:00:00', strtotime($monday));
        $to = date('Y-m-d 00:00:00', strtotime($monday . ' +7 days'));
        $mine = \Eshcol\Ats\Access::roleFilter($user ?? [], 'r.id');
        $cl = $clientId ? ' AND r.client_id = ' . $clientId : '';
        $booked = $db->all("SELECT i.*, a.candidate_id, c.full_name, r.id AS role_id, r.title, r.client_id, cl.name AS client
            FROM interviews i JOIN applications a ON a.id = i.application_id JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id
            JOIN candidates c ON c.id = a.candidate_id
            WHERE i.status IN ('booked','done') AND i.starts_at >= ? AND i.starts_at < ? AND $mine $cl ORDER BY i.starts_at", [$from, $to]);
        $open = [];
        foreach ($db->all("SELECT s.*, r.title, r.client_id, cl.name AS client FROM interview_slots s JOIN roles r ON r.id = s.role_id JOIN clients cl ON cl.id = r.client_id
                WHERE s.interview_id IS NULL AND s.starts_at >= ? AND s.starts_at < ? AND s.starts_at > NOW() AND r.status IN ('open','on_hold') AND $mine $cl
                  AND NOT EXISTS (" . self::CLASH_SQL . ") ORDER BY s.starts_at, r.id", [$from, $to]) as $s) {
            $k = $s['client_id'] . '|' . $s['starts_at'] . '|' . $s['round'];
            $open[$k] ??= ['slot_id' => (int) $s['id'], 'starts_at' => $s['starts_at'], 'duration_min' => (int) $s['duration_min'], 'kind' => $s['kind'],
                'round' => (int) $s['round'], 'client_id' => (int) $s['client_id'], 'client' => $s['client'], 'positions' => [], 'slot_ids' => []];
            $open[$k]['positions'][(int) $s['role_id']] = $s['title'];
            $open[$k]['slot_ids'][] = (int) $s['id'];
        }
        $choosing = $db->all("SELECT i.*, a.candidate_id, c.full_name, r.title, r.client_id, cl.name AS client FROM interviews i JOIN applications a ON a.id = i.application_id
            JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id JOIN candidates c ON c.id = a.candidate_id
            WHERE i.status = 'choosing' AND $mine $cl ORDER BY i.id");
        return ['booked' => $booked, 'open' => array_values($open), 'choosing' => $choosing];
    }

    /**
     * Who can be given a client's open time: candidates on its open positions at Shortlisted or later (Submitted to client,
     * Client interview), still in play, with no interview booked from now on. Grouped by position.
     */
    public function allottable(int $clientId, ?array $user): array
    {
        $db = $this->app->db;
        $mine = \Eshcol\Ats\Access::roleFilter($user ?? [], 'r.id');
        $out = [];
        foreach ($db->all("SELECT a.id, a.stage_id, a.role_id, c.full_name, r.title, s.name AS stage FROM applications a JOIN roles r ON r.id = a.role_id
                JOIN candidates c ON c.id = a.candidate_id JOIN stages s ON s.id = a.stage_id
                WHERE r.client_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND s.kind = 'active' AND $mine
                  AND NOT EXISTS (SELECT 1 FROM interviews i WHERE i.application_id = a.id AND i.status = 'booked' AND i.starts_at >= NOW())
                ORDER BY r.title, c.full_name", [$clientId]) as $a) {
            if (Shortlists::isShortlisted($this->app, (int) $a['stage_id'])) {
                $out[$a['title']][] = $a;
            }
        }
        return $out;
    }

    /**
     * Gives a client's open time to a candidate (from the calendar): books it as a direct booking would (call letter to
     * the candidate, confirmation to the client), and marks that position's slot as taken. Returns [ok, message].
     */
    public function allot(int $slotId, int $applicationId, int $userId): array
    {
        $db = $this->app->db;
        $slot = $db->one('SELECT s.*, r.client_id FROM interview_slots s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$slotId]);
        $a = $this->application($applicationId);
        if (!$slot || !$a || (int) $a['client_id'] !== (int) $slot['client_id']) {
            return [false, 'That time or candidate was not found for this client.'];
        }
        if ($slot['interview_id']) {
            return [false, 'Sorry, that time has just been taken.'];
        }
        $c = $db->one('SELECT meeting_link, interview_address, maps_url FROM clients WHERE id = ?', [$a['client_id']]) ?: [];
        [$ok, $msg, $id] = $this->book($applicationId, max(1, $this->nextRound($applicationId)), (string) $slot['kind'], (string) $slot['starts_at'], (int) $slot['duration_min'],
            ['meeting_link' => $c['meeting_link'] ?? '', 'address' => $c['interview_address'] ?? '', 'maps_url' => $c['maps_url'] ?? ''], $userId);
        if ($ok) {
            // The slot on the candidate's own position if there is one at that time, else the one clicked.
            $own = (int) $db->col('SELECT id FROM interview_slots WHERE role_id = ? AND starts_at = ? AND interview_id IS NULL LIMIT 1', [$a['role_id'], $slot['starts_at']]);
            $db->run('UPDATE interview_slots SET interview_id = ? WHERE id = ?', [$id, $own ?: $slotId]);
            Audit::log('interview_allotted', 'candidate', (int) $a['candidate_id'], ['interview' => $id, 'slot' => $own ?: $slotId]);
        }
        return [$ok, $msg];
    }

    /** Removes a client's free time from every position that offers it (the calendar's ×). Booked times stay. */
    public function removeClientTime(int $slotId): int
    {
        $db = $this->app->db;
        $s = $db->one('SELECT s.*, r.client_id FROM interview_slots s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$slotId]);
        if (!$s) {
            return 0;
        }
        return $db->run('DELETE s FROM interview_slots s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ? AND s.starts_at = ? AND s.round = ? AND s.interview_id IS NULL',
            [$s['client_id'], $s['starts_at'], $s['round']])->rowCount();
    }

    /**
     * The days and times ticked on the time picker (23 Nov 2026, Benjamin: "make the fix-time interface easier"):
     * every ticked day (date[]) at every ticked time (clock[]), plus one typed day and time. "Y-m-d H:i" strings,
     * in order, no repeats; addSlots() still drops past ones. Pure: tested directly.
     */
    public static function pickedTimes(array $post): array
    {
        $isDate = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
        $isClock = fn ($t) => is_string($t) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($t));
        $days = array_values(array_filter((array) ($post['date'] ?? []), $isDate));
        $clocks = array_values(array_filter((array) ($post['clock'] ?? []), $isClock));
        $other = trim((string) ($post['other_date'] ?? ''));
        if ($isDate($other)) {
            $days[] = $other;
        }
        $otherClock = trim((string) ($post['other_time'] ?? ''));
        if ($isClock($otherClock)) {
            $clocks[] = $otherClock;
        }
        $out = [];
        foreach (array_unique($days) as $d) {
            foreach (array_unique($clocks) as $t) {
                $out[] = $d . ' ' . date('H:i', strtotime("$d $t"));
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Corrects the round of a booked or invited interview without emailing anyone (23 Nov 2026: Mangesh's round 1 was
     * moved to the round 2 date with "Change the time", so it kept saying Round 1). Raising it records the earlier
     * rounds as held, "Through to the next round", so the stage and the next round add up. Returns [ok, message].
     */
    public function correctRound(int $id, int $round, int $userId): array
    {
        $db = $this->app->db;
        $iv = $db->one("SELECT * FROM interviews WHERE id = ? AND status IN ('booked','choosing')", [$id]);
        if (!$iv) {
            return [false, 'Only an interview that is booked or waiting for a time can be corrected.'];
        }
        $round = max(1, min(9, $round));
        $old = (int) $iv['round'];
        if ($round === $old) {
            return [true, "It is already round $round."];
        }
        if ($db->col("SELECT 1 FROM interviews WHERE application_id = ? AND round = ? AND id <> ? AND status IN ('booked','choosing')", [$iv['application_id'], $round, $id])) {
            return [false, "There is already a round $round interview arranged for this position."];
        }
        $db->update('interviews', ['round' => $round], $id);
        for ($r = 1; $r < $round; $r++) {
            if (!$db->col("SELECT 1 FROM interviews WHERE application_id = ? AND round = ? AND status = 'done'", [$iv['application_id'], $r])) {
                $db->insert('interviews', ['application_id' => $iv['application_id'], 'round' => $r, 'kind' => $iv['kind'], 'status' => 'done',
                    'outcome' => 'next_round', 'duration_min' => $iv['duration_min'], 'created_by' => $userId ?: null]);
            }
        }
        $a = $db->one('SELECT candidate_id FROM applications WHERE id = ?', [$iv['application_id']]);
        $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => $userId ?: null,
            'body' => ref_interview($id) . " corrected from round $old to round $round. Nobody was emailed."]);
        Audit::log('interview_round_corrected', 'candidate', (int) $a['candidate_id'], ['interview' => $id, 'from' => $old, 'to' => $round]);
        return [true, "Now round $round. Nobody was emailed."];
    }

    /**
     * The next round for an application: one more than the last round that actually took place (has a result). A round
     * that is booked but hasn't happened yet is not "done" (20 Nov 2026: Deepa Rani's round 1, moved to the next day,
     * went out as round 2 because a booked round 1 counted as finished).
     */
    public function nextRound(int $applicationId): int
    {
        return 1 + (int) $this->app->db->col("SELECT COALESCE(MAX(round), 0) FROM interviews WHERE application_id = ? AND status = 'done'", [$applicationId]);
    }

    /**
     * "Client interview · Round N" picked in the stage drop-down (Benjamin, 20 Nov 2026: "make moving to round 2 easy").
     * Earlier rounds still booked are recorded "Through to the next round"; an unanswered invitation to an earlier round
     * is withdrawn; the stage becomes Client interview. Then, if the client has given times for round N, the candidate is
     * invited to choose one. Returns [ok, message, book]: book = true when a time still needs booking (no times yet).
     */
    public function moveToRound(int $applicationId, int $round, int $userId, bool $byOwner = false): array
    {
        $db = $this->app->db;
        $round = max(1, min(9, $round));
        $a = $this->application($applicationId);
        if (!$a) {
            return [false, 'Application not found.', false];
        }
        foreach ($db->all("SELECT * FROM interviews WHERE application_id = ? AND round < ? AND status IN ('booked','choosing')", [$applicationId, $round]) as $iv) {
            if ($iv['status'] === 'booked') {
                $this->outcome((int) $iv['id'], 'next_round', '', $userId, $byOwner);
            } else {
                $this->cancel((int) $iv['id'], $userId);
            }
        }
        $sid = Stages::id($this->app, Stages::INTERVIEW);
        $cur = $db->one('SELECT * FROM applications WHERE id = ?', [$applicationId]);
        if ($sid && (int) $cur['stage_id'] !== $sid) {
            (new StageMover($this->app))->move($cur, $sid, "Round $round", $userId, $byOwner);
        }
        if ($db->col("SELECT 1 FROM interviews WHERE application_id = ? AND round = ? AND status IN ('choosing','booked')", [$applicationId, $round])) {
            return [true, "{$a['full_name']} is at round $round; that round is already arranged.", false];
        }
        if ($this->openSlots((int) $a['role_id'], $round) && $a['email'] && !Contact::silenced($a) && !$a['unreliable_at']) {
            [$ok, $msg] = $this->invite($applicationId, $round, $userId);
            return [$ok, "Moved to round $round. " . $msg, !$ok];
        }
        Audit::log('interview_round', 'candidate', (int) $a['candidate_id'], ['round' => $round]);
        return [true, "Moved to round $round. There are no round $round times from the client yet: book a time below, or add the client's times on the position.", true];
    }

    /** The booked (not yet held) interview for this application's round, if any. */
    public function bookedFor(int $applicationId, int $round): ?array
    {
        return $this->app->db->one("SELECT * FROM interviews WHERE application_id = ? AND round = ? AND status = 'booked' ORDER BY id DESC LIMIT 1", [$applicationId, $round]) ?: null;
    }

    /**
     * Who is ready for a round of a position, for the one-click invite (20 Nov 2026): round 1 is everyone the client
     * marked "Shortlist"; a later round is everyone whose previous round ended "Through to the next round". Leaves out
     * anyone already invited to, booked for or through that round, and anyone we can't contact.
     * @return list<array{application_id:int, candidate_id:int, full_name:string}>
     */
    public function readyFor(int $roleId, int $round): array
    {
        $db = $this->app->db;
        $rows = $round <= 1
            ? $db->all("SELECT DISTINCT a.id AS application_id, a.candidate_id, c.full_name FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id
                    JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id JOIN stages st ON st.id = a.stage_id
                    WHERE s.role_id = ? AND i.decision = 'shortlist' AND st.kind = 'active'", [$roleId])
            : $db->all("SELECT DISTINCT a.id AS application_id, a.candidate_id, c.full_name FROM interviews v JOIN applications a ON a.id = v.application_id
                    JOIN candidates c ON c.id = a.candidate_id JOIN stages st ON st.id = a.stage_id
                    WHERE a.role_id = ? AND v.round = ? AND v.status = 'done' AND v.outcome = 'next_round' AND st.kind = 'active'", [$roleId, $round - 1]);
        $out = [];
        foreach ($rows as $r) {
            $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$r['candidate_id']]);
            if (!$c || !$c['email'] || !Contact::canPutForward($c)) {
                continue;
            }
            if ($db->col("SELECT 1 FROM interviews WHERE application_id = ? AND round = ? AND status IN ('choosing','booked','done')", [$r['application_id'], $round])) {
                continue;
            }
            $out[] = ['application_id' => (int) $r['application_id'], 'candidate_id' => (int) $r['candidate_id'], 'full_name' => (string) $r['full_name']];
        }
        usort($out, fn ($x, $y) => strcasecmp($x['full_name'], $y['full_name']));
        return $out;
    }

    // ---------------------------------------------------------------- inviting and booking

    /** Sends the candidate a link to pick one of the open slots. Returns [ok, message]. */
    public function invite(int $applicationId, int $round, int $userId): array
    {
        $db = $this->app->db;
        $a = $this->application($applicationId);
        if (!$a || !$a['email'] || Contact::silenced($a)) {
            return [false, 'This candidate has no email address, or is marked do not contact.'];
        }
        if ($a['unreliable_at']) {
            return [false, 'This candidate is marked unreliable. Clear the mark on their page first if you want to give them another chance.'];
        }
        $slots = $this->openSlots((int) $a['role_id'], $round);
        if (!$slots) {
            return [false, "There are no open time slots for round $round yet. Add the times the client gave you first."];
        }
        $db->run("UPDATE interviews SET status = 'cancelled' WHERE application_id = ? AND round = ? AND status = 'choosing'", [$applicationId, $round]);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $c = $db->one('SELECT * FROM clients WHERE id = ?', [$a['client_id']]);
        $kind = $slots[0]['kind'];
        $id = $db->insert('interviews', ['application_id' => $applicationId, 'round' => $round, 'kind' => $kind, 'status' => 'choosing',
            'duration_min' => (int) $slots[0]['duration_min'], 'meeting_link' => $kind === 'virtual' ? $c['meeting_link'] : null,
            'address' => $kind === 'in_person' ? ($c['interview_address'] ?: null) : null, 'maps_url' => $kind === 'in_person' ? ($c['maps_url'] ?: null) : null,
            'token_hash' => hash('sha256', $token), 'token_enc' => Secrets::seal($this->app, $token, 'interview'), 'created_by' => $userId]);
        $link = $this->link($id);
        $first = $this->firstName($a);
        $lines = [];
        foreach ($slots as $s) {
            $lines[] = date('l j F, g:i A', strtotime($s['starts_at']));
        }
        // House design (phase 2, approved 15 Nov 2026).
        $u = $this->sender($userId);
        $emailId = $this->designed('interview', $a, "Choose your interview time: {$a['title']}", [
            'preheader' => $a['client_display'] . ' would like to meet you.',
            'eyebrow' => 'Interview invitation',
            'headline' => $first !== '' ? "Good news, $first." : 'Good news.',
            'sub' => $a['client_display'] . ' would like to interview you for ' . self::roleLine($a) . ' (round ' . $round . ', ' . self::HOW[$kind] . ').',
            'action' => ['kicker' => 'Choose a time that suits you',
                'paras' => [['Times are booked first come, first served, so please choose soon.', 'Times are booked first come, first served, so please choose soon.']],
                'list' => array_slice($lines, 0, 8), 'buttons' => [['Choose my time', $link]],
                'small' => 'None of these work? Open the link and tell us, and we will ask for other times.'],
            'steps' => [['You choose a time', 'From the link above, on your phone or computer.'], ['We confirm it', 'With the details and a calendar invite.'], ['A reminder on the day', 'So nothing is missed.']],
        ], $u, ['interview' => $id], [], 10, $userId);
        $db->run('UPDATE interviews SET invite_email_id = ? WHERE id = ?', [$emailId, $id]);
        Audit::log('interview_invited', 'candidate', (int) $a['candidate_id'], ['round' => $round, 'interview' => $id]);
        return [true, 'Sent: ' . $a['full_name'] . ' can now choose from ' . count($slots) . ' time' . (count($slots) === 1 ? '' : 's') . '.'];
    }

    /** Books a time directly (agreed on the phone) and sends the call letter. Returns [ok, message, id]. */
    public function book(int $applicationId, int $round, string $kind, string $when, int $duration, array $details, int $userId): array
    {
        $db = $this->app->db;
        $a = $this->application($applicationId);
        $ts = strtotime(str_replace('T', ' ', $when));
        if (!$a) {
            return [false, 'Application not found.', 0];
        }
        if (!$ts || $ts < time() - 3600) {
            return [false, 'Choose a date and time in the future.', 0];
        }
        $kind = isset(self::KINDS[$kind]) ? $kind : 'virtual';
        $c = $db->one('SELECT * FROM clients WHERE id = ?', [$a['client_id']]);
        $link = trim((string) ($details['meeting_link'] ?? '')) ?: $c['meeting_link'];
        $address = trim((string) ($details['address'] ?? '')) ?: $c['interview_address'];
        $maps = trim((string) ($details['maps_url'] ?? '')) ?: $c['maps_url'];
        if ($kind === 'virtual' && !$link) {
            return [false, 'Add the meeting link (Google Meet or Teams), or set a default on the client.', 0];
        }
        if ($kind === 'in_person' && !$address) {
            return [false, 'Add the interview address, or set a default on the client.', 0];
        }
        $at = date('Y-m-d H:i:00', $ts);
        // Already booked for this round: that's a change of time, not a new interview (20 Nov 2026, Deepa Rani).
        if ($existing = $this->bookedFor($applicationId, max(1, $round))) {
            [$ok, $msg] = $this->reschedule((int) $existing['id'], $when, $duration, $kind, $details, null, $userId);
            return [$ok, $msg, $ok ? (int) $existing['id'] : 0];
        }
        if ($x = $this->clashAt((int) $a['client_id'], $at, max(15, min(240, $duration)))) {
            return [false, 'That time is already booked with this client: ' . $x['full_name'] . ' (' . $x['title'] . ') at ' . date('j M, g:i A', strtotime($x['starts_at'])) . '. Choose another time.', 0];
        }
        $db->run("UPDATE interviews SET status = 'cancelled' WHERE application_id = ? AND round = ? AND status = 'choosing'", [$applicationId, $round]);
        $id = $db->insert('interviews', ['application_id' => $applicationId, 'round' => max(1, $round), 'kind' => $kind, 'status' => 'booked',
            'starts_at' => date('Y-m-d H:i:00', $ts), 'duration_min' => max(15, min(240, $duration)),
            'meeting_link' => $kind === 'virtual' ? $this->url($link) : null, 'address' => $kind === 'in_person' ? $address : null,
            'maps_url' => $kind === 'in_person' && $maps ? $this->url($maps) : null, 'created_by' => $userId]);
        $this->confirmed($id, $userId);
        $told = $db->col('SELECT client_notified_at FROM interviews WHERE id = ?', [$id]) !== null;
        return [true, 'Booked. The call letter is on its way to ' . $a['full_name'] . ($told ? ', and the client has been told.' : '. The client hears in the 6 pm email with the day\'s bookings.'), $id];
    }

    /** The candidate picks a slot from their link. Returns [ok, message]. */
    public function pick(array $iv, int $slotId): array
    {
        // Audit R2 (16 Nov 2026): one booking at a time per interview, all in one step (the slot, the interview, the emails),
        // so two clicks at once can't take two times, and a failure leaves no time taken.
        $db = $this->app->db;
        $a = $this->application((int) $iv['application_id']);
        return $db->tx(function () use ($db, $iv, $slotId, $a) {
            if ($db->col('SELECT status FROM interviews WHERE id = ? FOR UPDATE', [$iv['id']]) !== 'choosing') {
                return [false, 'This interview is already arranged.'];
            }
            // One booking at a time per client, so two candidates on different positions can't take the same time at once.
            $db->col('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$a['client_id']]);
            $want = $db->one('SELECT * FROM interview_slots WHERE id = ?', [$slotId]);
            if ($want && $this->clashAt((int) $a['client_id'], (string) $want['starts_at'], (int) $want['duration_min'], (int) $iv['id'])) {
                return [false, 'Sorry, someone has just taken that time. Please choose another.'];
            }
            $ok = $db->run("UPDATE interview_slots SET interview_id = ? WHERE id = ? AND role_id = ? AND round = ? AND interview_id IS NULL AND starts_at > NOW()",
                [$iv['id'], $slotId, $a['role_id'], $iv['round']])->rowCount();
            if (!$ok) {
                return [false, 'Sorry, someone has just taken that time. Please choose another.'];
            }
            $slot = $db->one('SELECT * FROM interview_slots WHERE id = ?', [$slotId]);
            // Audit C5: the meeting details follow the kind of time chosen, not the first time offered.
            $c = $db->one('SELECT meeting_link, interview_address, maps_url FROM clients WHERE id = ?', [$a['client_id']]) ?: [];
            $db->run("UPDATE interviews SET status = 'booked', starts_at = ?, duration_min = ?, kind = ?, meeting_link = ?, address = ?, maps_url = ? WHERE id = ?", [
                $slot['starts_at'], $slot['duration_min'], $slot['kind'],
                $slot['kind'] === 'virtual' ? ($iv['meeting_link'] ?: ($c['meeting_link'] ?? null) ?: null) : null,
                $slot['kind'] === 'in_person' ? ($iv['address'] ?: ($c['interview_address'] ?? null) ?: null) : null,
                $slot['kind'] === 'in_person' ? ($iv['maps_url'] ?: ($c['maps_url'] ?? null) ?: null) : null,
                $iv['id']]);
            $this->confirmed((int) $iv['id'], (int) $iv['created_by']);
            return [true, 'Booked.'];
        });
    }

    /**
     * Moves a booked interview to another time, keeping its round (20 Nov 2026: last-minute changes are common). The
     * candidate gets a fresh call letter marked "rescheduled", the client is told straight away (old and new time), and
     * the calendar invite replaces the earlier entry. $round: to correct a wrongly numbered round; null keeps it.
     * Returns [ok, message].
     */
    public function reschedule(int $id, string $when, int $duration, string $kind, array $details, ?int $round, int $userId): array
    {
        $db = $this->app->db;
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        if (!$iv || $iv['status'] !== 'booked') {
            return [false, 'Only a booked interview can be moved. Invite or book a new one instead.'];
        }
        $a = $this->application((int) $iv['application_id']);
        $ts = strtotime(str_replace('T', ' ', $when));
        if (!$a || !$ts || $ts < time() - 3600) {
            return [false, 'Choose a date and time in the future.'];
        }
        $kind = isset(self::KINDS[$kind]) ? $kind : (string) $iv['kind'];
        $c = $db->one('SELECT * FROM clients WHERE id = ?', [$a['client_id']]) ?: [];
        $link = trim((string) ($details['meeting_link'] ?? '')) ?: ((string) $iv['meeting_link'] ?: (string) ($c['meeting_link'] ?? ''));
        $address = trim((string) ($details['address'] ?? '')) ?: ((string) $iv['address'] ?: (string) ($c['interview_address'] ?? ''));
        $maps = trim((string) ($details['maps_url'] ?? '')) ?: ((string) $iv['maps_url'] ?: (string) ($c['maps_url'] ?? ''));
        if ($kind === 'virtual' && $link === '') {
            return [false, 'Add the meeting link (Google Meet or Teams), or set a default on the client.'];
        }
        if ($kind === 'in_person' && $address === '') {
            return [false, 'Add the interview address, or set a default on the client.'];
        }
        $minutes = max(15, min(240, $duration ?: (int) $iv['duration_min']));
        $at = date('Y-m-d H:i:00', $ts);
        if ($x = $this->clashAt((int) $a['client_id'], $at, $minutes, $id)) {
            return [false, 'That time is already booked with this client: ' . $x['full_name'] . ' (' . $x['title'] . ') at ' . date('j M, g:i A', strtotime($x['starts_at'])) . '. Choose another time.'];
        }
        $oldRound = (int) $iv['round'];
        $round = $round !== null ? max(1, min(9, $round)) : $oldRound;
        $oldWhen = date('l j F, g:i A', strtotime((string) $iv['starts_at']));
        $newWhen = date('l j F, g:i A', $ts);
        $now = date('Y-m-d H:i:s');
        $db->run('UPDATE interview_slots SET interview_id = NULL WHERE interview_id = ?', [$id]);
        $db->update('interviews', ['round' => $round, 'kind' => $kind, 'starts_at' => $at, 'duration_min' => $minutes,
            'meeting_link' => $kind === 'virtual' ? $this->url($link) : null, 'address' => $kind === 'in_person' ? $address : null,
            'maps_url' => $kind === 'in_person' && $maps !== '' ? $this->url($maps) : null,
            'reminded_at' => null, 'reschedules' => (int) $iv['reschedules'] + 1, 'rescheduled_at' => $now,
            'letter_sent_at' => $now, 'client_notified_at' => $now], $id);
        // A client time at the new hour is taken by this interview, as a pick would take it.
        $db->run('UPDATE interview_slots SET interview_id = ? WHERE role_id = ? AND round = ? AND starts_at = ? AND interview_id IS NULL LIMIT 1', [$id, $a['role_id'], $round, $at]);
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        $u = $this->sender($userId);
        $first = $this->firstName($a);
        $p = $this->confirmation($iv, $a);
        $p['eyebrow'] = 'Interview rescheduled';
        $p['headline'] = $first !== '' ? "Your interview has moved, $first." : 'Your interview has moved.';
        $p['sub'] = 'Your ' . self::HOW[$kind] . ' interview with ' . $a['client_display'] . ' for ' . self::roleLine($a) . " is now on $newWhen (it was $oldWhen).";
        $p['preheader'] = "New time: $newWhen.";
        $emailId = $this->designed('interview', $a, 'Interview rescheduled: ' . $a['title'] . ' with ' . $a['client_display'] . ', ' . date('j M Y, g:i A', $ts),
            $p, $u, ['interview' => $id, 'rescheduled' => true], $this->attachmentsFor($iv, $a), 10, $userId);
        $db->run('UPDATE interviews SET letter_email_id = ? WHERE id = ?', [$emailId, $id]);
        $told = '';
        $contact = $db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$a['client_id']]);
        if ($contact) {
            $cfirst = trim((string) strtok((string) $contact['name'], ' '));
            $where = $kind === 'virtual' ? "Online: {$iv['meeting_link']}" : ($kind === 'in_person' ? 'In person at: ' . one_line($iv['address']) : 'By phone');
            $fix = $round !== $oldRound ? " (please note this is round $round, not round $oldRound as in our earlier email)" : '';
            $body = ($cfirst !== '' ? "$cfirst," : 'Hello,') . "\n\nPlease note that the round $round interview with {$a['full_name']} (" . ref_candidate($a['candidate_id']) . ") for the {$a['title']} position has been rescheduled$fix:\n\n"
                . "Was: $oldWhen\nNow: " . $this->when($iv) . "\n$where\n\nThe updated calendar invite is attached and replaces the earlier one.";
            $this->queue('client_interview', ['email' => $contact['email'], 'full_name' => $contact['name'], 'candidate_id' => null], "Interview rescheduled: {$a['full_name']}, {$a['title']}, " . date('j M, g:i A', $ts),
                $body, $u, ['interview' => $id, 'rescheduled' => true], [['ics' => $this->ics($iv, $a, true), 'name' => 'Interview.ics']], 12, $userId);
            $told = ' and ' . $contact['name'] . ' at the client';
        }
        $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => $userId ?: null,
            'body' => "Round $round interview (" . ref_interview($id) . ") moved from $oldWhen to $newWhen" . ($round !== $oldRound ? " (round corrected from $oldRound)" : '') . '.']);
        Audit::log('interview_rescheduled', 'candidate', (int) $a['candidate_id'], ['interview' => $id, 'from' => $oldWhen, 'to' => $at, 'round' => $round]);
        return [true, 'Moved to ' . date('D j M, g:i A', $ts) . '. The new call letter is on its way to ' . $a['full_name'] . $told . '.'];
    }

    /**
     * A booked candidate chooses again from the client's open times (e.g. the client sent new times): the booking is
     * cancelled (the client is told), and the candidate gets a link to choose, for the same round. Returns [ok, message].
     */
    public function chooseAgain(int $id, int $userId): array
    {
        $iv = $this->app->db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        if (!$iv || $iv['status'] !== 'booked') {
            return [false, 'Only a booked interview can be re-offered.'];
        }
        $a = $this->application((int) $iv['application_id']);
        if (!$a || !$this->openSlots((int) $a['role_id'], (int) $iv['round'])) {
            return [false, 'There are no open round ' . (int) $iv['round'] . ' times to choose from. Add the client\'s new times first, or use Change the time.'];
        }
        $this->cancel($id, $userId, false, true, 'the candidate will choose a new time');
        return $this->invite((int) $iv['application_id'], (int) $iv['round'], $userId);
    }

    /** The candidate says none of the times work: tell whoever sent the link. */
    public function noneWork(array $iv, string $message): void
    {
        $a = $this->application((int) $iv['application_id']);
        $u = $this->sender((int) $iv['created_by']);
        $url = rtrim((string) ($this->app->config['app']['url'] ?? ''), '/');
        $text = "{$a['full_name']} (" . ref_candidate($a['candidate_id']) . ") says none of the interview times for {$a['title']} (round {$iv['round']}) work.\n\n"
            . ($message !== '' ? "Their message: \"$message\"\n\n" : '') . "Open the candidate: $url/candidates/{$a['candidate_id']}#interviews\n";
        (new Mailer($this->app))->enqueue(['kind' => 'client_reply', 'to_email' => $u['email'], 'to_name' => $u['name'],
            'subject' => "{$a['full_name']} can't make the interview times", 'html' => '<pre style="font-family:Arial, sans-serif; font-size:14px; white-space:pre-wrap;">' . htmlspecialchars($text) . '</pre>', 'text' => $text, 'priority' => 15]);
        $this->app->db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => null,
            'body' => "Said none of the round {$iv['round']} interview times work." . ($message !== '' ? " Their message: \"$message\"" : '')]);
    }

    /** A booking is confirmed: call letter to the candidate, confirmation to the client, stage moved on. */
    private function confirmed(int $id, int $userId): void
    {
        $db = $this->app->db;
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        $a = $this->application((int) $iv['application_id']);
        $u = $this->sender($userId);
        [$subject] = $this->letter($iv, $a);
        $files = $this->attachmentsFor($iv, $a);
        $emailId = $this->designed('interview', $a, $subject, $this->confirmation($iv, $a), $u, ['interview' => $id], $files, 10, $userId);
        $db->run('UPDATE interviews SET letter_email_id = ?, letter_sent_at = NOW() WHERE id = ?', [$emailId, $id]);
        // Tell the client: straight away when the interview is today, or tomorrow and it's already past 6 pm (the 6 pm
        // email has gone); otherwise in one email at 6 pm with everything booked that day (Benjamin, 17 Nov 2026).
        $contact = $db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$a['client_id']]);
        if ($contact && !self::clientNow((string) $iv['starts_at'], date('Y-m-d H:i:s'), $this->digestOn())) {
            $contact = null; // waits for the 6 pm email (clientDigest)
        } elseif ($contact) {
            $db->run('UPDATE interviews SET client_notified_at = NOW() WHERE id = ?', [$id]);
        }
        if ($contact) {
            $when = $this->when($iv);
            $cfirst = trim((string) strtok((string) $contact['name'], ' '));
            $where = $iv['kind'] === 'virtual' ? "Online: {$iv['meeting_link']}" : ($iv['kind'] === 'in_person' ? 'In person at: ' . one_line($iv['address']) : 'By phone');
            $cbody = ($cfirst !== '' ? "$cfirst," : 'Hello,') . "\n\nThis confirms the round {$iv['round']} interview with {$a['full_name']} (" . ref_candidate($a['candidate_id']) . ") for the {$a['title']} position:\n\n"
                . "Date & Time: $when\n$where\n\nA calendar invite is attached. I'll be in touch for your feedback after the interview.";
            $this->queue('client_interview', ['email' => $contact['email'], 'full_name' => $contact['name'], 'candidate_id' => null], "Interview confirmed: {$a['full_name']}, {$a['title']}, " . date('j M, g:i A', strtotime($iv['starts_at'])),
                $cbody, $u, ['interview' => $id], [['ics' => $this->ics($iv, $a, true), 'name' => 'Interview.ics']], 12, $userId);
        }
        // Move the candidate to "Client interview" unless already further along.
        $sid = Stages::id($this->app, Stages::INTERVIEW);
        $stage = $sid ? $db->one('SELECT id, sort FROM stages WHERE id = ? AND is_active = 1', [$sid]) : null;
        $cur = $db->one('SELECT a.stage_id, s.sort FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$a['id']]);
        if ($stage && $cur && (int) $cur['sort'] < (int) $stage['sort']) {
            $db->update('applications', ['stage_id' => $stage['id']], (int) $a['id']);
            $db->insert('stage_history', ['application_id' => $a['id'], 'from_stage_id' => $cur['stage_id'], 'to_stage_id' => $stage['id'], 'user_id' => $userId ?: null, 'note' => 'Interview booked (' . ref_interview($id) . ')']);
        }
        (new Retention($this->app))->touch((int) $a['candidate_id']);
        Audit::log('interview_booked', 'candidate', (int) $a['candidate_id'], ['interview' => $id, 'round' => (int) $iv['round']]);
    }

    /** The hour of the client's daily email (24-hour clock). */
    public const DIGEST_HOUR = 18;

    /**
     * Does the client hear about this booking straight away? Yes when the interview is today, or tomorrow and the 6 pm
     * email has already gone (it's 6 pm or later now), or the daily email is switched off. Pure.
     */
    public static function clientNow(string $startsAt, string $now, bool $digestOn = true): bool
    {
        if (!$digestOn) {
            return true;
        }
        $day = substr($startsAt, 0, 10);
        $today = substr($now, 0, 10);
        if ($day <= $today) {
            return true;
        }
        return $day === date('Y-m-d', strtotime($today . ' +1 day')) && (int) substr($now, 11, 2) >= self::DIGEST_HOUR;
    }

    /** The 6 pm client email is on unless switched off in settings (client_digest = 0). */
    public function digestOn(): bool
    {
        // No update check here: it runs inside the booking's transaction, and pages already wait while an update is pending.
        return $this->app->setting('client_digest', '1') === '1';
    }

    /**
     * The 6 pm email (17 Nov 2026): to each client, one email with every interview booked for them that the client hasn't
     * been told about yet, in time order, with all the calendar invites. Returns a line for the scheduled-jobs log.
     */
    public function clientDigest(): string
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT i.id, r.client_id FROM interviews i JOIN applications a ON a.id = i.application_id JOIN roles r ON r.id = a.role_id
            WHERE i.status = 'booked' AND i.client_notified_at IS NULL AND i.starts_at > NOW() ORDER BY r.client_id, i.starts_at");
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['client_id']][] = (int) $r['id'];
        }
        $sent = 0;
        foreach ($by as $clientId => $ids) {
            $contact = $db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$clientId]);
            if (!$contact) {
                $db->run('UPDATE interviews SET client_notified_at = NOW() WHERE id IN (' . implode(',', $ids) . ')'); // nobody to tell
                continue;
            }
            $lines = [];
            $rowsT = [];
            $wheres = [];
            $files = [];
            $creator = 0;
            foreach ($ids as $n => $id) {
                $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
                $a = $this->application((int) $iv['application_id']);
                $creator = $creator ?: (int) $iv['created_by'];
                $where = $iv['kind'] === 'virtual' ? 'Online: ' . $iv['meeting_link'] : ($iv['kind'] === 'in_person' ? 'In person at: ' . one_line($iv['address']) : 'By phone');
                $t0 = strtotime((string) $iv['starts_at']);
                $slot = date('D j M, g:i', $t0) . '–' . date('g:i A', $t0 + 60 * (int) $iv['duration_min']);
                $lines[] = '- ' . $a['full_name'] . ' (' . ref_candidate($a['candidate_id']) . ') | ' . $slot . ' | ' . $a['title'] . ((int) $iv['round'] > 1 ? ', round ' . (int) $iv['round'] : '') . ' | ' . $where;
                $rowsT[] = [$a['full_name'] . ' (' . ref_candidate($a['candidate_id']) . ')', $slot, $a['title'] . ((int) $iv['round'] > 1 ? ', round ' . (int) $iv['round'] : ''), $where];
                $wheres[$where] = true;
                $files[] = ['ics' => $this->ics($iv, $a, true), 'name' => 'Interview ' . ($n + 1) . ' - ' . preg_replace('/[^\p{L}\p{N} .-]/u', '', (string) $a['full_name']) . '.ics'];
            }
            $cfirst = trim((string) strtok((string) $contact['name'], ' '));
            $count = count($ids);
            $oneWhere = count($wheres) === 1 ? array_key_first($wheres) : null; // the same Meet link for all: once, under the table
            $intro = ($cfirst !== '' ? "$cfirst," : 'Hello,') . "\n\nThis confirms the " . ($count === 1 ? 'interview' : "$count interviews") . ' arranged today:';
            $outro = ($oneWhere !== null ? $oneWhere . "\n\n" : '') . ($count === 1 ? 'A calendar invite is attached.' : 'A calendar invite for each is attached.') . " I'll be in touch for your feedback after the interviews.";
            $body = $intro . "\n\n" . implode("\n", $lines) . "\n\n" . $outro;
            $u = $this->sender($creator ?: null);
            $this->queue('client_interview', ['email' => $contact['email'], 'full_name' => $contact['name'], 'candidate_id' => null],
                'Interviews confirmed: ' . $count . ' arranged today (' . date('j M') . ')', $body, $u, ['interviews' => $ids, 'digest' => true], $files, 12, $creator ?: null,
                null, null, [$intro, self::digestTable($rowsT, $oneWhere === null), $outro]);
            $db->run('UPDATE interviews SET client_notified_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
            $sent++;
        }
        return $sent ? "6 pm interview email to $sent client(s)" : '';
    }

    /** The 6 pm email's table: Name, Time slot, Position (and Where, when the links differ). Inline styles for mail apps. */
    public static function digestTable(array $rows, bool $withWhere): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $th = 'style="text-align:left; padding:8px 12px; background:#320A67; color:#FFFFFF; font-size:14px; font-weight:bold; border:1px solid #320A67;"';
        $head = ['Name', 'Time slot', 'Position'];
        if ($withWhere) {
            $head[] = 'Where';
        }
        $html = '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:4px 0 16px 0; font-family:Calibri, Arial, Helvetica, sans-serif; font-size:14px; color:#222222;"><tr>';
        foreach ($head as $h) {
            $html .= '<th ' . $th . '>' . $h . '</th>';
        }
        $html .= '</tr>';
        foreach ($rows as $i => $r) {
            $bg = $i % 2 ? '#F6F2FB' : '#FFFFFF';
            $cells = $withWhere ? $r : array_slice($r, 0, 3);
            $html .= '<tr>';
            foreach ($cells as $j => $c) {
                $v = $e($c);
                if ($j === 3) {
                    $v = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#5B2C93;">$1</a>', $v);
                }
                $html .= '<td style="padding:8px 12px; border:1px solid #E2D8F1; background:' . $bg . ';' . ($j === 0 ? ' font-weight:bold;' : '') . '">' . $v . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** How the round is held, in the candidate's words. */
    public const HOW = ['virtual' => 'video', 'in_person' => 'in-person', 'phone' => 'phone'];

    /** "the Accounts Executive role" ("... role on Eshcol's payroll" for staffing). */
    private static function roleLine(array $a): string
    {
        return 'the ' . $a['title'] . ' role' . (Engagement::of($a) === 'staffing' ? " on Eshcol's payroll" : '');
    }

    /** When / Where / Round rows and the join or map button, for the confirmation and the reminder. @return array{0: array, 1: array} */
    private function detailRows(array $iv, bool $withDate): array
    {
        $e = fn ($v) => CandidateEmail::h((string) $v);
        $t = strtotime((string) $iv['starts_at']);
        $when = date($withDate ? 'l j F Y, g:i A' : 'g:i A', $t) . ' (' . (int) $iv['duration_min'] . ' minutes)';
        $rows = [['When', $e($when), $when]];
        $btns = [];
        if ($iv['kind'] === 'in_person') {
            $addr = one_line((string) $iv['address']);
            $rows[] = ['Where', $e($addr), $addr];
            if ($iv['maps_url']) {
                $btns[] = ['Open in Maps', (string) $iv['maps_url']];
            }
        } elseif ($iv['kind'] === 'virtual') {
            $link = (string) $iv['meeting_link'];
            $tool = str_contains($link, 'meet.google') ? 'Google Meet' : (str_contains($link, 'teams.') ? 'Microsoft Teams' : (str_contains($link, 'zoom.') ? 'Zoom' : ''));
            $where = $tool !== '' ? "Online, on $tool" : 'Online';
            $rows[] = ['Where', $e($where), $where];
            if ($link !== '') {
                $btns[] = [$tool !== '' ? "Join on $tool" : 'Join the interview', $link];
            }
        } else {
            $rows[] = ['Where', 'They will call you on your mobile.', 'They will call you on your mobile.'];
        }
        $rows[] = ['Round', (string) (int) $iv['round'], (string) (int) $iv['round']];
        return [$rows, $btns];
    }

    /** The confirmation, in the house design: your editable call letter goes inside the box (approved 15 Nov 2026). */
    public function confirmation(array $iv, array $a): array
    {
        $first = $this->firstName($a);
        [, $letter] = $this->letter($iv, $a);
        $letter = (string) preg_replace('/^(Dear|Hello)[^\n]*\n+/', '', $letter); // the headline greets them
        [$rows, $btns] = $this->detailRows($iv, true);
        return [
            'preheader' => date('l j F, g:i A', strtotime((string) $iv['starts_at'])) . ': ' . $a['title'] . ' with ' . $a['client_display'] . '.',
            'eyebrow' => 'Interview confirmed',
            'headline' => $first !== '' ? "You're booked in, $first." : "You're booked in.",
            'sub' => 'Your ' . self::HOW[$iv['kind']] . ' interview with ' . $a['client_display'] . ' for ' . self::roleLine($a) . '.',
            'details' => $rows, 'details_buttons' => $btns,
            'action' => ['paras' => [[CandidateEmail::plainHtml($letter), trim($letter)]],
                'small' => 'A calendar invite is attached too. If anything changes, reply to this email or call me straight away.'],
        ];
    }

    /** The same-day reminder, in the house design. */
    public function reminder(array $iv, array $a): array
    {
        $first = $this->firstName($a);
        [$rows, $btns] = $this->detailRows($iv, false);
        $tip = match ($iv['kind']) {
            'virtual' => 'Join from a laptop or desktop, in a quiet place, 5 minutes early.',
            'in_person' => 'Please reach 10 minutes early and carry a copy of your CV.',
            default => 'They will call you on your mobile number. Please keep it charged and somewhere quiet.',
        };
        $at = date('g:i A', strtotime((string) $iv['starts_at']));
        return [
            'preheader' => 'Your interview with ' . $a['client_display'] . " is today at $at.",
            'eyebrow' => 'Today',
            'headline' => $first !== '' ? "All the best today, $first." : 'All the best today.',
            'sub' => 'Your interview with ' . $a['client_display'] . ' for ' . self::roleLine($a) . " is at $at.",
            'details' => $rows, 'details_buttons' => $btns,
            'action' => ['paras' => [[CandidateEmail::h($tip), $tip]], 'small' => 'If anything has changed, reply to this email or call me straight away.'],
        ];
    }

    /** Queues a candidate email in the house design, signed by whoever arranged it, with the LinkedIn button and any files. */
    private function designed(string $kind, array $to, string $subject, array $p, array $u, array $meta, array $files, int $priority, ?int $userId): int
    {
        $lines = array_values(array_filter([(string) ($u['title'] ?? ''), 'Eshcol Global Services (P) Ltd', !empty($u['mobile']) ? 'Mobile: ' . $u['mobile'] : '']));
        $sub = (string) $p['sub'];
        [$html, $text] = CandidateEmail::render($p + ['title' => $subject, 'sub_html' => CandidateEmail::h($sub), 'sub_text' => $sub,
            'signature' => ['name' => (string) ($u['name'] ?? 'Eshcol Global'), 'lines' => $lines, 'user' => $u], 'footer_reason' => Engagement::footerReason(Engagement::of($to))]);
        return (new Mailer($this->app))->enqueue(['kind' => $kind, 'candidate_id' => $to['candidate_id'] ?? null, 'to_email' => $to['email'], 'to_name' => $to['full_name'] ?? null,
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => $meta + ['attachments' => $files], 'priority' => $priority, 'created_by' => $userId]);
    }

    /** The call letter, from the editable template for the round type. @return array{0:string,1:string} subject, body */
    public function letter(array $iv, array $a): array
    {
        $tpl = (string) $this->app->setting($iv['kind'] === 'in_person' ? 'letter_in_person' : 'letter_virtual', '');
        if ($iv['kind'] === 'phone') {
            $tpl = "As discussed, your phone interview with our client, {{client_name}}, is confirmed for {{date}}, at {{time}} HRS. They will call you on your mobile number.\n\nI've attached the job description for your reference.\n\nBest of luck with your interview!";
        }
        // By type of position (15 Nov 2026, approved): our own team is "your interview with Eshcol Global"; staffing adds
        // "for a role on Eshcol's payroll". The editable templates keep their "our client" wording for client recruitment.
        $type = Engagement::of($a);
        foreach (['{{client_line}}', '{{client_name}}'] as $ph) {
            if ($type === 'inhouse') {
                $tpl = str_replace("our client, $ph,", $ph, $tpl);
                $tpl = str_replace("our client, $ph", $ph, $tpl);
            } elseif ($type === 'staffing') {
                $tpl = str_replace("our client, $ph,", "our client, $ph, for a role on Eshcol's payroll,", $tpl);
            }
        }
        $link = (string) $iv['meeting_link'];
        $vars = [
            '{{first_name}}' => $this->firstName($a), '{{client_name}}' => $a['client_display'],
            '{{client_line}}' => $a['client_display'] . ($a['website'] ? ' (' . $a['website'] . ')' : ''),
            '{{date}}' => date('F j, Y', strtotime($iv['starts_at'])), '{{time}}' => date('H:i', strtotime($iv['starts_at'])),
            '{{meeting_link}}' => $link, '{{meeting_tool}}' => str_contains($link, 'meet.google') ? 'Google Meet' : (str_contains($link, 'teams.') ? 'Microsoft Teams' : (str_contains($link, 'zoom.') ? 'Zoom' : 'meeting')),
            '{{address}}' => one_line($iv['address']), '{{maps_url}}' => (string) $iv['maps_url'],
            '{{role}}' => $a['title'], '{{round}}' => (string) $iv['round'],
        ];
        $first = $this->firstName($a);
        $body = ($first !== '' ? "Dear $first," : 'Dear Candidate,') . "\n\n" . strtr($tpl, $vars);
        $subject = 'Interview confirmed: ' . $a['title'] . ' with ' . $a['client_display'] . ', ' . date('j M Y, g:i A', strtotime($iv['starts_at']));
        return [$subject, $body];
    }

    /** JD always; company profile for in-person rounds; the calendar invite. */
    private function attachmentsFor(array $iv, array $a): array
    {
        $docs = new Documents($this->app);
        $files = [];
        if ($iv['kind'] === 'in_person' && ($p = Documents::attachment($docs->find('client', (int) $a['client_id'], 'company_profile'), 'Company profile'))) {
            $files[] = $p;
        }
        if ($jd = Documents::attachment($docs->find('role', (int) $a['role_id'], 'jd'), 'Job description')) {
            $files[] = $jd;
        }
        $files[] = ['ics' => $this->ics($iv, $a, false), 'name' => 'Interview.ics'];
        return $files;
    }

    /** A calendar invite (.ics) that opens in Outlook, Google Calendar and Apple Calendar. */
    public function ics(array $iv, array $a, bool $forClient): string
    {
        $start = strtotime($iv['starts_at']);
        $end = $start + 60 * (int) $iv['duration_min'];
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
        $what = $forClient ? "Interview: {$a['full_name']} for {$a['title']} (round {$iv['round']})" : "Interview: {$a['title']} with {$a['client_display']} (round {$iv['round']})";
        $where = $iv['kind'] === 'virtual' ? (string) $iv['meeting_link'] : ($iv['kind'] === 'in_person' ? one_line($iv['address']) : 'Phone');
        $desc = $iv['kind'] === 'virtual' ? "Join: {$iv['meeting_link']}" : ($iv['kind'] === 'in_person' ? 'Address: ' . $where . ($iv['maps_url'] ? "\nMap: {$iv['maps_url']}" : '') : 'Phone interview');
        $host = parse_url((string) ($this->app->config['app']['url'] ?? 'https://recruit.eshcolglobal.com'), PHP_URL_HOST) ?: 'recruit.eshcolglobal.com';
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Eshcol Global//Eshcol Recruit//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:iv-' . (int) $iv['id'] . ($forClient ? '-c' : '') . '@' . $host, 'SEQUENCE:' . (int) ($iv['reschedules'] ?? 0), 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . gmdate('Ymd\THis\Z', $start), 'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . $esc($what), 'LOCATION:' . $esc($where), 'DESCRIPTION:' . $esc($desc . "\n\nArranged by Eshcol Global, " . ref_interview($iv['id']))];
        if ($iv['kind'] === 'virtual' && $iv['meeting_link']) {
            $lines[] = 'URL:' . $iv['meeting_link'];
        }
        $lines = array_merge($lines, ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Interview', 'TRIGGER:-PT30M', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR']);
        // Lines over 75 octets are folded, as the calendar standard requires.
        return implode("\r\n", array_map(fn ($l) => rtrim(chunk_split($l, 74, "\r\n "), "\r\n "), $lines)) . "\r\n";
    }

    // ---------------------------------------------------------------- after booking

    /** Scheduled: the reminder before each interview (4 hours by default). Returns a log line or ''. */
    public function reminders(): string
    {
        $db = $this->app->db;
        $hours = max(1, (int) $this->app->setting('interview_reminder_hours', '4'));
        $due = $db->all("SELECT * FROM interviews WHERE status = 'booked' AND reminded_at IS NULL AND starts_at > NOW() AND starts_at <= NOW() + INTERVAL $hours HOUR");
        foreach ($due as $iv) {
            $db->run('UPDATE interviews SET reminded_at = NOW() WHERE id = ? AND reminded_at IS NULL', [$iv['id']]);
            $a = $this->application((int) $iv['application_id']);
            if (!$a || !$a['email'] || Contact::silenced($a)) {
                continue;
            }
            $this->designed('interview', $a, 'Reminder: your interview today at ' . date('g:i A', strtotime($iv['starts_at'])), $this->reminder($iv, $a),
                $this->sender((int) $iv['created_by']), ['interview' => (int) $iv['id'], 'reminder' => true], [], 5, null);
        }
        return $due ? count($due) . ' interview reminder(s) queued' : '';
    }

    /**
     * Interviews that have taken place and still wait for "How did it go?" (My day: Waiting on you, and Today).
     * 24 Nov 2026: left out once the person has been moved on by their stage instead (Rejected by client,
     * Not suitable, Withdrew, or anything past Client interview such as Offer made), and an earlier round once a later
     * round exists for the same position. Before, those stayed on the list for ever. $scope is the My day filter SQL.
     */
    public static function awaitingOutcome(\Eshcol\Ats\Db $db, string $scope = '1=1', int $limit = 5): array
    {
        return $db->all("
            SELECT i.id, i.round, i.starts_at, a.candidate_id, c.full_name, r.title
            FROM interviews i JOIN applications a ON a.id = i.application_id JOIN stages s ON s.id = a.stage_id
                 JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id
            WHERE i.status = 'booked' AND i.starts_at < NOW() - INTERVAL i.duration_min MINUTE AND s.kind IN ('active','hold')
              AND s.sort <= COALESCE((SELECT MAX(x.sort) FROM stages x WHERE x.code = 'interview'), s.sort)
              AND NOT EXISTS (SELECT 1 FROM interviews l WHERE l.application_id = i.application_id AND l.round > i.round AND l.status IN ('choosing','booked','done'))
              AND $scope
            ORDER BY i.starts_at LIMIT " . max(1, $limit));
    }

    /** Records how a round went. Selected → "Offer made"; not selected → "Not suitable". */
    /**
     * Returns a note for the person recording it when the stage could not move (e.g. the offer still needs approval),
     * else null. The stage moves through StageMover, so approvals, the joining record and the placement all apply.
     */
    public function outcome(int $id, string $outcome, string $feedback, int $userId, bool $byOwner = false): ?string
    {
        $db = $this->app->db;
        if (!isset(self::OUTCOMES[$outcome])) {
            return null;
        }
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        if (!$iv) {
            return null;
        }
        $db->run("UPDATE interviews SET status = ?, outcome = ?, feedback = ? WHERE id = ?", [$outcome === 'no_show' ? 'no_show' : 'done', $outcome, mb_substr($feedback, 0, 4000) ?: null, $id]);
        $code = match ($outcome) {
            'selected' => Stages::OFFER,
            'not_selected' => Stages::NOT_SUITABLE,
            'no_show' => Stages::WITHDREW,
            default => null,
        };
        $target = $code ? Stages::id($this->app, $code) : null;
        if ($target && !$db->col('SELECT is_active FROM stages WHERE id = ?', [$target])) {
            $target = null;
        }
        if (!$target && $outcome === 'no_show') {
            $target = (int) $db->col("SELECT id FROM stages WHERE is_active = 1 AND kind = 'fail' ORDER BY sort DESC LIMIT 1") ?: null;
        }
        $a = $db->one('SELECT * FROM applications WHERE id = ?', [$iv['application_id']]);
        $note = null;
        if ($target && $a && $target !== (int) $a['stage_id']) {
            $mover = new StageMover($this->app);
            if ($why = $mover->blocked((int) $a['id'], $target)) {
                $note = 'Recorded, but the stage was not changed: ' . $why;
            } else {
                $mover->move($a, $target, 'Round ' . $iv['round'] . ': ' . self::OUTCOMES[$outcome], $userId, $byOwner);
            }
        }
        if ($outcome === 'no_show') {
            $a = $this->application((int) $iv['application_id']);
            $when = $iv['starts_at'] ? date('j M Y', strtotime($iv['starts_at'])) : date('j M Y');
            (new Reliability($this->app))->mark((int) $a['candidate_id'], 'no_show', 'Round ' . $iv['round'] . ', ' . $a['title'] . ' (' . $a['client_name'] . '), ' . $when, $userId);
        }
        Audit::log('interview_outcome', 'interview', $id, ['outcome' => $outcome]);
        return $note;
    }

    /**
     * Cancels an interview and frees its time. For a booked one, the candidate and/or the client can be told by email
     * (20 Nov 2026; before, a cancellation told nobody). Returns who was told, for the confirmation.
     */
    public function cancel(int $id, int $userId, bool $tellCandidate = false, bool $tellClient = false, string $reason = ''): string
    {
        $db = $this->app->db;
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        $db->run("UPDATE interviews SET status = 'cancelled' WHERE id = ?", [$id]);
        $db->run('UPDATE interview_slots SET interview_id = NULL WHERE interview_id = ?', [$id]);
        Audit::log('interview_cancelled', 'interview', $id);
        if (!$iv || $iv['status'] !== 'booked' || !$iv['starts_at']) {
            return '';
        }
        $a = $this->application((int) $iv['application_id']);
        if (!$a) {
            return '';
        }
        $u = $this->sender($userId);
        $whenTxt = date('l j F, g:i A', strtotime((string) $iv['starts_at']));
        $reason = trim($reason);
        $told = [];
        if ($tellCandidate && $a['email'] && !Contact::silenced($a)) {
            $first = $this->firstName($a);
            $body = ($first !== '' ? "Dear $first," : 'Dear Candidate,') . "\n\nI'm writing to let you know that your round {$iv['round']} interview with {$a['client_display']} for " . self::roleLine($a)
                . ", on $whenTxt, has been cancelled" . ($reason !== '' ? ": $reason." : '.') . "\n\nI'll be in touch with you about the next step.";
            $this->queue('interview', ['email' => $a['email'], 'full_name' => $a['full_name'], 'candidate_id' => $a['candidate_id']],
                "Interview cancelled: {$a['title']}, " . date('j M, g:i A', strtotime((string) $iv['starts_at'])), $body, $u, ['interview' => $id, 'cancelled' => true], [], 10, $userId);
            $told[] = $a['full_name'];
        }
        $contact = $tellClient ? $db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$a['client_id']]) : null;
        if ($contact) {
            $cfirst = trim((string) strtok((string) $contact['name'], ' '));
            $body = ($cfirst !== '' ? "$cfirst," : 'Hello,') . "\n\nPlease note that the round {$iv['round']} interview with {$a['full_name']} (" . ref_candidate($a['candidate_id']) . ") for the {$a['title']} position, on $whenTxt, has been cancelled"
                . ($reason !== '' ? ": $reason." : '.') . " Please remove it from your calendar; I'll be in touch.";
            $this->queue('client_interview', ['email' => $contact['email'], 'full_name' => $contact['name'], 'candidate_id' => null],
                "Interview cancelled: {$a['full_name']}, {$a['title']}, " . date('j M, g:i A', strtotime((string) $iv['starts_at'])), $body, $u, ['interview' => $id, 'cancelled' => true], [], 12, $userId);
            $told[] = $contact['name'] . ' at the client';
        }
        $db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => $userId ?: null,
            'body' => "Round {$iv['round']} interview (" . ref_interview($id) . ") on $whenTxt cancelled" . ($reason !== '' ? ": $reason" : '') . ($told ? '. Told: ' . implode(' and ', $told) : '') . '.']);
        return $told ? 'Told: ' . implode(' and ', $told) . '.' : '';
    }

    // ---------------------------------------------------------------- lookups

    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        return $this->app->db->one('SELECT * FROM interviews WHERE token_hash = ?', [hash('sha256', $token)]) ?: null;
    }

    public function link(int $id): string
    {
        $enc = (string) $this->app->db->col('SELECT token_enc FROM interviews WHERE id = ?', [$id]);
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/i/' . Secrets::open($this->app, $enc, 'interview');
    }

    /** The application with everything letters need. */
    public function application(int $applicationId): ?array
    {
        $a = $this->app->db->one('SELECT a.id, a.role_id, a.candidate_id, c.full_name, c.first_name, c.email, c.phone, c.phone_norm, c.do_not_contact, c.removal_requested_at, c.unreliable_at,
                                         r.title, r.location, r.client_id, r.engagement, cl.name AS client_name, cl.logo_path, cl.logo_updated_at, cl.legal_name, cl.website, cl.interview_address, cl.maps_url, cl.meeting_link
                                  FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id WHERE a.id = ?', [$applicationId]);
        if ($a) {
            $a['client_display'] = $a['legal_name'] ?: $a['client_name'];
            if (Engagement::of($a) === 'inhouse') {
                $a['client_display'] = Engagement::NAME; // our own team: "your interview with Eshcol Global" (15 Nov 2026)
            }
        }
        return $a ?: null;
    }

    public function when(array $iv): string
    {
        return date('l, F j, Y \a\t H:i \H\r\s', strtotime($iv['starts_at']));
    }

    private function firstName(array $a): string
    {
        $f = trim((string) ($a['first_name'] ?: strtok((string) $a['full_name'], ' ')));
        return $f !== '' ? mb_convert_case(mb_strtolower($f), MB_CASE_TITLE) : '';
    }

    private function url(string $u): string
    {
        return preg_match('#^https?://#i', $u) ? $u : 'https://' . $u;
    }

    private function sender(?int $userId): array
    {
        $db = $this->app->db;
        return ($userId ? $db->one('SELECT name, title, mobile, email, role FROM users WHERE id = ?', [$userId]) : null)
            ?: ($db->one("SELECT name, title, mobile, email, role FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: ['name' => 'Eshcol Global', 'title' => '', 'mobile' => '', 'email' => '']);
    }

    /** Queues a plain, personal-looking email (like the letters Benjamin writes) with signature, optional button and files. */
    private function queue(string $kind, array $to, string $subject, string $body, array $u, array $meta, array $files, int $priority, ?int $userId, ?string $buttonUrl = null, ?string $buttonLabel = null, ?array $parts = null): int
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sig = signature_lines($u);
        $toHtml = function (string $t) use ($esc): string {
            $l = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#5B2C93;">$1</a>', nl2br($esc($t)));
            return preg_replace('#(^|<br />\s*)- #', '$1&bull;&nbsp;', $l); // bullet lines ("- ...") as real bullets
        };
        // $parts: [text, html (a table), text] for the 6 pm email; otherwise the plain body.
        $linked = $parts ? $toHtml($parts[0]) . '</p>' . $parts[1] . '<p style="margin:0 0 16px 0;">' . $toHtml($parts[2]) : $toHtml($body);
        $toCandidate = !empty($to['candidate_id']) && !str_starts_with($kind, 'client'); // every candidate email carries the LinkedIn button
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:23px; color:#222222; max-width:640px;">'
            . '<p style="margin:0 0 16px 0;">' . $linked . '</p>'
            . ($buttonUrl ? '<p style="margin:0 0 18px 0;"><a href="' . $esc($buttonUrl) . '" style="display:inline-block; padding:11px 20px; background:#5B2C93; color:#FFFFFF; text-decoration:none; border-radius:6px; font-weight:bold; font-family:Arial, Helvetica, sans-serif;">' . $esc((string) $buttonLabel) . '</a></p>' : '')
            . signature_html($u)
            . ($toCandidate ? CandidateEmail::linkedinPlain()[0] : '')
            . '</div></body></html>';
        $text = $body . "\n\nRegards,\n" . implode("\n", $sig) . "\n" . ($toCandidate ? CandidateEmail::linkedinPlain()[1] : '');
        $id = (new Mailer($this->app))->enqueue(['kind' => $kind, 'candidate_id' => $to['candidate_id'] ?? null, 'to_email' => $to['email'], 'to_name' => $to['full_name'] ?? null,
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => $meta + ['attachments' => $files], 'priority' => $priority, 'created_by' => $userId]);
        return $id;
    }
}
