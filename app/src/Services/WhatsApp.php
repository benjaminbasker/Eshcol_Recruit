<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * WhatsApp click-to-chat (design doc §2.5): the app writes the message, WhatsApp opens with it
 * typed, and you press send yourself. Free: no WhatsApp Business API, nothing sent by the app.
 */
final class WhatsApp
{
    public function __construct(private readonly App $app)
    {
    }

    /** The number in international form for wa.me (Indian mobiles get 91), or null. */
    public static function number(array $c): ?string
    {
        if (!empty($c['phone_norm']) && preg_match('/^[6-9]\d{9}$/', $c['phone_norm'])) {
            return '91' . $c['phone_norm'];
        }
        $d = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
        // Other countries only when written with their code (e.g. +971 for the Gulf).
        return str_starts_with(trim((string) ($c['phone'] ?? '')), '+') && strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
    }

    /**
     * Ready-written messages for a candidate, optionally about one of their applications.
     * @return array<string, array{label:string, text:string}>
     */
    public function messages(array $c, ?array $application, string $senderName): array
    {
        $first = trim((string) ($c['first_name'] ?: strtok((string) $c['full_name'], ' ')));
        $first = $first !== '' ? mb_convert_case(mb_strtolower($first), MB_CASE_TITLE) : '';
        $me = trim((string) strtok($senderName, ' ')) ?: 'Benjamin';
        $hello = 'Hello' . ($first !== '' ? " $first" : '') . ", this is $me from Eshcol Global";
        $out = [];
        if ($application) {
            $role = $this->app->db->one('SELECT title, location FROM roles WHERE id = ?', [$application['role_id']]);
            $about = 'the ' . $role['title'] . ' role' . (trim((string) $role['location']) !== '' ? ' in ' . trim($role['location']) : '');
            $q = $this->app->db->one("SELECT id FROM questionnaires WHERE application_id = ? AND status IN ('sent','opened') ORDER BY id DESC LIMIT 1", [$application['id']]);
            if ($q) {
                $link = (new Questionnaires($this->app))->link((int) $q['id']);
                $out['questions'] = ['label' => 'Ask them to answer the screening questions',
                    'text' => "$hello, about your application for $about. Could you answer a few quick questions? It takes about two minutes, and your answers are critical for us to take a decision on your candidature: $link"];
            }
            $iv = $this->app->db->one("SELECT * FROM interviews WHERE application_id = ? AND status IN ('choosing','booked') ORDER BY id DESC LIMIT 1", [$application['id']]);
            if ($iv && $iv['status'] === 'choosing') {
                $out['interview_choose'] = ['label' => 'Ask them to choose an interview time',
                    'text' => "$hello. Good news: the client would like to interview you for $about (round {$iv['round']}). Please choose a time that suits you here: " . (new Interviews($this->app))->link((int) $iv['id'])];
            }
            if ($iv && $iv['status'] === 'booked' && strtotime($iv['starts_at']) > time()) {
                $at = date('l j F \\a\\t g:i A', strtotime($iv['starts_at']));
                $how = $iv['kind'] === 'virtual' ? ' Join here: ' . $iv['meeting_link'] : ($iv['kind'] === 'in_person' ? ' Address: ' . one_line($iv['address']) . ($iv['maps_url'] ? ' Map: ' . $iv['maps_url'] : '') : '');
                $out['interview_reminder'] = ['label' => 'Remind them about the interview', 'text' => "$hello. A reminder about your interview for $about on $at.$how All the best!"];
            }
            $out['call'] = ['label' => 'Ask for a good time to call',
                'text' => "$hello. I would like to talk to you about $about. When would be a good time for a quick call, today or tomorrow?"];
            $out['interest'] = ['label' => 'Check they are still interested',
                'text' => "$hello. Are you still interested in $about? A quick yes or no is enough, and if you are, I will call you to talk about next steps."];
            $out['cv'] = ['label' => 'Ask for an updated CV',
                'text' => "$hello, about $about. Could you send me your updated CV, please? You can reply here or email it to resume@eshcolglobal.com."];
        }
        $out['hello'] = ['label' => 'Just say hello (you type the rest)', 'text' => "$hello. "];
        return $out;
    }
}
