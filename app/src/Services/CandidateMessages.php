<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;

/**
 * Messages from candidates (7 Nov 2026): replies to our emails that reach resume@ from someone
 * we already have, and anything they write in "Anything else you would like to share?".
 * Shown on the candidate's page and the dashboard. "Please remove me" is flagged for you to
 * confirm; nothing is deleted or changed by itself.
 */
final class CandidateMessages
{
    /** "Please remove me", "delete my details", "unsubscribe" and the like. */
    private const REMOVAL = '/\b(?:remove|delete|erase)\s+(?:me\b|my\s+(?:details|data|cv|resume|profile|information|info|name|number|email))|\bunsubscribe\b|\bdo\s*n[o\']t\s+(?:keep|store|contact)\s+(?:my|me)\b/i';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Keeps a message on the candidate's record. Returns its id, or null when nothing is left
     * once the quoted earlier emails and signatures are taken off.
     */
    public function record(int $candidateId, string $body, string $source = 'email', ?string $fromEmail = null, ?string $subject = null, ?string $receivedAt = null, ?int $intakeId = null, ?string $messageRef = null): ?int
    {
        $text = self::clean($body);
        if ($text === '') {
            return null;
        }
        $removal = preg_match(self::REMOVAL, $text) ? 'asked' : 'none';
        $id = $this->app->db->insert('candidate_messages', [
            'candidate_id' => $candidateId, 'source' => $source, 'from_email' => $fromEmail ? mb_substr(mb_strtolower($fromEmail), 0, 190) : null,
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null, 'body' => $text,
            'received_at' => $receivedAt ?: date('Y-m-d H:i:s'), 'removal' => $removal, 'mail_intake_id' => $intakeId,
            'message_ref' => $messageRef !== null && $messageRef !== '' ? mb_substr($messageRef, 0, 255) : null,
        ]);
        Audit::log('candidate_message', 'candidate', $candidateId, ['source' => $source, 'removal' => $removal === 'asked']);
        return $id;
    }

    /** The new part of an email: no quoted earlier messages, no "Sent from my phone", no signature divider. */
    public static function clean(string $body): string
    {
        $t = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $body);
        if (preg_match('/<(?:html|body|div|p|br)\b/i', $t)) {
            $t = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $cuts = [
            '/^\s*On\s.{3,200}\bwrote:\s*$/mi',            // Gmail
            '/^\s*-{2,}\s*Original Message\s*-{2,}/mi',     // Outlook (old)
            '/^\s*_{8,}\s*$/m',                              // Outlook divider
            '/^\s*From:\s.+\n\s*(?:Sent|Date):\s/mi',        // Outlook header block
            '/^\s*Sent from my\b/mi',
            '/^--\s*$/m',                                    // signature divider
        ];
        foreach ($cuts as $re) {
            if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) {
                $t = substr($t, 0, $m[0][1]);
            }
        }
        $lines = array_filter(explode("\n", $t), fn ($l) => !preg_match('/^\s*>/', $l));
        $t = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+$/m', '', implode("\n", $lines))));
        return mb_substr($t, 0, 5000);
    }

    /**
     * You write to a candidate from the app (a reply to one of their messages, or a new message).
     * It goes from resume@ like a personal email: your words, your signature, their message quoted
     * underneath, threaded to theirs in their inbox. Their answer comes back through resume@.
     * @return array{ok:bool, message:string, id?:int}
     */
    /**
     * Documents you can attach to a message to this candidate: the JD of each role they are on,
     * and the company profile of each of those roles' clients. Newest file of each kind.
     * @return array<int, array{id:int, kind:string, label:string, name:string, path:string}>
     */
    public function attachableDocs(int $candidateId): array
    {
        $db = $this->app->db;
        $out = [];
        $seen = [];
        foreach ($db->all("SELECT DISTINCT r.id AS role_id, r.title, r.code, r.client_id, cl.name AS client FROM applications a
                           JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id
                           WHERE a.candidate_id = ? AND r.is_pool = 0 ORDER BY a.id DESC", [$candidateId]) as $r) {
            foreach ([['role', (int) $r['role_id'], 'jd', 'JD · ' . $r['title']], ['client', (int) $r['client_id'], 'company_profile', 'Company profile · ' . $r['client']]] as [$ot, $oid, $kind, $label]) {
                $key = "$ot:$oid:$kind";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $doc = $db->one('SELECT * FROM documents WHERE owner_type = ? AND owner_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$ot, $oid, $kind]);
                if ($doc) {
                    $att = \Eshcol\Ats\Services\Documents::attachment($doc, $kind === 'jd' ? $r['title'] : 'Company profile');
                    $out[] = ['id' => (int) $doc['id'], 'kind' => $kind, 'label' => $label, 'name' => $att['name'], 'path' => $att['path']];
                }
            }
        }
        return $out;
    }

    /** The JD (for this role) and company profile (for its client) document ids, if uploaded - to prefill a one-click message. */
    public function roleDocIds(int $roleId, int $clientId): array
    {
        $db = $this->app->db;
        $ids = [];
        if ($id = $db->col("SELECT id FROM documents WHERE owner_type = 'role' AND owner_id = ? AND kind = 'jd' ORDER BY id DESC LIMIT 1", [$roleId])) {
            $ids[] = (int) $id;
        }
        if ($id = $db->col("SELECT id FROM documents WHERE owner_type = 'client' AND owner_id = ? AND kind = 'company_profile' ORDER BY id DESC LIMIT 1", [$clientId])) {
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /**
     * A one-click nudge to someone who hasn't responded (Benjamin, 18 Nov 2026): "we have an open position,
     * here's the JD and company profile, what's your CTC and notice?" Named how they came to us, when known.
     * @return array{0:string,1:string} [subject, body]
     */
    public static function openingNudge(array $c, array $role, ?string $locality): array
    {
        $first = Questionnaires::firstName($c);
        $via = !empty($c['channel']) ? channel_name($c['channel']) : ($c['source'] ?: null);
        $where = $locality ? " in $locality" : '';
        $subject = $role['title'] . ' opening with ' . $role['client'] . ($locality ? " - $locality" : '');
        $body = "Dear $first,\n\nWe received your CV" . ($via ? " through $via" : '') . " some time ago for one of our job postings. We have an open position with our client$where for {$role['title']}.\n\n"
            . "Attaching the company profile and the JD for your review.\n\n"
            . "If you're interested, could you reply with your current CTC, expected CTC, and the notice period you'd need to join?";
        return [$subject, $body];
    }

    /**
     * @param int[] $docIds documents to attach (only those attachableDocs lists for this candidate)
     * @param array<int, array{path:string, name:string}> $files already-stored files to attach (e.g. an uploaded one-off)
     */
    public function send(int $candidateId, int $userId, string $subject, string $body, ?int $replyToId = null, array $docIds = [], array $files = []): array
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        $body = trim(str_replace("\r\n", "\n", $body));
        $subject = trim(preg_replace('/\s+/', ' ', $subject));
        if (!$c) {
            return ['ok' => false, 'message' => 'Candidate not found.'];
        }
        if (Contact::silenced($c)) {
            return ['ok' => false, 'message' => 'Not sent: they are marked do not contact, or asked us not to keep their details.'];
        }
        if ($body === '') {
            return ['ok' => false, 'message' => 'Write a message first.'];
        }
        $orig = $replyToId ? $db->one("SELECT * FROM candidate_messages WHERE id = ? AND candidate_id = ? AND direction = 'in'", [$replyToId, $candidateId]) : null;
        // Reply to the address they wrote from; otherwise the one on their record.
        $to = $orig && $orig['from_email'] ? $orig['from_email'] : (string) $c['email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Not sent: there is no email address for this candidate.'];
        }
        if ($subject === '') {
            $subject = $orig && $orig['subject'] ? (preg_match('/^\s*re\s*:/i', $orig['subject']) ? $orig['subject'] : 'Re: ' . $orig['subject']) : 'A message from Eshcol Global';
        }
        $u = $db->one('SELECT name, title, mobile, email, role FROM users WHERE id = ?', [$userId]) ?: ['name' => 'Eshcol Global'];
        $first = Questionnaires::firstName($c);
        $quote = $orig ? ['On ' . date('j M Y, g:i a', strtotime((string) $orig['received_at'])) . ', ' . ($c['full_name'] ?: $to) . ' wrote:', (string) $orig['body']] : null;
        [$html, $text] = self::personal($body, signature_lines($u), $quote, $u);
        // Attachments: the documents you ticked (only ones offered for this candidate), plus any file you added.
        $allowed = [];
        if ($docIds) {
            foreach ($this->attachableDocs($candidateId) as $d) {
                $allowed[$d['id']] = ['path' => $d['path'], 'name' => $d['name']];
            }
        }
        $attachments = array_values(array_merge(array_filter(array_map(fn ($id) => $allowed[(int) $id] ?? null, $docIds)), $files));
        $refs = $orig && $orig['message_ref'] ? ['In-Reply-To' => $orig['message_ref'], 'References' => $orig['message_ref']] : [];
        $mailer = new Mailer($this->app);
        $emailId = $mailer->enqueue([
            'kind' => 'message', 'candidate_id' => $candidateId, 'to_email' => $to, 'to_name' => $c['full_name'],
            'subject' => mb_substr($subject, 0, 255), 'html' => $html, 'text' => $text,
            'meta' => ['headers' => $refs, 'personal' => true, 'attachments' => $attachments],
            'created_by' => $userId,
        ]);
        $id = $db->insert('candidate_messages', [
            'candidate_id' => $candidateId, 'direction' => 'out', 'source' => 'email', 'from_email' => null, 'subject' => mb_substr($subject, 0, 255),
            'body' => mb_substr($body, 0, 5000), 'received_at' => date('Y-m-d H:i:s'), 'read_at' => date('Y-m-d H:i:s'),
            'email_id' => $emailId, 'sent_by' => $userId, 'reply_to_id' => $orig ? (int) $orig['id'] : null,
        ]);
        // A personal reply goes now, not in the next five-minute run.
        $row = $db->one('SELECT * FROM email_queue WHERE id = ?', [$emailId]);
        $sent = $row && $mailer->sendRow($row);
        if ($orig) {
            $db->run('UPDATE candidate_messages SET read_at = COALESCE(read_at, NOW()) WHERE candidate_id = ? AND direction = ? AND id <= ?', [$candidateId, 'in', $orig['id']]);
        }
        (new Retention($this->app))->touch($candidateId);
        Audit::log('candidate_message_sent', 'candidate', $candidateId, ['message' => $id, 'reply' => (bool) $orig, 'files' => count($attachments)]);
        $withFiles = $attachments ? ' with ' . count($attachments) . ' file' . (count($attachments) === 1 ? '' : 's') : '';
        return ['ok' => true, 'id' => $id, 'message' => $sent ? "Sent to $to$withFiles." : "Queued for $to$withFiles: it goes within 5 minutes (see Emails if it doesn't)."];
    }

    /** A personal-looking email (like a letter you would type), with signature and an optional quote. @return array{0:string,1:string} */
    public static function personal(string $body, array $sig, ?array $quote = null, ?array $user = null): array
    {
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paras = implode('', array_map(fn ($p) => '<p style="margin:0 0 14px 0;">' . nl2br($h($p)) . '</p>', preg_split("/\n{2,}/", $body)));
        // The formatted signature (17 Nov 2026); the plain lines stay in the text version.
        $sigHtml = '<div style="margin:6px 0 0 0;">' . signature_html($user) . '</div>';
        $quoteHtml = $quote ? '<div style="margin:22px 0 0 0; padding:0 0 0 12px; border-left:3px solid #D8D2E4; color:#6E6780; font-size:14px; line-height:21px;">'
            . '<p style="margin:0 0 6px 0;">' . $h($quote[0]) . '</p>' . nl2br($h($quote[1])) . '</div>' : '';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:23px; color:#222222; max-width:640px;">'
            . $paras . $sigHtml . CandidateEmail::linkedinPlain()[0] . $quoteHtml . '</div></body></html>';
        $text = $body . "\n\nRegards,\n" . implode("\n", $sig) . "\n" . CandidateEmail::linkedinPlain()[1]
            . ($quote ? "\n" . $quote[0] . "\n" . implode("\n", array_map(fn ($l) => '> ' . $l, explode("\n", $quote[1]))) . "\n" : '');
        return [$html, $text];
    }

    /** The conversation, newest first: their messages and yours, with the delivery state and any files on yours. */
    public function thread(int $candidateId, int $limit = 50): array
    {
        $rows = $this->app->db->all('SELECT m.*, e.status AS email_status, e.last_error, e.meta AS email_meta, u.name AS sent_by_name FROM candidate_messages m
            LEFT JOIN email_queue e ON e.id = m.email_id LEFT JOIN users u ON u.id = m.sent_by
            WHERE m.candidate_id = ? ORDER BY m.received_at DESC, m.id DESC LIMIT ' . (int) $limit, [$candidateId]);
        foreach ($rows as &$r) {
            $r['attachments'] = array_values(array_filter(array_map(fn ($a) => (string) ($a['name'] ?? ''),
                (array) (json_decode((string) ($r['email_meta'] ?? ''), true)['attachments'] ?? [])), fn ($n) => $n !== ''));
        }
        return $rows;
    }

    /** Latest message per candidate, unread first: the Messages page. */
    public function inbox(int $limit = 100): array
    {
        return $this->app->db->all("SELECT m.*, c.full_name, (SELECT COUNT(*) FROM candidate_messages u WHERE u.candidate_id = m.candidate_id AND u.read_at IS NULL) AS unread
            FROM candidate_messages m JOIN candidates c ON c.id = m.candidate_id
            WHERE m.id = (SELECT m2.id FROM candidate_messages m2 WHERE m2.candidate_id = m.candidate_id ORDER BY m2.received_at DESC, m2.id DESC LIMIT 1)
            ORDER BY unread > 0 DESC, m.received_at DESC LIMIT " . (int) $limit);
    }

    /** Newest first. */
    public function forCandidate(int $candidateId, int $limit = 20): array
    {
        return $this->app->db->all('SELECT * FROM candidate_messages WHERE candidate_id = ? ORDER BY received_at DESC, id DESC LIMIT ' . (int) $limit, [$candidateId]);
    }

    public function markRead(int $candidateId): void
    {
        $this->app->db->run('UPDATE candidate_messages SET read_at = NOW() WHERE candidate_id = ? AND read_at IS NULL', [$candidateId]);
    }

    /** You confirm a removal request (they then get no more emails), or say it wasn't one. */
    public function resolveRemoval(int $messageId, bool $confirm): ?int
    {
        $db = $this->app->db;
        $m = $db->one("SELECT * FROM candidate_messages WHERE id = ? AND removal = 'asked'", [$messageId]);
        if (!$m) {
            return null;
        }
        $db->tx(function () use ($db, $m, $confirm) {
            $db->run('UPDATE candidate_messages SET removal = ? WHERE id = ?', [$confirm ? 'confirmed' : 'dismissed', $m['id']]);
            if ($confirm) {
                $db->run('UPDATE candidates SET removal_requested_at = COALESCE(removal_requested_at, ?) WHERE id = ?', [$m['received_at'], $m['candidate_id']]);
            }
        });
        Audit::log($confirm ? 'removal_confirmed' : 'removal_dismissed', 'candidate', (int) $m['candidate_id'], ['message' => (int) $m['id']]);
        if ($confirm) {
            (new Consents($this->app))->record((int) $m['candidate_id'], 'withdrawn', 'removal_request');
        }
        return (int) $m['candidate_id'];
    }
}
