<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * CVs emailed to resume@ come in by themselves.
 *
 * Every few minutes the scheduled job reads new emails in the mailbox's Inbox through
 * Microsoft Graph (an app registration with Mail.ReadWrite), and imports each CV
 * attachment exactly as an upload would: duplicates recognised, scored, sent on to
 * every open role it fits, and thanked if that is switched on.
 *
 * - The role comes from the code in the subject line ("ADV-0003 Accounts Associate"),
 *   which is what the job posting asks applicants to write, or else from an open role's
 *   title in the subject (the website form writes "Job application — Accounting Manager
 *   (Pune) — name"). Neither: the CV goes into the database and the router places it.
 * - The candidate's address: the email's Reply-To (the website form puts the applicant's
 *   own address there), else the sender, never a job board's, a no-reply or our own.
 * - Nothing is moved or deleted in the mailbox. A handled email gets the Outlook
 *   category "In Eshcol Recruit", so you can see at a glance what has come in.
 * - Each email is recorded once (table mail_intake), so it is never imported twice.
 * - Emails without a CV are noted and left alone, except a message from someone we already
 *   have (a reply to our email): that is kept on their record as a message (CandidateMessages).
 */
final class MailIntake
{
    public const CATEGORY = 'In Eshcol Recruit';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const DOC_TYPES = ['pdf', 'docx', 'doc', 'rtf', 'txt'];
    private const IMG_TYPES = ['jpg', 'jpeg', 'png'];
    /** Senders whose address is never the candidate's own (job boards, no-reply). */
    private const NOT_CANDIDATE = '/(no-?reply|donotreply|mailer-daemon|postmaster)@|@(\w+\.)*(indeed|naukri|linkedin|foundit|monster|shine|apna|glassdoor|timesjobs)\./i';

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $http;
    private ?string $token = null;

    public function __construct(private readonly App $app, ?callable $http = null)
    {
        $this->http = $http ?? [$this, 'curl'];
    }

    /** ['tenant_id','client_id','client_secret','mailbox'] or null when not set up. */
    public static function config(App $app): ?array
    {
        $c = json_decode((string) Secrets::get($app, 'mail_intake'), true);
        return is_array($c) && !empty($c['tenant_id']) && !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['mailbox']) ? $c : null;
    }

    public static function enabled(App $app): bool
    {
        return self::config($app) !== null && $app->setting('mail_intake_on') === '1';
    }

    /** Proves the details work: gets a token and reads one email header. Returns a message or throws. */
    public function test(array $cfg): string
    {
        $this->token = null;
        $this->tokenFor($cfg);
        [$code, $body] = $this->graph($cfg, 'GET', '/users/' . rawurlencode($cfg['mailbox']) . '/mailFolders/inbox?$select=totalItemCount,unreadItemCount');
        if ($code !== 200) {
            throw new \RuntimeException($this->graphError($code, $body, $cfg['mailbox']));
        }
        $j = json_decode($body, true);
        return sprintf('Connected to %s: %d emails in the Inbox (%d unread).', $cfg['mailbox'], (int) ($j['totalItemCount'] ?? 0), (int) ($j['unreadItemCount'] ?? 0));
    }

    /**
     * Reads new emails and imports their CVs. Returns a one-line summary.
     * Stops early (without losing its place) when Microsoft is unreachable.
     */
    public function run(int $maxEmails = 25): string
    {
        $cfg = self::config($this->app);
        if (!$cfg || $this->app->setting('mail_intake_on') !== '1') {
            return 'skipped (CV inbox not connected)';
        }
        $db = $this->app->db;
        $since = (string) ($this->app->setting('mail_intake_since') ?: date('Y-m-d\TH:i:s\Z', time() - 7 * 86400));
        $box = '/users/' . rawurlencode($cfg['mailbox']);
        $url = $box . '/mailFolders/inbox/messages?' . http_build_query([
            '$filter' => "receivedDateTime ge $since",
            '$orderby' => 'receivedDateTime asc',
            '$top' => (string) $maxEmails,
            '$select' => 'id,internetMessageId,subject,from,replyTo,receivedDateTime,hasAttachments,categories,body',
        ], '', '&', PHP_QUERY_RFC3986);
        [$code, $body] = $this->graph($cfg, 'GET', $url, null, ['Prefer: outlook.body-content-type="text"']);
        if ($code !== 200) {
            throw new \RuntimeException($this->graphError($code, $body, $cfg['mailbox']));
        }
        $userId = (int) $db->col("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1");
        $ack = new Acknowledger($this->app);
        $sendAck = $this->app->setting('mail_intake_ack', '1') === '1' && $ack->enabled();
        $counts = ['emails' => 0, 'cvs' => 0, 'no_cv' => 0, 'failed' => 0, 'messages' => 0];
        $messages = new CandidateMessages($this->app);
        $cursor = $since;
        foreach ((array) (json_decode($body, true)['value'] ?? []) as $m) {
            $cursor = (string) ($m['receivedDateTime'] ?? $cursor);
            $key = hash('sha256', (string) ($m['internetMessageId'] ?? $m['id']));
            if ($db->col('SELECT id FROM mail_intake WHERE message_key = ?', [$key])) {
                continue;
            }
            $counts['emails']++;
            $from = (string) ($m['from']['emailAddress']['address'] ?? '');
            $replyTo = (string) ($m['replyTo'][0]['emailAddress']['address'] ?? '');
            $replyName = (string) ($m['replyTo'][0]['emailAddress']['name'] ?? '');
            $fromName = (string) ($m['from']['emailAddress']['name'] ?? '');
            $subject = (string) ($m['subject'] ?? '');
            $roleId = $this->roleFromSubject($subject);
            $row = ['message_key' => $key, 'from_email' => mb_substr($from, 0, 190), 'from_name' => mb_substr($fromName, 0, 190),
                'subject' => mb_substr($subject, 0, 255), 'role_id' => $roleId,
                'received_at' => isset($m['receivedDateTime']) ? date('Y-m-d H:i:s', strtotime($m['receivedDateTime'])) : null];

            $files = !empty($m['hasAttachments']) ? $this->attachments($cfg, $box, (string) $m['id']) : [];
            $text = (string) ($m['body']['content'] ?? '');
            if (!$files) {
                // From someone we already have? Keep it as a message on their record.
                $known = $this->knownCandidate($replyTo, $from, $cfg['mailbox']);
                if ($known) {
                    $intakeId = $db->insert('mail_intake', $row + ['outcome' => 'message', 'detail' => 'Message from ' . ref_candidate($known) . '.', 'candidate_ids' => (string) $known]);
                    if ($messages->record($known, $text, 'email', $replyTo !== '' && $this->isCandidateAddress($replyTo, $cfg['mailbox']) ? $replyTo : $from, $subject, $row['received_at'], $intakeId, (string) ($m['internetMessageId'] ?? ''))) {
                        $counts['messages']++;
                    }
                    $this->tag($cfg, $box, $m);
                    continue;
                }
                $db->insert('mail_intake', $row + ['outcome' => 'no_cv', 'detail' => 'No CV attached.']);
                $counts['no_cv']++;
                continue;
            }
            $notes = [];
            $cands = [];
            foreach ($files as [$name, $bytes]) {
                $tmp = $this->app->storage('uploads-tmp/mail-' . bin2hex(random_bytes(8)));
                file_put_contents($tmp, $bytes);
                try {
                    $r = (new CandidateImporter($this->app))->import($tmp, $name, $roleId, $userId, 'email', self::channelOf($from, $subject, $name));
                } finally {
                    @unlink($tmp);
                }
                $notes[] = "$name: " . $r['message'];
                if (!empty($r['candidate_id']) && in_array($r['outcome'], ['new', 'updated', 'duplicate_file'], true)) {
                    $cid = (int) $r['candidate_id'];
                    $cands[] = $cid;
                    $this->fillEmail($cid, $replyTo !== '' && $this->isCandidateAddress($replyTo, $cfg['mailbox']) ? $replyTo : $from, $cfg['mailbox']);
                    $this->fillName($cid, $this->nameFromEmail($subject, $replyTo, $replyName, $from, $fromName, $cfg['mailbox']));
                    if ($sendAck && $r['outcome'] !== 'duplicate_file') {
                        $a = $ack->acknowledge($cid, $roleId, $userId);
                        $notes[] = $a['message'];
                    }
                }
            }
            $ok = (bool) $cands;
            $intakeId = $db->insert('mail_intake', $row + ['outcome' => $ok ? 'imported' : 'failed', 'detail' => mb_substr(implode("\n", $notes), 0, 4000),
                'candidate_ids' => $cands ? implode(',', array_unique($cands)) : null]);
            $ok ? $counts['cvs'] += count(array_unique($cands)) : $counts['failed']++;
            // A reply to our email with an updated CV may say something too: keep the words as a message.
            if ($ok && preg_match('/^\s*(?:re|aw|fwd?)\s*:/i', $subject) && $messages->record((int) $cands[0], $text, 'email', $from, $subject, $row['received_at'], $intakeId, (string) ($m['internetMessageId'] ?? ''))) {
                $counts['messages']++;
            }
            $this->tag($cfg, $box, $m);
        }
        $this->set('mail_intake_since', $cursor);
        $this->set('mail_intake_last_ok', date('Y-m-d H:i:s'));
        if ($counts['emails']) {
            Audit::log('mail_intake', null, null, $counts);
        }
        return sprintf('%d new email(s): %d CV(s) imported, %d without a CV, %d could not be read', $counts['emails'], $counts['cvs'], $counts['no_cv'], $counts['failed'])
            . ($counts['messages'] ? ', ' . $counts['messages'] . ' message(s) from candidates' : '');
    }

    /**
     * Where an emailed CV came from: the job board that sent it, our website's form, a board or
     * "referral" named in the subject, a Naukri file name; otherwise straight to resume@.
     */
    public static function channelOf(string $from, string $subject, string $filename = ''): string
    {
        $from = mb_strtolower($from);
        foreach (['naukri' => 'naukri', 'indeed' => 'indeed', 'linkedin' => 'linkedin', 'foundit' => 'foundit', 'monster' => 'foundit', 'apna' => 'apna'] as $needle => $ch) {
            if (preg_match('/@(?:[\w-]+\.)*' . $needle . '\./', $from)) {
                return $ch;
            }
        }
        if (preg_match('/^no-?reply@eshcolglobal\.com$/', $from) || preg_match('/^\s*Job application\b/i', $subject)) {
            return 'website';
        }
        if (preg_match('/\b(naukri|linked\s?in|indeed|foundit|monster|apna|whats\s?app|referr(?:al|ed))\b/i', $subject, $m)) {
            $w = mb_strtolower(preg_replace('/\s+/', '', $m[1]));
            return match (true) {
                $w === 'monster' => 'foundit',
                str_starts_with($w, 'referr') => 'referral',
                default => $w,
            };
        }
        return CandidateImporter::channelFromFilename($filename) ?? 'email';
    }

    /** Tag a handled email in Outlook. Never fatal: the import has already happened and is recorded. */
    private function tag(array $cfg, string $box, array $m): void
    {
        $cats = array_values(array_unique(array_merge((array) ($m['categories'] ?? []), [self::CATEGORY])));
        $this->graph($cfg, 'PATCH', $box . '/messages/' . rawurlencode((string) $m['id']), json_encode(['categories' => $cats]));
    }

    /** The candidate an email comes from (Reply-To first, then the sender), when exactly one has that address. */
    private function knownCandidate(string $replyTo, string $from, string $mailbox): ?int
    {
        foreach ([$replyTo, $from] as $a) {
            if ($a === '' || !$this->isCandidateAddress($a, $mailbox)) {
                continue;
            }
            $ids = $this->app->db->all('SELECT id FROM candidates WHERE email_norm = ? LIMIT 2', [mb_strtolower($a)]);
            if (count($ids) === 1) {
                return (int) $ids[0]['id'];
            }
        }
        return null;
    }

    /** "ADV-0003 Accounts Associate" → that role's id, if it is open; else an open role whose title is in the subject. */
    public function roleFromSubject(string $subject): ?int
    {
        if (preg_match_all('/\b([A-Za-z]{2,3})\s*-\s*(\d{4})\b/', $subject, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $id = $this->app->db->col("SELECT id FROM roles WHERE code = ? AND status = 'open' AND is_pool = 0", [strtoupper($m[1]) . '-' . $m[2]]);
                if ($id) {
                    return (int) $id;
                }
            }
        }
        // By title, longest first ("Accounting Manager" before "Manager"); only when one open role has that title.
        $roles = $this->app->db->all("SELECT id, title FROM roles WHERE status = 'open' AND is_pool = 0");
        usort($roles, fn ($a, $b) => mb_strlen($b['title']) <=> mb_strlen($a['title']));
        foreach ($roles as $r) {
            $t = trim((string) $r['title']);
            if (mb_strlen($t) >= 5 && preg_match('/(?<![\pL\pN])' . preg_quote($t, '/') . '(?![\pL\pN])/iu', $subject)) {
                $same = array_filter($roles, fn ($x) => mb_strtolower(trim($x['title'])) === mb_strtolower($t));
                return count($same) === 1 ? (int) $r['id'] : null;
            }
        }
        return null;
    }

    /** CV files in the email: documents; photos only when no document came with them. */
    private function attachments(array $cfg, string $box, string $id): array
    {
        [$code, $body] = $this->graph($cfg, 'GET', $box . '/messages/' . rawurlencode($id) . '/attachments');
        if ($code !== 200) {
            throw new \RuntimeException($this->graphError($code, $body, $cfg['mailbox']));
        }
        $docs = [];
        $imgs = [];
        foreach ((array) (json_decode($body, true)['value'] ?? []) as $a) {
            if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || empty($a['contentBytes'])) {
                continue;
            }
            $name = basename(str_replace('\\', '/', (string) ($a['name'] ?? 'cv')));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $bytes = base64_decode((string) $a['contentBytes'], true);
            if ($bytes === false || strlen($bytes) > CandidateImporter::MAX_BYTES) {
                continue;
            }
            if (in_array($ext, self::DOC_TYPES, true)) {
                $docs[] = [$name, $bytes];
            } elseif (in_array($ext, self::IMG_TYPES, true) && empty($a['isInline']) && strlen($bytes) > 60000) {
                $imgs[] = [$name, $bytes]; // a photographed CV, not a logo in a signature
            }
        }
        return $docs ?: $imgs;
    }

    /**
     * The applicant's name as the email gives it: the website form's subject ends
     * "— Name"; otherwise the display name on Reply-To or From, if that is the person.
     */
    public function nameFromEmail(string $subject, string $replyTo, string $replyName, string $from, string $fromName, string $mailbox): ?string
    {
        $tries = [];
        if (preg_match('/^\s*Job application\b.*[—–-]\s*([^—–-]{3,60})\s*$/u', $subject, $m)) {
            $tries[] = $m[1];
        }
        if ($replyTo !== '' && $this->isCandidateAddress($replyTo, $mailbox)) {
            $tries[] = $replyName;
        }
        if ($this->isCandidateAddress($from, $mailbox)) {
            $tries[] = $fromName;
        }
        foreach ($tries as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', $t), " \t\"'");
            if (preg_match('/^[\p{L}][\p{L}.\']*(?:\s+[\p{L}][\p{L}.\']*){0,4}$/u', $t) && mb_strlen($t) >= 3 && FieldExtractor::looksLikeName($t)) {
                return mb_convert_case(mb_strtolower($t), MB_CASE_TITLE, 'UTF-8');
            }
        }
        return null;
    }

    /** Only when the CV gave no usable name. */
    private function fillName(int $candidateId, ?string $name): void
    {
        if ($name === null) {
            return;
        }
        $db = $this->app->db;
        $c = $db->one('SELECT full_name, field_sources FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c || trim((string) $c['full_name']) !== '') {
            return;
        }
        $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
        $src['full_name'] = $src['first_name'] = 'email';
        $db->run('UPDATE candidates SET full_name = ?, first_name = ?, field_sources = ? WHERE id = ?',
            [$name, explode(' ', $name)[0], json_encode($src), $candidateId]);
    }

    /** A person's own address: not a job board, not a no-reply, not one of ours. */
    private function isCandidateAddress(string $a, string $mailbox): bool
    {
        if (!filter_var($a, FILTER_VALIDATE_EMAIL) || preg_match(self::NOT_CANDIDATE, $a)) {
            return false;
        }
        $a = mb_strtolower($a);
        $ourDomain = substr(strrchr(mb_strtolower($mailbox), '@') ?: '', 1);
        if ($ourDomain !== '' && str_ends_with($a, '@' . $ourDomain)) {
            return false;
        }
        $own = array_map(fn ($x) => mb_strtolower(trim($x)), explode(',', (string) $this->app->setting('mail_own_addresses', '')));
        return !in_array($a, $own, true);
    }

    /** The CV had no email address: use the applicant's (Reply-To or sender), if it is a person's own. */
    private function fillEmail(int $candidateId, string $from, string $mailbox): void
    {
        if (!$this->isCandidateAddress($from, $mailbox)) {
            return;
        }
        // Audit C3 (16 Nov 2026): saved in the form used for matching too, marked as from their email (so a later CV
        // reading keeps it), and never when another candidate already has this address.
        $email = mb_strtolower(trim($from));
        $db = $this->app->db;
        if ($db->col('SELECT 1 FROM candidates WHERE email_norm = ? AND id <> ? LIMIT 1', [$email, $candidateId])) {
            return;
        }
        $db->run("UPDATE candidates SET email = ?, email_norm = ?, field_sources = JSON_SET(COALESCE(field_sources, '{}'), '$.email', 'email')
            WHERE id = ? AND (email IS NULL OR email = '')", [$email, $email, $candidateId]);
    }

    private function tokenFor(array $cfg): string
    {
        if ($this->token) {
            return $this->token;
        }
        [$code, $body] = ($this->http)('POST', 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant_id']) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
        $j = json_decode($body, true);
        if ($code !== 200 || empty($j['access_token'])) {
            $why = (string) ($j['error_description'] ?? $j['error'] ?? "HTTP $code");
            throw new \RuntimeException('Microsoft refused the sign-in details: ' . mb_substr(strtok($why, "\r\n"), 0, 200));
        }
        return $this->token = (string) $j['access_token'];
    }

    /** @return array{0:int,1:string} */
    private function graph(array $cfg, string $method, string $path, ?string $body = null, array $extra = []): array
    {
        $headers = array_merge(['Authorization: Bearer ' . $this->tokenFor($cfg), 'Accept: application/json'], $extra);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        return ($this->http)($method, self::GRAPH . $path, $headers, $body);
    }

    private function graphError(int $code, string $body, string $mailbox): string
    {
        $msg = (string) (json_decode($body, true)['error']['message'] ?? "HTTP $code");
        return match (true) {
            $code === 401 || $code === 403 => "Microsoft says this app may not read $mailbox ($msg). Check the Mail.ReadWrite application permission has admin consent, and that any access policy includes this mailbox.",
            $code === 404 => "Microsoft can't find the mailbox $mailbox. It must be a real mailbox (a shared mailbox is fine), not just an alias.",
            default => 'Microsoft Graph error: ' . mb_substr($msg, 0, 200),
        };
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException('Could not reach Microsoft: ' . $err);
        }
        return [$code, (string) $out];
    }

    private function set(string $k, string $v): void
    {
        $this->app->db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
}
