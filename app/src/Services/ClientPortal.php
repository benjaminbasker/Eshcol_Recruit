<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * The client portal (Benjamin, 13 Nov 2026).
 *
 * A client's contact signs in with a link sent to their email: no password to keep or forget. The
 * owner gives access per contact (the client's page) and can take it away at once. Signed in, the
 * contact sees only their own company's positions and the candidates sent to them:
 *  - every candidate from every shortlist sent (not drafts, not closed ones), newest version of each;
 *  - Shortlist / Hold / Not suitable with a comment, as on the shortlist link; the owners are told;
 *  - interview times offered as a window with a chosen interview length: 5–6 pm at 20 minutes gives
 *    three times for candidates to pick from (the owner still invites the candidates, as before);
 *  - a new position can be asked for; it arrives in Eshcol Recruit as a draft.
 * Candidates' email and phone are shown (Benjamin, 14 Nov 2026: clients always get contact details).
 * Sign-in links are single use; only their hashes are stored. Opening the link shows a button
 * (so an email scanner that visits the link doesn't use it up).
 */
final class ClientPortal
{
    public const INVITE_DAYS = 7;
    public const LINK_MINUTES = 30;
    public const SESSION_DAYS = 30; // Benjamin, 20 Nov 2026: stay signed in for 30 days on their own device
    public const CODE_MINUTES = 10;
    public const CODE_TRIES = 5;
    public const PREVIEW_HOURS = 2;
    /** Interview lengths a client can choose, in minutes. */
    public const LENGTHS = [15, 20, 30, 45, 60];
    private const MAX_SLOTS = 40;

    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- access (the owner's side)

    /** Adds a contact to a client. Returns the id, or a message for the person. */
    public function addContact(int $clientId, string $name, string $email, ?string $designation, ?string $phone): int|string
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '') {
            return 'Give the contact a name.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'That email address does not look right.';
        }
        return $this->app->db->insert('client_contacts', ['client_id' => $clientId, 'name' => mb_substr($name, 0, 120), 'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
            'designation' => $designation ? mb_substr(trim($designation), 0, 120) : null, 'phone' => $phone ? mb_substr(trim($phone), 0, 30) : null]);
    }

    /** Gives a contact access and emails the invitation (or a fresh one). Returns a problem, or null. */
    public function invite(int $contactId, int $userId): ?string
    {
        $db = $this->app->db;
        $c = $this->contactRow($contactId);
        if (!$c) {
            return 'Contact not found.';
        }
        if (!$c['email'] || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Add an email address for ' . $c['name'] . ' first: the sign-in link is sent there.';
        }
        // The same address at two clients would not know which company to open.
        if ($db->col('SELECT 1 FROM client_contacts WHERE email = ? AND portal_access = 1 AND id <> ?', [$c['email'], $contactId])) {
            return 'Another contact with this email address already has portal access.';
        }
        $db->run('UPDATE client_contacts SET portal_access = 1, portal_invited_at = NOW() WHERE id = ?', [$contactId]);
        $token = $this->issue($contactId, 'invite', null);
        $this->emailLink($c, $token, 'invite', $userId);
        Audit::log('portal_invited', 'client', (int) $c['client_id'], ['contact' => $contactId]);
        return null;
    }

    /** Takes access away: every sign-in and unused link of the contact ends now. */
    public function revoke(int $contactId): void
    {
        $db = $this->app->db;
        $c = $this->contactRow($contactId);
        if (!$c) {
            return;
        }
        $db->run('UPDATE client_contacts SET portal_access = 0 WHERE id = ?', [$contactId]);
        $db->run('DELETE FROM client_sessions WHERE contact_id = ?', [$contactId]);
        $db->run('DELETE FROM client_logins WHERE contact_id = ?', [$contactId]);
        Audit::log('portal_revoked', 'client', (int) $c['client_id'], ['contact' => $contactId]);
    }

    // ---------------------------------------------------------------- signing in

    /**
     * "Email me a sign-in link". Says nothing about whether the address is known (the page shows the
     * same message either way). At most 3 links per contact in 15 minutes.
     */
    public function requestLink(string $email, ?string $ip): bool
    {
        $db = $this->app->db;
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $c = $db->one('SELECT cc.*, cl.name AS client, cl.is_active FROM client_contacts cc JOIN clients cl ON cl.id = cc.client_id WHERE cc.email = ? AND cc.portal_access = 1 LIMIT 1', [$email]);
        if (!$c || !$c['is_active']) {
            return false;
        }
        if ((int) $db->col('SELECT COUNT(*) FROM client_logins WHERE contact_id = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [$c['id']]) >= 3) {
            return false;
        }
        $token = $this->issue((int) $c['id'], 'signin', $ip);
        $this->emailLink($c, $token, 'signin', null);
        return true;
    }

    /** A new one-time link for a contact. Returns the token (only its hash is stored). */
    public function issue(int $contactId, string $purpose, ?string $ip): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->app->db->run('DELETE FROM client_logins WHERE expires_at < NOW() - INTERVAL 7 DAY');
        $this->app->db->insert('client_logins', ['contact_id' => $contactId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose === 'invite' ? 'invite' : 'signin',
            'ip' => $ip, 'expires_at' => date('Y-m-d H:i:s', time() + ($purpose === 'invite' ? self::INVITE_DAYS * 86400 : self::LINK_MINUTES * 60))]);
        return $token;
    }

    /** The contact a sign-in link is for, if it can still be used. Does not use it up. */
    public function peek(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        return $this->app->db->one('SELECT cc.*, cl.name AS client, l.id AS login_id FROM client_logins l JOIN client_contacts cc ON cc.id = l.contact_id JOIN clients cl ON cl.id = cc.client_id
            WHERE l.token_hash = ? AND l.used_at IS NULL AND l.expires_at > NOW() AND cc.portal_access = 1 AND cl.is_active = 1', [hash('sha256', $token)]);
    }

    /** Uses a sign-in link: returns a session token for the cookie, or null when the link can't be used. */
    public function consume(string $token, string $userAgent = ''): ?string
    {
        $db = $this->app->db;
        $c = $this->peek($token);
        if (!$c) {
            return null;
        }
        if ($db->run('UPDATE client_logins SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$c['login_id']])->rowCount() === 0) {
            return null; // used a moment ago
        }
        return $this->startSession($c, $userAgent);
    }

    /** A new signed-in browser for a contact: returns the session token for the cookie (only its hash is stored). */
    private function startSession(array $c, string $userAgent, ?int $previewBy = null): string
    {
        $db = $this->app->db;
        $session = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->run('DELETE FROM client_sessions WHERE expires_at < NOW()');
        $db->insert('client_sessions', ['contact_id' => $c['id'], 'token_hash' => hash('sha256', $session), 'label' => mb_substr(\Eshcol\Ats\Auth::deviceLabel($userAgent), 0, 190),
            'last_seen_at' => date('Y-m-d H:i:s'), 'preview_by' => $previewBy,
            'expires_at' => date('Y-m-d H:i:s', time() + ($previewBy ? self::PREVIEW_HOURS * 3600 : self::SESSION_DAYS * 86400))]);
        if ($previewBy) {
            Audit::log('portal_previewed', 'client', (int) $c['client_id'], ['contact' => (int) $c['id']]);
        } else {
            $db->run('UPDATE client_contacts SET portal_last_login_at = NOW() WHERE id = ?', [$c['id']]);
            Audit::log('portal_signed_in', 'client', (int) $c['client_id'], ['contact' => (int) $c['id']]);
        }
        return $session;
    }

    // ---------------------------------------------------------------- signing in with a code (20 Nov 2026)

    /**
     * "Email me a code": a 6-digit code to the contact's email, good for 10 minutes and 5 tries. Says nothing about
     * whether the address is known (the page looks the same either way). At most 3 codes per contact in 15 minutes.
     */
    public function requestCode(string $email, ?string $ip): bool
    {
        $db = $this->app->db;
        $c = $this->contactByEmail($email);
        if (!$c) {
            return false;
        }
        if ((int) $db->col('SELECT COUNT(*) FROM client_logins WHERE contact_id = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [$c['id']]) >= 3) {
            return false;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->run('DELETE FROM client_logins WHERE expires_at < NOW() - INTERVAL 7 DAY');
        // A new code replaces any earlier one still waiting.
        $db->run("UPDATE client_logins SET used_at = NOW() WHERE contact_id = ? AND purpose = 'code' AND used_at IS NULL", [$c['id']]);
        $db->insert('client_logins', ['contact_id' => $c['id'], 'token_hash' => self::codeHash((int) $c['id'], $code), 'purpose' => 'code', 'ip' => $ip,
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60)]);
        $this->emailCode($c, $code);
        return true;
    }

    /** Checks a code: returns a session token for the cookie, or null (wrong, expired, used, or too many tries). */
    public function verifyCode(string $email, string $code, string $userAgent = ''): ?string
    {
        $db = $this->app->db;
        $code = (string) preg_replace('/\D/', '', $code);
        $c = $this->contactByEmail($email);
        if (!$c || strlen($code) !== 6) {
            return null;
        }
        $l = $db->one("SELECT * FROM client_logins WHERE contact_id = ? AND purpose = 'code' AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$c['id']]);
        if (!$l || (int) $l['attempts'] >= self::CODE_TRIES) {
            return null;
        }
        $db->run('UPDATE client_logins SET attempts = attempts + 1 WHERE id = ?', [$l['id']]);
        if (!hash_equals((string) $l['token_hash'], self::codeHash((int) $c['id'], $code))) {
            return null;
        }
        if ($db->run('UPDATE client_logins SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$l['id']])->rowCount() === 0) {
            return null;
        }
        return $this->startSession($c, $userAgent);
    }

    /** The owner looks at the portal as this contact sees it: a 2-hour, read-only session (nothing pressed is saved). */
    public function previewSession(int $contactId, int $userId, string $userAgent = ''): ?string
    {
        $c = $this->contactRow($contactId);
        return $c ? $this->startSession($c, $userAgent, $userId) : null;
    }

    private static function codeHash(int $contactId, string $code): string
    {
        return hash('sha256', "portal-code:$contactId:$code");
    }

    private function contactByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $c = $this->app->db->one('SELECT cc.*, cl.name AS client, cl.is_active FROM client_contacts cc JOIN clients cl ON cl.id = cc.client_id WHERE cc.email = ? AND cc.portal_access = 1 LIMIT 1', [$email]);
        return $c && $c['is_active'] ? $c : null;
    }

    /** The signed-in contact for a session cookie, with their client; null when not signed in. */
    public function contactBySession(?string $session): ?array
    {
        if (!$session || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $session)) {
            return null;
        }
        $db = $this->app->db;
        $c = $db->one('SELECT cc.*, cl.name AS client, cl.logo_path, cl.logo_updated_at, s.id AS session_id, s.preview_by FROM client_sessions s JOIN client_contacts cc ON cc.id = s.contact_id JOIN clients cl ON cl.id = cc.client_id
            WHERE s.token_hash = ? AND s.expires_at > NOW() AND (cc.portal_access = 1 OR s.preview_by IS NOT NULL) AND cl.is_active = 1', [hash('sha256', $session)]);
        if ($c) {
            $db->run('UPDATE client_sessions SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 5 MINUTE)', [$c['session_id']]);
        }
        return $c;
    }

    public function signOut(?string $session): void
    {
        if ($session) {
            $this->app->db->run('DELETE FROM client_sessions WHERE token_hash = ?', [hash('sha256', $session)]);
        }
    }

    // ---------------------------------------------------------------- what the client sees

    /**
     * The client's positions: open and on hold first, then recently filled, and the ones they asked for
     * that are still drafts. With how many candidates were sent, and how many await their decision.
     */
    public function roles(array $contact): array
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT r.id, r.title, r.code, r.location, r.work_mode, r.positions, r.status, r.opened_at, r.closed_at, r.requested_at, r.requested_by_contact_id
            FROM roles r WHERE r.client_id = ? AND r.is_pool = 0
              AND (r.status IN ('open','on_hold') OR (r.status = 'filled' AND (r.closed_at IS NULL OR r.closed_at > CURDATE() - INTERVAL 180 DAY)) OR (r.status = 'draft' AND r.requested_at IS NOT NULL))
            ORDER BY FIELD(r.status, 'open', 'on_hold', 'draft', 'filled'), r.created_at DESC", [$contact['client_id']]);
        foreach ($rows as &$r) {
            $cands = $r['status'] === 'draft' ? [] : $this->candidates($contact, (int) $r['id']);
            $r['sent'] = count($cands);
            $r['undecided'] = count(array_filter($cands, fn ($c) => !$c['decision'] && $c['state'][0] === 'review'));
            $r['interviews'] = count(array_filter($cands, fn ($c) => $c['interview'] && $c['interview']['status'] === 'booked'));
        }
        return $rows;
    }

    /** One of the client's positions, or null (another client's, a pool, or a draft they did not ask for). */
    public function role(array $contact, int $roleId): ?array
    {
        $r = $this->app->db->one("SELECT r.* FROM roles r WHERE r.id = ? AND r.client_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold','filled')", [$roleId, $contact['client_id']]);
        return $r ?: null;
    }

    /**
     * Every candidate sent to the client for a position: from each shortlist that was sent (not drafts,
     * not closed), the newest version of each person, newest first. With where they stand and any interview.
     */
    public function candidates(array $contact, int $roleId): array
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT i.*, s.mode, s.show_salary, s.sent_at, a.candidate_id, st.name AS stage, st.kind AS stage_kind, st.code AS stage_code, cd.email AS c_email, cd.phone AS c_phone
            FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id JOIN roles r ON r.id = s.role_id
            JOIN applications a ON a.id = i.application_id JOIN stages st ON st.id = a.stage_id JOIN candidates cd ON cd.id = a.candidate_id
            WHERE s.role_id = ? AND r.client_id = ? AND s.status IN ('sent','viewed','answered')
            ORDER BY i.id DESC", [$roleId, $contact['client_id']]);
        $out = [];
        foreach ($rows as $r) {
            $app = (int) $r['application_id'];
            if (isset($out[$app])) {
                continue; // an older version of the same person
            }
            $r['p'] = Shortlists::withContacts(json_decode((string) $r['profile'], true) ?: [], $r['c_email'], $r['c_phone']);
            $r['interview'] = $db->one("SELECT id, round, kind, status, starts_at, duration_min, outcome FROM interviews WHERE application_id = ? AND status IN ('choosing','booked','done','no_show')
                ORDER BY id DESC LIMIT 1", [$app]);
            $r['state'] = self::state($r);
            $out[$app] = $r;
        }
        return array_values($out);
    }

    /**
     * Where a candidate stands, in the client's words: [key, label]. Pure: tested directly.
     * Keys: review (waiting for the client's decision), interview, offer, joined, closed, hold.
     */
    public static function state(array $r): array
    {
        $stage = (string) ($r['stage'] ?? '');
        $kind = (string) ($r['stage_kind'] ?? 'active');
        $iv = $r['interview'] ?? null;
        return match (true) {
            $kind === 'success' => ['joined', 'Joined'],
            $kind === 'fail' && (isset($r['stage_code']) ? in_array($r['stage_code'], ['withdrew', 'no_response'], true) : str_starts_with($stage, 'Withdr')) => ['closed', 'Withdrew'],
            $kind === 'fail' => ['closed', 'No longer in process'],
            $kind === 'hold' => ['hold', 'On hold'],
            (isset($r['stage_code']) ? $r['stage_code'] === 'offer' : str_starts_with($stage, 'Offer')) => ['offer', 'Offer made'],
            $iv && $iv['status'] === 'booked' && $iv['starts_at'] => ['interview', 'Interview on ' . date('D j M, g:i a', strtotime((string) $iv['starts_at']))],
            $iv && $iv['status'] === 'choosing' => ['interview', 'Choosing an interview time'],
            $iv && $iv['status'] === 'no_show' => ['interview', 'Did not attend the interview'],
            $iv && $iv['status'] === 'done' => ['interview', 'Interviewed' . ($iv['outcome'] ? ': ' . (Interviews::OUTCOMES[$iv['outcome']] ?? '') : '')],
            !empty($r['decision']) => ['review', 'Your decision: ' . (Shortlists::DECISIONS[$r['decision']] ?? '')],
            default => ['review', 'Waiting for your decision'],
        };
    }

    /** One candidate sent to this client (by shortlist item id), with the shortlist row the PDF needs; else null. */
    public function item(array $contact, int $roleId, int $itemId): ?array
    {
        foreach ($this->candidates($contact, $roleId) as $c) {
            if ((int) $c['id'] === $itemId) {
                $c['shortlist'] = $this->app->db->one('SELECT s.*, r.title, r.location, r.code, r.client_id, c.name AS client, c.logo_path, c.logo_updated_at
                    FROM shortlists s JOIN roles r ON r.id = s.role_id JOIN clients c ON c.id = r.client_id WHERE s.id = ?', [$c['shortlist_id']]);
                return $c;
            }
        }
        return null;
    }

    /** The first time the client opens a position, its shortlists count as viewed (as opening the link does). */
    public function markViewed(array $contact, int $roleId): void
    {
        $this->app->db->run("UPDATE shortlists s JOIN roles r ON r.id = s.role_id SET s.first_viewed_at = COALESCE(s.first_viewed_at, NOW()), s.status = IF(s.status = 'sent', 'viewed', s.status)
            WHERE s.role_id = ? AND r.client_id = ? AND s.status IN ('sent','viewed','answered') AND s.first_viewed_at IS NULL", [$roleId, $contact['client_id']]);
    }

    /**
     * Saves the client's decisions and comments for a position; tells the owners what changed.
     * Returns how many candidates changed.
     */
    public function saveDecisions(array $contact, int $roleId, array $post): int
    {
        $db = $this->app->db;
        $role = $this->role($contact, $roleId);
        if (!$role) {
            return 0;
        }
        $changes = [];
        foreach ($this->candidates($contact, $roleId) as $it) {
            if (!isset($post['decision'][$it['id']]) && !isset($post['comment'][$it['id']])) {
                continue; // not on the form
            }
            $d = (string) ($post['decision'][$it['id']] ?? '');
            $d = isset(Shortlists::DECISIONS[$d]) ? $d : null;
            $comment = mb_substr(trim((string) ($post['comment'][$it['id']] ?? '')), 0, 2000);
            if ($d === $it['decision'] && $comment === (string) $it['comment']) {
                continue;
            }
            $db->run('UPDATE shortlist_items SET decision = ?, comment = ?, decided_at = NOW(), decided_by_contact_id = ? WHERE id = ?', [$d, $comment !== '' ? $comment : null, $contact['id'], $it['id']]);
            $db->run("UPDATE shortlists SET status = 'answered', answered_at = NOW() WHERE id = ?", [$it['shortlist_id']]);
            $changes[] = ['name' => $it['p']['name'] ?? 'Candidate', 'decision' => $d, 'comment' => $comment, 'candidate_id' => (int) $it['candidate_id']];
        }
        if ($changes) {
            Audit::log('portal_feedback', 'role', $roleId, ['contact' => (int) $contact['id'], 'changed' => count($changes)]);
            $lines = [];
            foreach ($changes as $c) {
                $lines[] = [$c['name'], ($c['decision'] ? Shortlists::DECISIONS[$c['decision']] : 'No decision') . ($c['comment'] !== '' ? ': "' . $c['comment'] . '"' : ''), "/candidates/{$c['candidate_id']}"];
            }
            $this->tellOwners("{$contact['client']} replied about {$role['title']}",
                "{$contact['name']} marked these candidates in the client portal for {$role['title']} ({$role['code']}):", $lines, "/roles/$roleId#tab-client",
                'Nobody has been moved automatically: update their stages when you are ready.');
        }
        return count($changes);
    }

    // ---------------------------------------------------------------- interview times

    /**
     * The interview times in a window, for a chosen interview length: 17:00–18:00 at 20 minutes gives
     * 17:00, 17:20 and 17:40. Pure: tested directly.
     * @return list<string> 'Y-m-d H:i'
     */
    public static function windowTimes(string $date, string $from, string $to, int $minutes): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $from) || !preg_match('/^\d{1,2}:\d{2}$/', $to) || $minutes < 5) {
            return [];
        }
        $start = strtotime("$date $from");
        $end = strtotime("$date $to");
        if (!$start || !$end || $end <= $start) {
            return [];
        }
        $out = [];
        for ($t = $start; $t + $minutes * 60 <= $end && count($out) < self::MAX_SLOTS; $t += $minutes * 60) {
            $out[] = date('Y-m-d H:i', $t);
        }
        return $out;
    }

    /** "Tue 18 Nov, 10:00 am to 11:00 am; Thu 20 Nov, 4:00 pm to 5:00 pm": each day's first start to last end. Pure. */
    public static function timesSummary(array $times, int $minutes): string
    {
        $days = [];
        foreach ($times as $t) {
            $days[substr($t, 0, 10)][] = strtotime($t);
        }
        $out = [];
        foreach ($days as $d => $ts) {
            $out[] = date('D j M', strtotime($d)) . ', ' . date('g:i a', min($ts)) . ' to ' . date('g:i a', max($ts) + $minutes * 60);
        }
        return implode('; ', $out);
    }

    /**
     * Several days at once (16 Nov 2026, Benjamin: "1 hour each on 2 days"): date[], from[] and to[] rows (or the old
     * single date/from/to), each a window split by the interview length. Blank rows are skipped; times sorted, no
     * repeats, at most MAX_SLOTS per day. Pure: tested directly. @return list<string> 'Y-m-d H:i'
     */
    public static function daysTimes(array $post, int $minutes): array
    {
        $dates = (array) ($post['date'] ?? []);
        $froms = (array) ($post['from'] ?? []);
        $tos = (array) ($post['to'] ?? []);
        $all = [];
        foreach ($dates as $i => $d) {
            $all = array_merge($all, self::windowTimes(trim((string) $d), trim((string) ($froms[$i] ?? '')), trim((string) ($tos[$i] ?? '')), $minutes));
        }
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }

    /**
     * The client offers a window of time and picks the interview length. Returns [times added, problem or ''].
     * The owners are told; they invite the shortlisted candidates to pick, as before.
     */
    public function offerTimes(array $contact, int $roleId, array $post): array
    {
        $role = $this->role($contact, $roleId);
        if (!$role || $role['status'] !== 'open') {
            return [0, 'This position is not open.'];
        }
        $len = (int) ($post['length'] ?? 30);
        $len = in_array($len, self::LENGTHS, true) ? $len : 30;
        $times = self::daysTimes($post, $len);
        if (!$times) {
            return [0, 'Please give at least one date, a start time and a later end time. Each window must be at least as long as one interview.'];
        }
        $n = (new Interviews($this->app))->addSlots($roleId, max(1, min(9, (int) ($post['round'] ?? 1))), (string) ($post['kind'] ?? 'virtual'), $times, $len, 'client');
        if (!$n) {
            return [0, 'Those times are in the past, less than 30 minutes away, or already offered.'];
        }
        Audit::log('client_slots_added', 'role', $roleId, ['contact' => (int) $contact['id'], 'count' => $n]);
        $when = self::timesSummary($times, $len);
        $this->tellOwners("{$contact['client']} offered interview times for {$role['title']}",
            "{$contact['name']} offered $n interview time" . ($n === 1 ? '' : 's') . " of $len minutes in the client portal: $when.", [], "/roles/$roleId#interviews",
            'Open the role and invite the shortlisted candidates to choose a time.');
        return [$n, ''];
    }

    /** Times offered and still free, and interviews booked, for a position. */
    public function schedule(array $contact, int $roleId): array
    {
        $db = $this->app->db;
        if (!$this->role($contact, $roleId)) {
            return ['free' => [], 'booked' => []];
        }
        return [
            'free' => $db->all('SELECT id, round, kind, starts_at, duration_min, source FROM interview_slots WHERE role_id = ? AND interview_id IS NULL AND starts_at > NOW() ORDER BY starts_at', [$roleId]),
            'booked' => $db->all("SELECT i.id, i.round, i.kind, i.starts_at, i.duration_min, c.full_name FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id
                WHERE a.role_id = ? AND i.status = 'booked' AND i.starts_at > NOW() - INTERVAL 1 DAY ORDER BY i.starts_at", [$roleId]),
        ];
    }

    /** The client withdraws a time they offered that nobody has taken. */
    public function removeSlot(array $contact, int $slotId): ?int
    {
        $db = $this->app->db;
        $s = $db->one("SELECT sl.id, sl.role_id FROM interview_slots sl JOIN roles r ON r.id = sl.role_id WHERE sl.id = ? AND r.client_id = ? AND sl.source = 'client' AND sl.interview_id IS NULL", [$slotId, $contact['client_id']]);
        if (!$s) {
            return null;
        }
        $db->run('DELETE FROM interview_slots WHERE id = ? AND interview_id IS NULL', [$slotId]);
        return (int) $s['role_id'];
    }

    // ---------------------------------------------------------------- asking for a position

    /**
     * The client asks for a new position: saved as a draft for the owner to check and open.
     * Returns the role id, or a message for the person.
     */
    public function requestPosition(array $contact, array $post): int|string
    {
        $db = $this->app->db;
        $s = fn (string $k, int $max) => mb_substr(trim((string) ($post[$k] ?? '')), 0, $max);
        $num = fn (string $k) => is_numeric($post[$k] ?? null) && (float) $post[$k] >= 0 ? (float) $post[$k] : null;
        $title = $s('title', 160);
        if ($title === '') {
            return 'Please give the job title.';
        }
        if ((int) $db->col('SELECT COUNT(*) FROM roles WHERE client_id = ? AND requested_at > NOW() - INTERVAL 1 DAY', [$contact['client_id']]) >= 10) {
            return 'That is a lot of new positions in one day. Please email us the rest.';
        }
        $min = $num('exp_min');
        $max = $num('exp_max');
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        $mode = isset(WORK_MODES[$post['work_mode'] ?? '']) ? $post['work_mode'] : null;
        $id = $db->tx(function () use ($db, $contact, $s, $num, $title, $min, $max, $mode) {
            $id = $db->insert('roles', [
                'client_id' => $contact['client_id'], 'title' => $title, 'location' => $s('location', 160) ?: null, 'work_mode' => $mode,
                'positions' => max(1, min(99, (int) ($num('positions') ?? 1))), 'exp_min' => $min, 'exp_max' => $max,
                'exp_allow_below' => (float) $this->app->setting('exp_allow_below_default', '1'), 'exp_allow_above' => (float) $this->app->setting('exp_allow_above_default', '5'),
                'ctc_max_lpa' => ($b = $num('ctc_max_lpa')) !== null && $b <= 500 ? $b : null, 'notice_max_days' => ($n = $num('notice_max_days')) !== null ? min(365, (int) $n) : null,
                'status' => 'draft', 'jd_text' => $s('jd_text', 60000) ?: null, 'score_threshold' => 50, 'accepts_routed' => 1,
                'location_scope' => FunctionRouting::defaultScope($min, $max, $mode),
                'requested_by_contact_id' => $contact['id'], 'requested_at' => date('Y-m-d H:i:s'), 'request_note' => $s('note', 2000) ?: null,
            ]);
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $contact['client']), 0, 3)) ?: 'ROL';
            $db->update('roles', ['code' => sprintf('%s-%04d', $prefix, $id)], $id);
            return $id;
        });
        Audit::log('portal_position_requested', 'role', $id, ['contact' => (int) $contact['id']]);
        $facts = array_filter([
            ['Location', $s('location', 160) . ($mode ? ' (' . WORK_MODES[$mode] . ')' : '')], ['Experience', $min !== null || $max !== null ? ($min ?? '?') . '–' . ($max ?? '?') . ' years' : ''],
            ['Budget', $num('ctc_max_lpa') !== null ? 'up to ' . lpa($num('ctc_max_lpa')) : ''], ['Positions', (string) max(1, (int) ($num('positions') ?? 1))], ['Note', $s('note', 2000)],
        ], fn ($f) => trim((string) $f[1]) !== '');
        $this->tellOwners("{$contact['client']} asked for a new position: $title", "{$contact['name']} asked for this position in the client portal. It is saved as a draft: check it, add the must-haves, and open it.",
            array_map(fn ($f) => [$f[0], $f[1], null], $facts), "/roles/$id/edit", '');
        return $id;
    }

    /** Positions clients asked for that are still drafts (for My day). */
    public function pendingRequests(): array
    {
        return $this->app->db->all("SELECT r.id, r.title, r.requested_at, c.name AS client, cc.name AS contact FROM roles r JOIN clients c ON c.id = r.client_id
            LEFT JOIN client_contacts cc ON cc.id = r.requested_by_contact_id WHERE r.status = 'draft' AND r.requested_at IS NOT NULL ORDER BY r.requested_at");
    }

    // ---------------------------------------------------------------- emails

    public function url(string $path = ''): string
    {
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/portal' . $path;
    }

    /** The invitation, or a sign-in link asked for on the sign-in page. */
    /** The sign-in code email: the code large, so it can be read at a glance. */
    private function emailCode(array $c, string $code): void
    {
        $u = $this->app->db->one("SELECT name, title, mobile, email, role FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: ['name' => 'Eshcol Global'];
        $first = trim((string) strtok((string) $c['name'], ' '));
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $font = 'font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#222222;';
        $intro = 'Here is your code to sign in to the Eshcol Global client portal for ' . $c['client'] . '. It works once, for the next ' . self::CODE_MINUTES . ' minutes.';
        $small = 'If you did not ask for this, you can ignore this email: nobody can sign in without the code. Please do not share it.';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="' . $font . ' max-width:600px;">'
            . '<p style="margin:0 0 14px 0;">' . ($first !== '' ? $esc($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 14px 0;">' . $esc($intro) . '</p>'
            . '<p style="margin:8px 0 20px 0;"><span style="display:inline-block; padding:12px 22px; background:#F2ECFB; border:1px solid #C8B6E6; border-radius:8px; color:#320A67; font-family:Consolas, Courier New, monospace; font-size:30px; line-height:36px; font-weight:bold; letter-spacing:8px;">' . $esc($code) . '</span></p>'
            . signature_html($u)
            . '<p style="margin:22px 0 0 0; color:#8A8396; font-size:12px; line-height:17px;">' . $esc($small) . '</p></div></body></html>';
        $text = ($first !== '' ? "$first," : 'Hello,') . "\n\n$intro\n\nYour code: $code\n\nRegards,\n" . implode("\n", signature_lines($u)) . "\n\n$small\n";
        $mailer = new Mailer($this->app);
        $id = $mailer->enqueue(['kind' => 'portal', 'to_email' => $c['email'], 'to_name' => $c['name'], 'subject' => "Your sign-in code: $code", 'html' => $html, 'text' => $text,
            'priority' => 1, 'created_by' => null]);
        // The code goes now, not in the next five-minute run.
        $row = $this->app->db->one('SELECT * FROM email_queue WHERE id = ?', [$id]);
        if ($row) {
            $mailer->sendRow($row);
        }
    }

    private function emailLink(array $c, string $token, string $purpose, ?int $userId): void
    {
        $db = $this->app->db;
        $link = $this->url('/in/' . $token);
        $u = ($userId ? $db->one('SELECT name, title, mobile, email, role FROM users WHERE id = ?', [$userId]) : null)
            ?: ($db->one("SELECT name, title, mobile, email, role FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: ['name' => 'Eshcol Global']);
        $first = trim((string) strtok((string) $c['name'], ' '));
        if ($purpose === 'invite') {
            $subject = 'Your Eshcol Global client portal';
            $paras = ["We have set up a client portal for {$c['client']}. In one place you can see every candidate we send you for each position, give your decision and comments, offer interview times, and ask us for new positions.",
                'There is no password to remember. Press the button below to sign in; it works for ' . self::INVITE_DAYS . ' days. After that, enter your email address at ' . $this->url() . ' and we will email you a 6-digit code to sign in with. You stay signed in for ' . self::SESSION_DAYS . ' days on your own computer or phone.'];
            $small = 'This link is personal to you: please do not forward it. Candidate profiles in the portal are shared in confidence.';
        } else {
            $subject = 'Your sign-in link for the Eshcol Global client portal';
            $paras = ['Here is the sign-in link you asked for. It works once, for the next ' . self::LINK_MINUTES . ' minutes.'];
            $small = 'If you did not ask for this, you can ignore this email: nobody can sign in without it.';
        }
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $font = 'font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#222222;';
        $sig = signature_lines($u);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="' . $font . ' max-width:600px;">'
            . '<p style="margin:0 0 14px 0;">' . ($first !== '' ? $esc($first) . ',' : 'Hello,') . '</p>'
            . implode('', array_map(fn ($p) => '<p style="margin:0 0 14px 0;">' . $esc($p) . '</p>', $paras))
            . '<p style="margin:6px 0 18px 0;"><a href="' . $esc($link) . '" style="display:inline-block; padding:11px 20px; background:#5B2C93; color:#FFFFFF; text-decoration:none; border-radius:6px; font-weight:bold; font-size:15px; font-family:Arial, Helvetica, sans-serif;">Open the client portal</a></p>'
            . signature_html($u)
            . '<p style="margin:22px 0 0 0; color:#8A8396; font-size:12px; line-height:17px;">' . $esc($small) . '</p></div></body></html>';
        $text = ($first !== '' ? "$first," : 'Hello,') . "\n\n" . implode("\n\n", $paras) . "\n\nOpen the client portal: $link\n\nRegards,\n" . implode("\n", $sig) . "\n\n$small\n";
        (new Mailer($this->app))->enqueue(['kind' => 'portal', 'to_email' => $c['email'], 'to_name' => $c['name'], 'subject' => $subject, 'html' => $html, 'text' => $text,
            'priority' => 3, 'created_by' => $userId]);
    }

    /**
     * An email to the owners about something a client did in the portal.
     * @param list<array{0:string, 1:string, 2:?string}> $lines [label, text, path to link the label to]
     */
    private function tellOwners(string $subject, string $intro, array $lines, string $path, string $foot): void
    {
        $url = rtrim((string) ($this->app->config['app']['url'] ?? ''), '/');
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $li = $tl = '';
        foreach ($lines as [$label, $text, $link]) {
            $li .= '<li style="margin-bottom:6px;">' . ($link ? '<a href="' . $esc($url . $link) . '" style="color:#7946B2;">' . $esc($label) . '</a>' : '<b>' . $esc($label) . '</b>') . ': ' . $esc($text) . '</li>';
            $tl .= "- $label: $text\n";
        }
        $html = '<div style="font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#2A2238; max-width:600px;">'
            . '<p style="color:#320A67; font-family:Georgia, serif; font-size:20px;">' . $esc($subject) . '</p><p>' . $esc($intro) . '</p>' . ($li ? "<ul>$li</ul>" : '')
            . '<p><a href="' . $esc($url . $path) . '" style="color:#7946B2;">Open it in Eshcol Recruit</a></p>'
            . ($foot !== '' ? '<p style="font-size:12px; color:#6E6780;">' . $esc($foot) . '</p>' : '') . '</div>';
        $text = "$subject\n\n$intro\n$tl\nOpen it: $url$path\n" . ($foot !== '' ? "\n$foot\n" : '');
        $m = new Mailer($this->app);
        foreach ($this->app->db->all("SELECT email, name FROM users WHERE is_active = 1 AND role = 'owner'") as $u) {
            $m->enqueue(['kind' => 'client_reply', 'to_email' => $u['email'], 'to_name' => $u['name'], 'subject' => $subject, 'html' => $html, 'text' => $text, 'priority' => 15]);
        }
    }

    private function contactRow(int $id): ?array
    {
        return $this->app->db->one('SELECT cc.*, cl.name AS client FROM client_contacts cc JOIN clients cl ON cl.id = cc.client_id WHERE cc.id = ?', [$id]);
    }
}
