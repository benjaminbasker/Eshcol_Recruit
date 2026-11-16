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

    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- slots

    /**
     * Adds time slots for a role's round. $times: 'Y-m-d H:i' strings (local time).
     * @return int how many were added (past and duplicate times are skipped)
     */
    public function addSlots(int $roleId, int $round, string $kind, array $times, int $duration, string $source, ?int $shortlistId = null): int
    {
        $db = $this->app->db;
        $n = 0;
        foreach ($times as $t) {
            $ts = strtotime(str_replace('T', ' ', trim((string) $t)));
            if (!$ts || $ts < time() + 1800) {
                continue;
            }
            $at = date('Y-m-d H:i:00', $ts);
            if ($db->col('SELECT 1 FROM interview_slots WHERE role_id = ? AND round = ? AND starts_at = ?', [$roleId, $round, $at])) {
                continue;
            }
            $db->insert('interview_slots', ['role_id' => $roleId, 'round' => max(1, min(9, $round)), 'kind' => isset(self::KINDS[$kind]) ? $kind : 'virtual',
                'starts_at' => $at, 'duration_min' => max(15, min(240, $duration)), 'source' => $source === 'client' ? 'client' : 'you', 'shortlist_id' => $shortlistId]);
            $n++;
        }
        return $n;
    }

    /** Free future slots for a role's round. */
    public function openSlots(int $roleId, int $round): array
    {
        return $this->app->db->all('SELECT * FROM interview_slots WHERE role_id = ? AND round = ? AND interview_id IS NULL AND starts_at > NOW() + INTERVAL 30 MINUTE ORDER BY starts_at', [$roleId, $round]);
    }

    /** The next round for an application: one more than the last round that happened. */
    public function nextRound(int $applicationId): int
    {
        return 1 + (int) $this->app->db->col("SELECT COALESCE(MAX(round), 0) FROM interviews WHERE application_id = ? AND status IN ('booked','done')", [$applicationId]);
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
        $db->run("UPDATE interviews SET status = 'cancelled' WHERE application_id = ? AND round = ? AND status = 'choosing'", [$applicationId, $round]);
        $id = $db->insert('interviews', ['application_id' => $applicationId, 'round' => max(1, $round), 'kind' => $kind, 'status' => 'booked',
            'starts_at' => date('Y-m-d H:i:00', $ts), 'duration_min' => max(15, min(240, $duration)),
            'meeting_link' => $kind === 'virtual' ? $this->url($link) : null, 'address' => $kind === 'in_person' ? $address : null,
            'maps_url' => $kind === 'in_person' && $maps ? $this->url($maps) : null, 'created_by' => $userId]);
        $this->confirmed($id, $userId);
        return [true, 'Booked. The call letter is on its way to ' . $a['full_name'] . ', and the client has been told.', $id];
    }

    /** The candidate picks a slot from their link. Returns [ok, message]. */
    public function pick(array $iv, int $slotId): array
    {
        $db = $this->app->db;
        if ($iv['status'] !== 'choosing') {
            return [false, 'This interview is already arranged.'];
        }
        $a = $this->application((int) $iv['application_id']);
        $ok = $db->run("UPDATE interview_slots SET interview_id = ? WHERE id = ? AND role_id = ? AND round = ? AND interview_id IS NULL AND starts_at > NOW()",
            [$iv['id'], $slotId, $a['role_id'], $iv['round']])->rowCount();
        if (!$ok) {
            return [false, 'Sorry, someone has just taken that time. Please choose another.'];
        }
        $slot = $db->one('SELECT * FROM interview_slots WHERE id = ?', [$slotId]);
        $db->run("UPDATE interviews SET status = 'booked', starts_at = ?, duration_min = ?, kind = ? WHERE id = ?", [$slot['starts_at'], $slot['duration_min'], $slot['kind'], $iv['id']]);
        $this->confirmed((int) $iv['id'], (int) $iv['created_by']);
        return [true, 'Booked.'];
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
        // Tell the client.
        $contact = $db->one('SELECT name, email FROM client_contacts WHERE client_id = ? AND email IS NOT NULL ORDER BY id LIMIT 1', [$a['client_id']]);
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
        $stage = $db->one("SELECT id, sort FROM stages WHERE kind = 'active' AND is_active = 1 AND name LIKE '%interview%' ORDER BY sort LIMIT 1");
        $cur = $db->one('SELECT a.stage_id, s.sort FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$a['id']]);
        if ($stage && $cur && (int) $cur['sort'] < (int) $stage['sort']) {
            $db->update('applications', ['stage_id' => $stage['id']], (int) $a['id']);
            $db->insert('stage_history', ['application_id' => $a['id'], 'from_stage_id' => $cur['stage_id'], 'to_stage_id' => $stage['id'], 'user_id' => $userId ?: null, 'note' => 'Interview booked (' . ref_interview($id) . ')']);
        }
        (new Retention($this->app))->touch((int) $a['candidate_id']);
        Audit::log('interview_booked', 'candidate', (int) $a['candidate_id'], ['interview' => $id, 'round' => (int) $iv['round']]);
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
            'signature' => ['name' => (string) ($u['name'] ?? 'Eshcol Global'), 'lines' => $lines], 'footer_reason' => Engagement::footerReason(Engagement::of($to))]);
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
            'UID:iv-' . (int) $iv['id'] . ($forClient ? '-c' : '') . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
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

    /** Records how a round went. Selected → "Offer made"; not selected → "Not suitable". */
    public function outcome(int $id, string $outcome, string $feedback, int $userId): void
    {
        $db = $this->app->db;
        if (!isset(self::OUTCOMES[$outcome])) {
            return;
        }
        $iv = $db->one('SELECT * FROM interviews WHERE id = ?', [$id]);
        if (!$iv) {
            return;
        }
        $db->run("UPDATE interviews SET status = ?, outcome = ?, feedback = ? WHERE id = ?", [$outcome === 'no_show' ? 'no_show' : 'done', $outcome, mb_substr($feedback, 0, 4000) ?: null, $id]);
        $target = match ($outcome) {
            'selected' => $db->one("SELECT id FROM stages WHERE is_active = 1 AND name LIKE 'Offer%' ORDER BY sort LIMIT 1"),
            'not_selected' => $db->one("SELECT id FROM stages WHERE is_active = 1 AND kind = 'fail' AND name LIKE 'Not suitable%' LIMIT 1"),
            'no_show' => $db->one("SELECT id FROM stages WHERE is_active = 1 AND kind = 'fail' AND name LIKE 'Withdr%' LIMIT 1")
                ?: $db->one("SELECT id FROM stages WHERE is_active = 1 AND kind = 'fail' ORDER BY sort DESC LIMIT 1"),
            default => null,
        };
        $cur = (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$iv['application_id']]);
        if ($target && (int) $target['id'] !== $cur) {
            $db->update('applications', ['stage_id' => $target['id']], (int) $iv['application_id']);
            $db->insert('stage_history', ['application_id' => $iv['application_id'], 'from_stage_id' => $cur, 'to_stage_id' => $target['id'], 'user_id' => $userId,
                'note' => 'Round ' . $iv['round'] . ': ' . self::OUTCOMES[$outcome]]);
        }
        if ($outcome === 'no_show') {
            $a = $this->application((int) $iv['application_id']);
            $when = $iv['starts_at'] ? date('j M Y', strtotime($iv['starts_at'])) : date('j M Y');
            (new Reliability($this->app))->mark((int) $a['candidate_id'], 'no_show', 'Round ' . $iv['round'] . ', ' . $a['title'] . ' (' . $a['client_name'] . '), ' . $when, $userId);
        }
        Audit::log('interview_outcome', 'interview', $id, ['outcome' => $outcome]);
    }

    public function cancel(int $id, int $userId): void
    {
        $this->app->db->run("UPDATE interviews SET status = 'cancelled' WHERE id = ?", [$id]);
        $this->app->db->run('UPDATE interview_slots SET interview_id = NULL WHERE interview_id = ?', [$id]);
        Audit::log('interview_cancelled', 'interview', $id);
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
        return ($userId ? $db->one('SELECT name, title, mobile, email FROM users WHERE id = ?', [$userId]) : null)
            ?: ($db->one("SELECT name, title, mobile, email FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: ['name' => 'Eshcol Global', 'title' => '', 'mobile' => '', 'email' => '']);
    }

    /** Queues a plain, personal-looking email (like the letters Benjamin writes) with signature, optional button and files. */
    private function queue(string $kind, array $to, string $subject, string $body, array $u, array $meta, array $files, int $priority, ?int $userId, ?string $buttonUrl = null, ?string $buttonLabel = null): int
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sig = signature_lines($u);
        $linked = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#5B2C93;">$1</a>', nl2br($esc($body)));
        // Bullet lines ("- ...") read better as real bullets.
        $linked = preg_replace('#(^|<br />\s*)- #', '$1&bull;&nbsp;', $linked);
        $toCandidate = !empty($to['candidate_id']) && !str_starts_with($kind, 'client'); // every candidate email carries the LinkedIn button
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:23px; color:#222222; max-width:640px;">'
            . '<p style="margin:0 0 16px 0;">' . $linked . '</p>'
            . ($buttonUrl ? '<p style="margin:0 0 18px 0;"><a href="' . $esc($buttonUrl) . '" style="display:inline-block; padding:11px 20px; background:#5B2C93; color:#FFFFFF; text-decoration:none; border-radius:6px; font-weight:bold; font-family:Arial, Helvetica, sans-serif;">' . $esc((string) $buttonLabel) . '</a></p>' : '')
            . '<p style="margin:0 0 4px 0;">Regards,</p><p style="margin:0;">' . implode('<br>', array_map(fn ($l, $i) => $i === 0 ? '<b>' . $esc($l) . '</b>' : $esc($l), $sig, array_keys($sig))) . '</p>'
            . ($toCandidate ? CandidateEmail::linkedinPlain()[0] : '')
            . '</div></body></html>';
        $text = $body . "\n\nRegards,\n" . implode("\n", $sig) . "\n" . ($toCandidate ? CandidateEmail::linkedinPlain()[1] : '');
        $id = (new Mailer($this->app))->enqueue(['kind' => $kind, 'candidate_id' => $to['candidate_id'] ?? null, 'to_email' => $to['email'], 'to_name' => $to['full_name'] ?? null,
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => $meta + ['attachments' => $files], 'priority' => $priority, 'created_by' => $userId]);
        return $id;
    }
}
