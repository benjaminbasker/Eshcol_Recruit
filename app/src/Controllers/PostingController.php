<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Http;

/**
 * Copy-ready job postings for LinkedIn, Indeed and Naukri, a LinkedIn feed post and a WhatsApp
 * message, all from the role (design doc §2.5). The app never posts anything itself: the
 * portals' posting APIs are closed to firms our size, so you paste.
 */
final class PostingController extends Controller
{
    public function show(int $roleId): never
    {
        $r = $this->db->one('SELECT r.*, c.name AS client FROM roles r JOIN clients c ON c.id = r.client_id WHERE r.id = ? AND r.is_pool = 0', [$roleId]);
        $r || Http::abort(404);
        $nameClient = ($_GET['client'] ?? '') === '1';
        $showPay = ($_GET['pay'] ?? '') === '1';
        $by = [];
        foreach ($this->db->all('SELECT kind, value FROM role_criteria WHERE role_id = ? ORDER BY sort, id', [$roleId]) as $c) {
            $by[$c['kind']][] = $c['value'];
        }
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        $loc = trim((string) $r['location']);
        $exp = $r['exp_min'] !== null || $r['exp_max'] !== null
            ? ($r['exp_max'] !== null ? $n($r['exp_min'] ?? 0) . '–' . $n($r['exp_max']) . ' years' : $n($r['exp_min']) . '+ years') : null;
        $pay = $showPay && $r['ctc_max_lpa'] !== null
            ? ($r['ctc_min_lpa'] !== null ? '₹' . $n($r['ctc_min_lpa']) . '–' . $n($r['ctc_max_lpa']) . ' lakh a year' : 'up to ₹' . $n($r['ctc_max_lpa']) . ' lakh a year') : null;
        $who = \Eshcol\Ats\Services\Engagement::postHeadline($r, $nameClient); // by type of position (15 Nov 2026)
        $code = (string) $r['code'];
        $apply = "Email your CV to resume@eshcolglobal.com with \"$code {$r['title']}\" in the subject line.";
        $jd = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string) $r['jd_text'])));

        $facts = array_filter([
            $loc !== '' ? "Location: $loc" . (isset(WORK_MODES[$r['work_mode'] ?? '']) ? ' (' . WORK_MODES[$r['work_mode']] . ')' : '') : null,
            $exp ? "Experience: $exp" : null,
            $pay ? "Salary: $pay" : null,
            $r['notice_max_days'] !== null ? ((int) $r['notice_max_days'] === 0 ? 'Joining: immediately' : 'Joining: within ' . (int) $r['notice_max_days'] . ' days') : null,
            (int) $r['positions'] > 1 ? 'Openings: ' . (int) $r['positions'] : null,
        ]);
        $list = fn (array $items) => implode("\n", array_map(fn ($i) => "• $i", $items));
        $sections = [];
        if ($facts) {
            $sections[] = $list($facts);
        }
        if (!empty($by['must'])) {
            $sections[] = "What you'll need\n" . $list($by['must']);
        }
        if (!empty($by['nice'])) {
            $sections[] = "Good to have\n" . $list($by['nice']);
        }
        if (!empty($by['qualification'])) {
            $sections[] = 'Qualification: ' . implode(' or ', $by['qualification']);
        }
        if ($jd !== '') {
            $sections[] = "About the role\n$jd";
        }
        $sections[] = "How to apply\n$apply\nSee all our openings: https://www.eshcolglobal.com/jobs/";

        $description = "$who: {$r['title']}" . ($loc !== '' ? " in $loc" : '') . ".\n\n" . implode("\n\n", $sections)
            . "\n\nEshcol Global is a recruitment and HR services firm based in Chennai. We read every application.";
        $skills = array_values(array_unique(array_merge($by['must'] ?? [], $by['nice'] ?? [])));
        $tags = array_map(fn ($t) => '#' . preg_replace('/[^A-Za-z0-9]/', '', ucwords($t)), array_slice(array_merge(['Hiring', 'Jobs'], $loc !== '' ? [$loc . ' Jobs'] : [], array_slice($skills, 0, 3)), 0, 6));
        $feed = "We're hiring: {$r['title']}" . ($loc !== '' ? " in $loc" : '') . ($exp ? " ($exp)" : '') . ".\n\n"
            . ($by['must'] ?? [] ? 'You will need: ' . implode(', ', array_slice($by['must'], 0, 5)) . ".\n" : '')
            . ($pay ? "Salary: $pay.\n" : '')
            . "\n$apply\n\nKnow someone who fits? Please share this post.\n\n" . implode(' ', array_unique($tags));
        $whatsapp = "*{$r['title']}*" . ($loc !== '' ? " · $loc" : '') . ($exp ? " · $exp" : '') . ($pay ? " · $pay" : '') . "\n"
            . ($by['must'] ?? [] ? 'Needs: ' . implode(', ', array_slice($by['must'], 0, 5)) . "\n" : '')
            . "To apply: $apply\nPlease forward to anyone who may be interested.";
        $title = $r['title'] . ($loc !== '' ? " | $loc" : '') . ($exp ? " | $exp" : '');

        $this->view('roles/post', ['title' => 'Post · ' . $r['title'], 'role' => $r, 'nameClient' => $nameClient, 'showPay' => $showPay,
            'blocks' => [
                'title' => ['Job title (all portals)', $title, 'Some portals limit the title to about 80 characters.'],
                'description' => ['Job description: LinkedIn, Indeed and Naukri', $description, 'Paste into the description box. Bullets show as plain bullets on every portal.'],
                'skills' => ['Key skills: Naukri', implode(', ', $skills), 'Naukri asks for these separately. Add or remove a few if it suggests better ones.'],
                'feed' => ['LinkedIn feed post', $feed, 'For your own or the company page feed, to spread the word.'],
                'whatsapp' => ['WhatsApp message', $whatsapp, 'For groups or broadcast lists. *Stars* make the title bold in WhatsApp.'],
            ]]);
    }
}
