<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * The key details about a candidate, the same on every page that lists people (16 Nov 2026, Benjamin): experience,
 * where they live, highest qualification, current → expected salary (and the least they'd accept), notice, whether
 * they answered our details request, and a CV button that opens the CV full screen (cv-viewer.js).
 * One query per list for everyone on it.
 */
final class KeyFacts
{
    /** Answered our details request / asked, no answer yet / declined. */
    public const RESPONDED = ['answered' => 'Responded', 'asked' => 'Details asked', 'declined' => 'Not interested'];

    public function __construct(private readonly App $app)
    {
    }

    /** @param list<int> $candidateIds @return array<int, array> candidate id => facts */
    public function forCandidates(array $candidateIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $db = $this->app->db;
        $out = [];
        foreach ($db->all("SELECT id, full_name, exp_months, exp_as_of, location, locality, qualifications, ctc_current_lpa, ctc_expected_lpa, ctc_min_lpa, notice_days, preferred_cv_id
                FROM candidates WHERE id IN ($in)") as $c) {
            $out[(int) $c['id']] = $c + ['responded' => null, 'responded_at' => null, 'cv_id' => null, 'cv_ext' => null];
        }
        // Their details request: answered beats asked; the latest date.
        foreach ($db->all("SELECT candidate_id, MAX(status = 'answered') AS answered, MAX(status IN ('sent','opened')) AS asked, MAX(status = 'declined') AS declined,
                MAX(answered_at) AS answered_at FROM questionnaires WHERE candidate_id IN ($in) GROUP BY candidate_id") as $q) {
            $cid = (int) $q['candidate_id'];
            if (isset($out[$cid])) {
                $out[$cid]['responded'] = $q['answered'] ? 'answered' : ($q['declined'] ? 'declined' : ($q['asked'] ? 'asked' : null));
                $out[$cid]['responded_at'] = $q['answered_at'];
            }
        }
        // Our messages and theirs (16 Nov 2026): an email we sent from Messages, or WhatsApp opened from their page,
        // against their emails back and their answers on the details form. The latest of each side decides the tag.
        foreach ($out as $cid => $_) {
            $out[$cid] += ['msg_out' => null, 'msg_out_via' => null, 'msg_in' => null, 'msg_unread' => 0];
        }
        foreach ($db->all("SELECT candidate_id, direction, MAX(received_at) AS at, SUM(direction = 'in' AND read_at IS NULL) AS unread
                FROM candidate_messages WHERE candidate_id IN ($in) GROUP BY candidate_id, direction") as $m) {
            $cid = (int) $m['candidate_id'];
            if (!isset($out[$cid])) {
                continue;
            }
            if ($m['direction'] === 'out') {
                $out[$cid]['msg_out'] = $m['at'];
                $out[$cid]['msg_out_via'] = 'email';
            } else {
                $out[$cid]['msg_in'] = $m['at'];
                $out[$cid]['msg_unread'] = (int) $m['unread'];
            }
        }
        foreach ($db->all("SELECT entity_id AS candidate_id, MAX(created_at) AS at FROM audit_log WHERE action = 'whatsapp_opened' AND entity_type = 'candidate' AND entity_id IN ($in) GROUP BY entity_id") as $w) {
            $cid = (int) $w['candidate_id'];
            if (isset($out[$cid]) && ($out[$cid]['msg_out'] === null || $w['at'] > $out[$cid]['msg_out'])) {
                $out[$cid]['msg_out'] = $w['at'];
                $out[$cid]['msg_out_via'] = 'whatsapp';
            }
        }
        foreach ($out as $cid => $c) {
            if ($c['responded_at'] && ($c['msg_in'] === null || $c['responded_at'] > $c['msg_in'])) {
                $out[$cid]['msg_in'] = $c['responded_at'];
            }
        }
        // Where they are on each open position (for the Candidates list).
        foreach ($out as $cid => $_) {
            $out[$cid]['positions'] = [];
        }
        foreach ($db->all("SELECT a.candidate_id, r.id AS role_id, r.title, s.name AS stage, s.kind AS stage_kind FROM applications a JOIN roles r ON r.id = a.role_id
                JOIN stages s ON s.id = a.stage_id WHERE a.candidate_id IN ($in) AND r.is_pool = 0 AND r.status IN ('open','on_hold') ORDER BY s.sort DESC, r.title") as $ap) {
            if (isset($out[(int) $ap['candidate_id']])) {
                $out[(int) $ap['candidate_id']]['positions'][] = $ap;
            }
        }
        // The CV that goes to clients: the chosen one, else the newest.
        foreach ($db->all("SELECT id, candidate_id, ext FROM cv_files WHERE candidate_id IN ($in) ORDER BY id DESC") as $f) {
            $cid = (int) $f['candidate_id'];
            if (!isset($out[$cid])) {
                continue;
            }
            $preferred = (int) ($out[$cid]['preferred_cv_id'] ?? 0);
            if ($out[$cid]['cv_id'] === null || (int) $f['id'] === $preferred) {
                $out[$cid]['cv_id'] = (int) $f['id'];
                $out[$cid]['cv_ext'] = strtolower((string) $f['ext']);
            }
        }
        return $out;
    }

    /** The colour of a stage tag, by its name and kind (16 Nov 2026). Pure. */
    public static function stageTone(string $name, string $kind): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            $kind === 'success' || str_starts_with($n, 'joined') => 'st-joined',
            $kind === 'hold' || str_starts_with($n, 'future') => 'st-future',
            str_starts_with($n, 'withdr') || str_starts_with($n, 'no response') => 'st-withdrew',
            str_starts_with($n, 'awaiting') => 'st-await',
            $kind === 'fail' || str_starts_with($n, 'not suitable') || str_starts_with($n, 'reject') => 'st-down',
            str_contains($n, 'review') || str_contains($n, 'screen') => 'st-review',
            str_starts_with($n, 'shortlist') => 'st-short',
            str_contains($n, 'submit') => 'st-sent',
            str_contains($n, 'interview') => 'st-iv',
            str_contains($n, 'offer') => 'st-offer',
            default => 'st-applied',
        };
    }

    /** A coloured stage tag, e.g. "Under review"; $role adds the position ("Accounting Associate · Under review"). */
    public static function stageChip(string $name, string $kind, ?string $role = null): string
    {
        return '<span class="stage-chip ' . self::stageTone($name, $kind) . '">' . ($role !== null ? e($role) . ' · ' : '') . e($name) . '</span>';
    }

    /**
     * Where our conversation stands (16 Nov 2026): ['wait'|'replied'|'new', label], or null when we've never written.
     * Pure: tested directly.
     */
    public static function contact(array $f): ?array
    {
        $out = $f['msg_out'] ?? null;
        $in = $f['msg_in'] ?? null;
        $d = fn (string $t): string => date('j M', strtotime($t));
        if (!empty($f['msg_unread'])) {
            return ['new', 'New reply' . ($in ? ' ' . $d($in) : '')];
        }
        if ($out !== null && ($in === null || $in < $out)) {
            return ['wait', (($f['msg_out_via'] ?? '') === 'whatsapp' ? "WhatsApp'd " : 'Messaged ') . $d($out) . ' · awaiting reply'];
        }
        if ($out !== null && $in !== null) {
            return ['replied', 'Replied ' . $d($in)];
        }
        return null;
    }

    /** The highest qualification, short: the first one listed ("CA Inter (Group 1 cleared), B.Com" -> "CA Inter (Group 1 cleared)"). Pure. */
    public static function topQualification(?string $quals): string
    {
        $q = trim((string) $quals);
        if ($q === '') {
            return '';
        }
        // Split on commas or semicolons outside brackets.
        $parts = preg_split('/[;,](?![^(]*\))/u', $q) ?: [$q];
        return trim((string) $parts[0]);
    }

    /** The CV button: opens the CV full screen (cv-viewer.js). '' when there's no CV the app can show. */
    public static function cvButton(array $f, bool $small = true): string
    {
        $ext = (string) ($f['cv_ext'] ?? '');
        if (empty($f['cv_id']) || !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx'], true)) {
            return '';
        }
        $id = (int) $f['cv_id'];
        $src = $ext === 'docx' ? "/cv/$id" : "/cv/$id?view=1";
        return '<button type="button" class="btn ghost' . ($small ? ' small' : '') . ' cv-btn" data-cv-enlarge data-src="' . e($src) . '" data-ext="' . e($ext)
            . '" data-name="' . e((string) ($f['full_name'] ?: 'Candidate')) . '" data-download="/cv/' . $id . '" aria-label="Open the CV of ' . e((string) ($f['full_name'] ?: 'this candidate')) . '">'
            . icon('file', 'sm') . 'CV</button>';
    }

    /**
     * The key details as a line of small coloured pills. $skip: parts a page already shows ('exp', 'place', 'qual',
     * 'ctc', 'notice', 'responded'). Pure apart from the helpers it formats with.
     */
    public static function line(array $f, array $skip = []): string
    {
        $p = [];
        $m = exp_now($f);
        if (!in_array('exp', $skip, true)) {
            $p[] = ['k-exp', $m === null ? 'Experience ?' : experience($m)];
        }
        if (!in_array('place', $skip, true)) {
            $place = Commute::place($f['locality'] ?? null, $f['location'] ?? null);
            $p[] = ['k-place', $place !== '' ? $place : 'Location ?'];
        }
        if (!in_array('qual', $skip, true)) {
            $q = self::topQualification($f['qualifications'] ?? null);
            $p[] = ['k-qual', $q !== '' ? $q : 'Qualification ?'];
        }
        if (!in_array('ctc', $skip, true)) {
            $cur = $f['ctc_current_lpa'] ?? null;
            $exp = $f['ctc_expected_lpa'] ?? null;
            $min = $f['ctc_min_lpa'] ?? null;
            $p[] = ['k-ctc', '₹ ' . ($cur !== null ? lpa($cur) : '?') . ' → ' . ($exp !== null ? lpa($exp) : '?') . ($min !== null ? ' (min ' . lpa($min) . ')' : '')];
        }
        if (!in_array('notice', $skip, true)) {
            $n = $f['notice_days'] ?? null;
            $p[] = ['k-notice', $n === null ? 'Notice ?' : ((int) $n === 0 ? 'Joins immediately' : 'Notice ' . (int) $n . ' days')];
        }
        if (!in_array('responded', $skip, true) && !empty($f['responded'])) {
            $p[] = ['k-resp k-resp-' . $f['responded'], self::RESPONDED[$f['responded']] . ($f['responded'] === 'answered' && !empty($f['responded_at']) ? ' ' . date('j M', strtotime((string) $f['responded_at'])) : '')];
        }
        if (!in_array('contact', $skip, true) && ($ct = self::contact($f))) {
            $p[] = ['k-msg k-msg-' . $ct[0], $ct[1]];
        }
        return '<span class="keyfacts">' . implode('', array_map(fn ($x) => '<span class="kf ' . $x[0] . '">' . e($x[1]) . '</span>', $p)) . '</span>';
    }
}
