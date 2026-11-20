<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * Client shortlists. Two ways to send, chosen per shortlist:
 *  - 'cvs' (Benjamin's usual way): an email with a table (S.No, Name, current and expected CTC,
 *    notice, remarks) and each candidate's CV, branded by Eshcol (a cover page, and our reference
 *    and contact number on every page), as links and, when small enough, attachments;
 *  - 'profiles': a profile per candidate, with their email and phone (Benjamin, 14 Nov 2026: clients always get contact details).
 * Either way the client gets a private page to mark each candidate Shortlist, Hold or Not suitable
 * and to suggest interview times. Shortlists start as drafts, so you see the exact email first.
 * What the client sees is a snapshot taken when prepared.
 */
final class Shortlists
{
    public const DECISIONS = ['shortlist' => 'Shortlist', 'hold' => 'Hold', 'reject' => 'Not suitable'];

    /** @var array<int, array{0:string, 1:int}>|null stage id => [kind, sort] */
    private static ?array $stageMap = null;
    private static ?int $shortSort = null;

    /**
     * Shortlisted (16 Nov 2026): at the Shortlisted stage or any later one (Submitted, Interview, Offer), or Joined.
     * One rule for the green band, the "Shortlisted" panel on a position and the column on Roles.
     */
    public static function isShortlisted(App $app, int $stageId): bool
    {
        self::loadStages($app);
        [$kind, $sort] = self::$stageMap[$stageId] ?? ['', 0];
        return $kind === 'success' || ($kind === 'active' && $sort >= self::$shortSort);
    }

    /** The same rule as SQL, for a stages table aliased $s. */
    public static function shortlistedSql(App $app, string $s = 's'): string
    {
        self::loadStages($app);
        return "($s.kind = 'success' OR ($s.kind = 'active' AND $s.sort >= " . (int) self::$shortSort . '))';
    }

    private static function loadStages(App $app): void
    {
        if (self::$stageMap !== null) {
            return;
        }
        self::$stageMap = [];
        foreach ($app->db->all('SELECT id, kind, sort FROM stages') as $st) {
            self::$stageMap[(int) $st['id']] = [(string) $st['kind'], (int) $st['sort']];
        }
        $short = Stages::id($app, Stages::SHORTLISTED);
        self::$shortSort = $short && isset(self::$stageMap[$short]) ? self::$stageMap[$short][1] : 30;
    }

    /** Everyone shortlisted on a position, earliest stage first (those waiting to be sent), best score first. */
    public function shortlistedOn(int $roleId): array
    {
        return $this->app->db->all('SELECT a.id, a.candidate_id, a.stage_id, c.full_name, s.name AS stage, s.kind AS stage_kind, f.score, f.band,
                (SELECT MAX(COALESCE(sl.sent_at, sl.created_at)) FROM shortlist_items i JOIN shortlists sl ON sl.id = i.shortlist_id WHERE i.application_id = a.id AND sl.status <> \'draft\') AS sent_at
            FROM applications a JOIN stages s ON s.id = a.stage_id JOIN candidates c ON c.id = a.candidate_id
            LEFT JOIN fit_assessments f ON f.application_id = a.id AND f.status = \'done\'
            WHERE a.role_id = ? AND ' . self::shortlistedSql($this->app) . ' ORDER BY s.sort, f.score DESC, c.full_name', [$roleId]);
    }

    public function __construct(private readonly App $app)
    {
    }

    /** Matched requirements are stored with the sentence each was found in; this keeps just the names. */
    private static function names(array $list): array
    {
        return array_values(array_filter(array_map(fn ($m) => is_array($m) ? (string) ($m['value'] ?? '') : (string) $m, $list)));
    }

    /** A first draft of your line about a candidate, from what the app knows. */
    public function autoSummary(int $applicationId): string
    {
        $a = $this->app->db->one('SELECT a.score_breakdown, c.* FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$applicationId]);
        $bd = json_decode((string) $a['score_breakdown'], true) ?: [];
        $must = $bd['parts']['must'] ?? null;
        $bits = [];
        $exp = exp_now($a);
        if ($exp !== null) {
            $bits[] = experience($exp) . ' of experience';
        }
        if ($a['current_title']) {
            $bits[] = 'currently ' . $a['current_title'] . ($a['current_company'] ? ' at ' . $a['current_company'] : '');
        }
        $s = $bits ? ucfirst(implode(', ', $bits)) . '.' : '';
        if ($must && !empty($must['matched'])) {
            $s .= ' Has ' . implode(', ', self::names($must['matched'])) . '.';
        }
        if ($a['notice_days'] !== null) {
            $s .= ' Can join ' . ((int) $a['notice_days'] === 0 ? 'immediately' : 'in ' . (int) $a['notice_days'] . ' days') . '.';
        }
        return trim($s);
    }

    /**
     * The distance as the client reads it: "About 7 km from your Balewadi office" (straight line), or "Near / A moderate
     * distance from / Far from your Balewadi office" when one map point is missing. Null when it can't be said. Pure.
     */
    public static function distanceLine(?array $how, ?string $officeLocality): ?string
    {
        if (!$how || !$officeLocality || in_array($how['level'] ?? '', ['other_city', 'same_city'], true) && !isset($how['km'])) {
            return null;
        }
        $where = "your $officeLocality office";
        if (isset($how['km'])) {
            return ($how['km'] < 2 ? 'Under 2 km' : 'About ' . $how['km'] . ' km') . " from $where";
        }
        return match ($how['level']) {
            'near' => "Near $where",
            'moderate' => "A moderate distance from $where",
            'far' => "Far from $where",
            default => null,
        };
    }

    /** What the client sees about a candidate: everything useful, with their email and phone; never the score or internal flags. */
    public function profile(int $applicationId, bool $showSalary): array
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.*, c.* , a.id AS app_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$applicationId]);
        $bd = json_decode((string) $a['score_breakdown'], true) ?: [];
        $matched = array_values(array_unique(array_merge(self::names($bd['parts']['must']['matched'] ?? []), self::names($bd['nice'] ?? []))));
        $skills = array_column($db->all('SELECT skill FROM candidate_skills WHERE candidate_id = ? ORDER BY skill LIMIT 20', [$a['candidate_id']]), 'skill');
        $exp = exp_now($a);
        $answers = [];
        $q = $db->one("SELECT id FROM questionnaires WHERE application_id = ? AND status = 'answered' ORDER BY id DESC LIMIT 1", [$applicationId]);
        if ($q) {
            foreach ($db->all('SELECT qkey, prompt, answer FROM questionnaire_answers WHERE questionnaire_id = ? ORDER BY sort', [$q['id']]) as $r) {
                if (str_starts_with($r['qkey'], 'ctc_') || str_starts_with($r['qkey'], 'gap_') || in_array($r['qkey'], array_merge(['notice', 'notes', 'permission'], Questionnaires::DETAIL_KEYS), true)) {
                    continue; // already in the facts above (salary only when you choose to show it)
                }
                $answers[] = ['q' => $r['prompt'], 'a' => $r['answer']];
            }
        }
        $notice = $a['notice_days'] === null ? null : ((int) $a['notice_days'] === 0 ? 'Immediately' : ((int) $a['notice_days'] >= 120 ? 'More than 12 weeks' : (int) $a['notice_days'] . ' days'));
        // How far they live from the client's office (16 Nov 2026): "About 7 km from your Balewadi office".
        $cl = $db->one('SELECT cl.interview_address, cl.maps_url FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ?', [$a['role_id']]) ?: [];
        $cm = new Commute(new Skills($db));
        $office = $cm->localityIn($cl['interview_address'] ?? null, $cl['maps_url'] ?? null);
        $how = $office ? $cm->between($a['locality'] ?? null, $a['location'] ?? null, $office) : null;
        $distance = self::distanceLine($how, $office['locality'] ?? null);
        // Career at a glance for the client's cover page (option C, 20 Nov 2026): the dated jobs, newest first, as the CV
        // reader found them (Claude's reading when there is one, else the dates in the CV text). Only facts from the CV.
        $hist = (new AiReading($this->app))->history((int) $a['candidate_id']);
        if ($hist === null) {
            $hist = CvReport::parseHistory((string) $db->col('SELECT text FROM cv_files WHERE candidate_id = ? AND text IS NOT NULL ORDER BY id DESC LIMIT 1', [$a['candidate_id']]));
        }
        $jobs = array_values(array_filter($hist, fn ($h) => empty($h['education']) && empty($h['break'])));
        usort($jobs, fn ($x, $y) => $y['start'] <=> $x['start']);
        $career = array_map(fn ($j) => ['role' => (string) $j['label'], 'when' => $j['from'] . ' – ' . $j['to']], array_slice($jobs, 0, 5));
        return array_filter([
            'ref' => ref_candidate($a['candidate_id']),
            'name' => $a['full_name'] ?: 'Candidate',
            'email' => $a['email'] ?: null,
            'phone' => $a['phone'] ?: null,
            'title' => $a['current_title'],
            'company' => $a['current_company'],
            'experience' => $exp !== null ? experience($exp) : null,
            'location' => Commute::place($a['locality'] ?? null, $a['location']) ?: null,
            'distance' => $distance,
            'qualifications' => $a['qualifications'],
            'notice' => $notice,
            'ctc_current' => $showSalary && $a['ctc_current_lpa'] !== null ? lpa($a['ctc_current_lpa']) : null,
            'ctc_expected' => $showSalary && $a['ctc_expected_lpa'] !== null ? lpa($a['ctc_expected_lpa']) : null,
            'ctc_min' => $showSalary && $a['ctc_min_lpa'] !== null ? lpa($a['ctc_min_lpa']) : null,
            'matched' => $matched,
            'skills' => array_values(array_diff($skills, $matched)),
            'answers' => $answers,
            'career' => $career,
            // Plain figures for the email table (only when salary is shown).
            'inr_current' => $showSalary && $a['ctc_current_lpa'] !== null ? inr($a['ctc_current_lpa']) : null,
            'inr_expected' => $showSalary && $a['ctc_expected_lpa'] !== null ? inr($a['ctc_expected_lpa']) : null,
            'inr_min' => $showSalary && $a['ctc_min_lpa'] !== null ? inr($a['ctc_min_lpa']) : null,
            'notice_short' => $a['notice_days'] === null ? null : ((int) $a['notice_days'] === 0 ? 'Immediate' : ((int) $a['notice_days'] >= 120 ? '12+ weeks' : (int) $a['notice_days'] . ' days')),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** A short remark for the email table, from the screening answers (you can rewrite it). */
    public function autoRemark(int $applicationId): string
    {
        return $this->autoRemarks([$applicationId])[$applicationId] ?? '';
    }

    /**
     * autoRemark() for many applications in two requests (audit P1, 16 Nov 2026), not two or three each.
     * @param list<int> $applicationIds
     * @return array<int, string>
     */
    public function autoRemarks(array $applicationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $applicationIds))));
        if (!$ids) {
            return [];
        }
        $db = $this->app->db;
        $in = implode(',', $ids);
        $bits = array_fill_keys($ids, []);
        foreach ($db->all("SELECT a.id, c.ctc_min_lpa, c.ctc_expected_lpa FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id IN ($in)") as $c) {
            if ($c['ctc_min_lpa'] !== null && (float) $c['ctc_min_lpa'] < (float) ($c['ctc_expected_lpa'] ?? 0)) {
                $bits[(int) $c['id']][] = 'Will consider ' . inr($c['ctc_min_lpa']);
            }
        }
        // The relocation answer on each application's latest answered details request.
        foreach ($db->all("SELECT q.application_id, qa.answer FROM questionnaires q
                JOIN questionnaire_answers qa ON qa.questionnaire_id = q.id AND qa.qkey = 'relocate'
                WHERE q.application_id IN ($in) AND q.id = (SELECT MAX(q2.id) FROM questionnaires q2 WHERE q2.application_id = q.application_id AND q2.status = 'answered')") as $r) {
            if ((string) $r['answer'] !== '') {
                $bits[(int) $r['application_id']][] = $r['answer'] === 'Yes' ? 'Fine with the location' : 'Location is a concern';
            }
        }
        return array_map(fn ($b) => implode('. ', $b), $bits);
    }

    /**
     * Prepares a DRAFT shortlist (nothing is sent yet). $items: [[application_id, remarks], ...]
     * @return int the shortlist id
     */
    public function create(int $roleId, array $items, string $toName, string $toEmail, string $cc, string $note, bool $showSalary, int $userId, string $mode = 'cvs'): int
    {
        $db = $this->app->db;
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $days = max(7, (int) $this->app->setting('shortlist_days', '30'));
        $pinCv = !(new Migrator($this->app))->pending(); // asked before the transaction: the check itself would end it
        return $db->tx(function () use ($db, $roleId, $items, $toName, $toEmail, $cc, $note, $showSalary, $userId, $token, $days, $mode, $pinCv) {
            $id = $db->insert('shortlists', [
                'role_id' => $roleId, 'token_hash' => hash('sha256', $token), 'token_enc' => Secrets::seal($this->app, $token, 'shortlist'),
                'to_name' => mb_substr($toName, 0, 160) ?: null, 'to_email' => mb_strtolower($toEmail), 'cc' => $cc ?: null, 'note' => $note ?: null,
                'show_salary' => $showSalary ? 1 : 0, 'mode' => $mode === 'profiles' ? 'profiles' : 'cvs', 'status' => 'draft',
                'expires_at' => date('Y-m-d H:i:s', strtotime("+$days days 23:59:59")), 'created_by' => $userId,
            ]);
            foreach (array_values($items) as $i => [$appId, $remarks]) {
                $row = ['shortlist_id' => $id, 'application_id' => (int) $appId, 'sort' => $i,
                    'summary' => mb_substr(trim((string) $remarks), 0, 2000) ?: null, 'profile' => json_encode($this->profile((int) $appId, $showSalary))];
                if ($pinCv) {
                    // The exact CV version sent, so what the client saw can always be reproduced (B13, 14 Nov 2026).
                    $row['cv_file_id'] = $this->cvFor((int) $appId);
                }
                $db->insert('shortlist_items', $row);
            }
            return $id;
        });
    }

    /** Sends a draft: the email goes out and the candidates move to "Submitted to client". Returns an error, or null. */
    public function send(int $id, int $userId): ?string
    {
        $db = $this->app->db;
        $s = $db->one('SELECT * FROM shortlists WHERE id = ?', [$id]);
        if (!$s || $s['status'] !== 'draft') {
            return 'This shortlist has already been sent.';
        }
        if ($s['mode'] === 'cvs' && $db->col('SELECT COUNT(*) FROM shortlist_items WHERE shortlist_id = ? AND branded_path IS NULL', [$id])) {
            return 'Some CVs are still being prepared. Wait until every CV shows "ready", then send.';
        }
        // Audit R1 (16 Nov 2026): build the email first; then mark sent, queue the email and move the stages together,
        // so it is either all done or none of it. Claiming the draft in the same step stops a double-click sending twice.
        try {
            $content = $this->emailContent($id);
        } catch (\Throwable $e) {
            return 'The email could not be prepared, so nothing was sent (' . mb_strimwidth($e->getMessage(), 0, 140, '…') . '). Try again.';
        }
        $sent = $db->tx(function () use ($db, $id, $userId, $content) {
            if ($db->run("UPDATE shortlists SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'draft'", [$id])->rowCount() !== 1) {
                return false;
            }
            $sid = Stages::id($this->app, Stages::SUBMITTED);
            $submitted = $sid ? $db->one('SELECT id, sort FROM stages WHERE id = ? AND is_active = 1', [$sid]) : null;
            foreach ($db->all('SELECT application_id FROM shortlist_items WHERE shortlist_id = ?', [$id]) as $it) {
                // Move them on to "Submitted to client", unless they're already further along.
                $a = $db->one('SELECT a.id, a.stage_id, s.sort FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$it['application_id']]);
                if ($submitted && $a && (int) $a['sort'] < (int) $submitted['sort']) {
                    $db->update('applications', ['stage_id' => $submitted['id']], (int) $a['id']);
                    $db->insert('stage_history', ['application_id' => $a['id'], 'from_stage_id' => $a['stage_id'], 'to_stage_id' => $submitted['id'],
                        'user_id' => $userId, 'note' => 'Sent to the client (' . ref_shortlist($id) . ')']);
                }
            }
            $this->email($id, $userId, $content);
            return true;
        });
        if (!$sent) {
            return 'This shortlist has already been sent.';
        }
        Audit::log('shortlist_sent', 'role', (int) $s['role_id'], ['shortlist' => $id]);
        return null;
    }

    public function link(int $id): string
    {
        $enc = (string) $this->app->db->col('SELECT token_enc FROM shortlists WHERE id = ?', [$id]);
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/s/' . Secrets::open($this->app, $enc, 'shortlist');
    }

    /** The sender of a shortlist (for the signature): whoever created it, else the first owner. */
    private function sender(array $s): array
    {
        $db = $this->app->db;
        return $db->one('SELECT name, title, mobile, email, role FROM users WHERE id = ?', [(int) $s['created_by']])
            ?: ($db->one("SELECT name, title, mobile, email, role FROM users WHERE role = 'owner' ORDER BY id LIMIT 1") ?: ['name' => 'Eshcol Global', 'title' => '', 'mobile' => '', 'email' => '']);
    }

    /**
     * The email exactly as the client will get it, in Benjamin's format.
     * @return array{0:string,1:string,2:string,3:array} subject, html, text, attachments
     */
    public function emailContent(int $id): array
    {
        $db = $this->app->db;
        $s = $db->one('SELECT s.*, r.title, r.location, r.code FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE s.id = ?', [$id]);
        $items = $this->items($id);
        $u = $this->sender($s);
        $link = $this->link($id);
        $n = count($items);
        $words = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
        $first = trim((string) strtok((string) $s['to_name'], ' '));
        $intro = trim((string) $s['note']) !== '' ? trim((string) $s['note'])
            : ($s['mode'] === 'cvs' ? 'I am attaching ' . ($words[$n] ?? $n) . ' profile' . ($n === 1 ? '' : 's') . " for the {$s['title']} position. Kindly review the same and let me know your feedback."
                : "Please find " . ($words[$n] ?? $n) . " candidate profile" . ($n === 1 ? '' : 's') . " for the {$s['title']} position. Kindly review the same and let me know your feedback.");
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $font = 'font-family:Calibri, Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#222222;';
        $th = 'padding:7px 10px; border:1px solid #CFC6DD; background:#F1EBF9; color:#320A67; font-weight:bold; text-align:left; font-size:14px;';
        $td = 'padding:7px 10px; border:1px solid #DDD6E8; vertical-align:top; font-size:14px;';
        $rows = '';
        $textRows = '';
        foreach ($items as $i => $it) {
            $p = $it['p'];
            $cv = "$link/cv/{$it['id']}";
            $rows .= '<tr><td style="' . $td . 'text-align:center;">' . ($i + 1) . '</td>'
                . '<td style="' . $td . '"><a href="' . $esc($cv) . '" style="color:#5B2C93; font-weight:bold;">' . $esc($p['name'] ?? 'Candidate') . '</a>'
                . '<br><span style="color:#6E6780; font-size:12px;">' . $esc($p['ref'] ?? '') . '</span></td>'
                . ($s['show_salary'] ? '<td style="' . $td . 'text-align:right;">' . $esc($p['inr_current'] ?? '–') . '</td><td style="' . $td . 'text-align:right;">' . $esc($p['inr_expected'] ?? '–') . '</td>' : '')
                . '<td style="' . $td . '">' . $esc($p['notice_short'] ?? '–') . '</td>'
                . '<td style="' . $td . '">' . nl2br($esc((string) $it['summary'])) . '</td></tr>';
            $textRows .= ($i + 1) . '. ' . ($p['name'] ?? 'Candidate') . ' (' . ($p['ref'] ?? '') . ')'
                . ($s['show_salary'] ? ' | Current CTC ' . ($p['inr_current'] ?? '–') . ' | Expected CTC ' . ($p['inr_expected'] ?? '–') : '')
                . ' | Notice ' . ($p['notice_short'] ?? '–') . ($it['summary'] ? ' | ' . preg_replace('/\s+/', ' ', $it['summary']) : '') . "\n   CV: $cv\n";
        }
        $head = '<tr><th style="' . $th . 'width:44px;">S.No</th><th style="' . $th . '">Name</th>'
            . ($s['show_salary'] ? '<th style="' . $th . '">Current CTC</th><th style="' . $th . '">Expected CTC</th>' : '')
            . '<th style="' . $th . '">Notice period</th><th style="' . $th . '">Remarks</th></tr>';
        $btn = fn ($href, $label, $bg, $fg) => '<a href="' . $esc($href) . '" style="display:inline-block; padding:10px 18px; margin:0 8px 8px 0; background:' . $bg . '; color:' . $fg . '; text-decoration:none; border-radius:6px; font-weight:bold; font-size:14px; font-family:Arial, Helvetica, sans-serif;">' . $label . '</a>';
        $sig = signature_lines($u);
        $attachments = [];
        if ($s['mode'] === 'cvs') {
            $total = array_sum(array_map(fn ($it) => (int) $it['branded_size'], $items));
            if ($total > 0 && $total <= 3 * 1048576) {
                foreach ($items as $it) {
                    if ($it['branded_path']) {
                        $attachments[] = ['path' => $it['branded_path'], 'name' => $this->cvFileName($it)];
                    }
                }
            }
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:16px; background:#FFFFFF;">'
            . '<div style="' . $font . ' max-width:760px;">'
            . '<p style="margin:0 0 14px 0;">' . ($first !== '' ? $esc($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 16px 0;">' . nl2br($esc($intro)) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:0 0 16px 0; ' . $font . '">' . $head . $rows . '</table>'
            . '<p style="margin:0 0 6px 0;">' . ($s['mode'] === 'cvs' ? ($attachments ? 'The CVs are attached, and you can also open each one from its name above.' : 'Click a name to open the CV.') : 'Click a name to open the profile.') . '</p>'
            . '<p style="margin:10px 0 18px 0;">' . $btn("$link", 'Give your feedback', '#5B2C93', '#FFFFFF')
            . ($s['mode'] === 'cvs' ? $btn("$link/zip", 'Download all CVs', '#F1EBF9', '#320A67') : $btn("$link/pdf", 'Download all profiles', '#F1EBF9', '#320A67')) . '</p>'
            . signature_html($u)
            . '<p style="margin:22px 0 0 0; color:#8A8396; font-size:12px; line-height:17px;">These profiles are shared in confidence for this role. The links work until ' . $esc(date('j F Y', strtotime($s['expires_at']))) . '.</p>'
            . '</div></body></html>';
        $text = ($first !== '' ? "$first," : 'Hello,') . "\n\n$intro\n\n$textRows\nGive your feedback: $link\n"
            . ($s['mode'] === 'cvs' ? "Download all CVs: $link/zip\n" : "Download all profiles: $link/pdf\n") . "\nRegards,\n" . implode("\n", $sig) . "\n";
        $subject = ($s['mode'] === 'cvs' ? $s['title'] . ' Profiles' : 'Candidates for ' . $s['title']) . ' (' . $s['code'] . ')';
        return [$subject, $html, $text, $attachments];
    }

    /** Queues the email to the client. */
    public function email(int $id, ?int $userId, ?array $content = null): int
    {
        $db = $this->app->db;
        $s = $db->one('SELECT * FROM shortlists WHERE id = ?', [$id]);
        [$subject, $html, $text, $attachments] = $content ?? $this->emailContent($id);
        $emailId = (new Mailer($this->app))->enqueue(['kind' => 'shortlist', 'to_email' => $s['to_email'], 'to_name' => $s['to_name'],
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => ['shortlist' => $id, 'cc' => $s['cc'], 'attachments' => $attachments],
            'priority' => 20, 'created_by' => $userId]);
        $db->run('UPDATE shortlists SET email_id = ? WHERE id = ?', [$emailId, $id]);
        return $emailId;
    }

    /** "EG-10042 Kavitha Raman - Eshcol Global.pdf" */
    public function cvFileName(array $item): string
    {
        $p = $item['p'] ?? json_decode((string) $item['profile'], true);
        return trim(preg_replace('/[^\pL\pN .()-]+/u', ' ', ($p['ref'] ?? '') . ' ' . ($p['name'] ?? 'Candidate'))) . ' - Eshcol Global.pdf';
    }

    /**
     * The CV that goes to clients for an application (B13, 15 Nov 2026): the one chosen on the candidate's CV tab,
     * else the newest. Null when they have none.
     */
    public function cvFor(int $applicationId): ?int
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.candidate_id, c.preferred_cv_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$applicationId]);
        if (!$a) {
            return null;
        }
        if ($a['preferred_cv_id'] && $db->col('SELECT 1 FROM cv_files WHERE id = ? AND candidate_id = ?', [$a['preferred_cv_id'], $a['candidate_id']])) {
            return (int) $a['preferred_cv_id'];
        }
        return (int) $db->col('SELECT id FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$a['candidate_id']]) ?: null;
    }

    /** The CV for a shortlist item: the one the shortlist was made with, else cvFor(). [absolute path, extension], or null. */
    public function cvSource(int $itemId): ?array
    {
        $item = $this->app->db->one('SELECT application_id, cv_file_id FROM shortlist_items WHERE id = ?', [$itemId]);
        $fid = $item ? ((int) $item['cv_file_id'] ?: $this->cvFor((int) $item['application_id'])) : null;
        $f = $fid ? $this->app->db->one('SELECT storage_path, ext FROM cv_files WHERE id = ?', [$fid]) : null;
        if (!$f || !is_file($p = $this->app->storage($f['storage_path']))) {
            return null;
        }
        return [$p, strtolower((string) $f['ext'])];
    }

    /** Saves a finished branded CV for an item. */
    public function storeBranded(int $itemId, string $pdf): void
    {
        $dir = $this->app->storage('branded');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $old = $this->app->db->col('SELECT branded_path FROM shortlist_items WHERE id = ?', [$itemId]);
        if ($old) {
            @unlink($this->app->storage($old));
        }
        $rel = 'branded/' . bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($this->app->storage($rel), $pdf);
        $this->app->db->run('UPDATE shortlist_items SET branded_path = ?, branded_size = ?, branded_at = NOW() WHERE id = ?', [$rel, strlen($pdf), $itemId]);
    }

    /** The branding text for an item's CV pages: [header right, footer]. */
    public function stampText(array $s, array $item): array
    {
        $u = $this->sender($s);
        $p = $item['p'] ?? json_decode((string) $item['profile'], true);
        return [
            'Ref ' . ($p['ref'] ?? '') . ' · ' . ($s['title'] ?? ''),
            'Presented by Eshcol Global · ' . $u['name'] . (!empty($u['mobile']) ? ' · ' . $u['mobile'] : '') . ' · ' . ($u['email'] ?: 'resume@eshcolglobal.com') . ' · www.eshcolglobal.com',
        ];
    }

    /** The branded cover page for one candidate, as PDF. */
    public function coverPdf(array $s, array $item): string
    {
        return $this->renderPdf($this->coverHtml($s, $item), '');
    }

    /**
     * A CV that isn't a PDF (Word, scan, text), rebuilt as a branded PDF: cover page, then the CV
     * from the HTML your browser made of the Word file, or else from the text we read from it.
     */
    public function brandedFromContent(array $s, array $item, ?string $html): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($html !== null && trim($html) !== '') {
            $body = $this->cleanHtml($html);
        } else {
            $fid = (int) ($item['cv_file_id'] ?? 0) ?: $this->cvFor((int) $item['application_id']);
            $text = (string) $this->app->db->col('SELECT text FROM cv_files WHERE id = ?', [$fid]);
            $body = '';
            foreach (preg_split("/\n\s*\n/", trim($text)) as $para) {
                $body .= '<p>' . nl2br($esc(trim($para))) . '</p>';
            }
        }
        [$head, $foot] = $this->stampText($s, $item);
        $cv = '<div class="cvpage"><div class="stamp-h">' . $esc($head) . '</div><div class="stamp-f">' . $esc($foot) . '</div><div class="cvbody">' . $body . '</div></div>';
        return $this->renderPdf($this->coverHtml($s, $item) . $cv, '');
    }

    /** Keeps the structure of a converted Word CV, drops anything active or external. */
    private function cleanHtml(string $html): string
    {
        $html = mb_substr($html, 0, 3_000_000);
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR);
        libxml_clear_errors();
        $allowed = ['div', 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'img', 'span', 'a', 'sup', 'sub'];
        $xpath = new \DOMXPath($doc);
        // Only what is inside our wrapper (loadHTML adds its own html/body around it).
        foreach (iterator_to_array($xpath->query('//*[@id="root"]//*')) as $el) {
            if (!in_array(strtolower($el->nodeName), $allowed, true)) {
                $el->parentNode?->removeChild($el);
                continue;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                $keep = ($name === 'src' && $el->nodeName === 'img' && preg_match('#^data:image/(png|jpe?g|gif);base64,#i', $attr->value))
                    || in_array($name, ['colspan', 'rowspan'], true);
                if (!$keep) {
                    $el->removeAttribute($attr->name);
                }
            }
            if ($el->nodeName === 'img' && !$el->hasAttribute('src')) {
                $el->parentNode?->removeChild($el);
            }
        }
        $root = $doc->getElementById('root');
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private function coverHtml(array $s, array $item): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $u = $this->sender($s);
        $p = $item['p'] ?? json_decode((string) $item['profile'], true);
        $logo = 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->app->root . '/templates/logo.png'));
        // Option C (Benjamin, 20 Nov 2026): a headline, the career at a glance beside the core skills, then the terms.
        // Only facts from the CV and the candidate's own answers; never a score or an internal note.
        $career = $p['career'] ?? [];
        $currentPos = trim(($p['title'] ?? '') . (isset($p['company']) ? ' at ' . $p['company'] : ''));
        $headline = implode(' · ', array_filter([$currentPos !== '' ? $currentPos : null, $p['experience'] ?? null, $p['location'] ?? null]));
        $facts = array_filter([
            'Current position' => !$career && $currentPos !== '' ? $currentPos : null,
            'Experience' => !$career ? ($p['experience'] ?? null) : null,
            'Distance' => $p['distance'] ?? null,
            'Availability' => isset($p['notice']) ? ($p['notice'] === 'Immediately' ? 'Can join immediately' : 'Notice period: ' . $p['notice']) : null,
            'Current CTC' => isset($p['inr_current']) ? '₹' . $p['inr_current'] . ' a year' : null,
            'Expected CTC' => isset($p['inr_expected']) ? '₹' . $p['inr_expected'] . ' a year' : null,
            'Lowest acceptable CTC' => isset($p['inr_min']) ? '₹' . $p['inr_min'] . ' a year' : null,
        ]);
        $rows = '';
        foreach ($facts as $k => $v) {
            $rows .= '<tr><td class="k">' . $esc($k) . '</td><td>' . $esc($v) . '</td></tr>';
        }
        $tl = '';
        foreach ($career as $j) {
            $tl .= '<tr><td class="dot">&#9679;</td><td class="job"><b>' . $esc($j['role']) . '</b><br><span>' . $esc($j['when']) . '</span></td></tr>';
        }
        $chips = '';
        foreach (array_slice($p['matched'] ?? [], 0, 8) as $m) {
            $chips .= '<span class="chip m">' . $esc($m) . '</span> ';
        }
        foreach (array_slice($p['skills'] ?? [], 0, max(0, 12 - count($p['matched'] ?? []))) as $m) {
            $chips .= '<span class="chip">' . $esc($m) . '</span> ';
        }
        $right = ($chips !== '' ? '<p class="h">Core skills</p><div>' . $chips . '</div>' : '')
            . (!empty($p['qualifications']) ? '<p class="h" style="margin-top:12px">Qualification</p><p class="v">' . $esc($p['qualifications']) . '</p>' : '');
        $glance = $tl !== ''
            ? '<table class="glance"><tr><td class="l"><p class="h">Career at a glance</p><table class="tl">' . $tl . '</table></td><td class="rr">' . $right . '</td></tr></table>'
            : ($right !== '' ? '<div class="glance1">' . $right . '</div>' : '');
        $contact = array_filter([$u['name'], $u['title'] ?? '', 'Eshcol Global Services (P) Ltd', !empty($u['mobile']) ? 'Mobile: ' . $u['mobile'] : '', $u['email'] ?? '', 'www.eshcolglobal.com']);
        return '<div class="cover"><table class="head"><tr><td><img src="' . $logo . '" style="height:58px"></td><td class="r">CANDIDATE PROFILE<br><b>' . $esc($p['ref'] ?? '') . '</b></td></tr></table>'
            . '<h1>' . $esc($p['name'] ?? 'Candidate') . '</h1><p class="sub">Presented for the ' . $esc($s['title'] ?? '') . ' position</p>'
            . ($headline !== '' ? '<p class="headline">' . $esc($headline) . '</p>' : '')
            . $glance
            . ($rows !== '' ? "<table class=\"facts\">$rows</table>" : '')
            . (trim((string) $item['summary']) !== '' ? '<div class="remarks"><span class="lbl">Our remarks</span><br>' . nl2br($esc($item['summary'])) . '</div>' : '')
            . '<div class="contact"><span class="lbl">Your contact at Eshcol Global</span><br>' . implode('<br>', array_map($esc, $contact)) . '</div>'
            . '<p class="conf">This profile is presented by Eshcol Global Services (P) Ltd. Please quote the reference above when discussing this candidate. Shared in confidence for this role.</p></div>';
    }

    private function renderPdf(string $bodyHtml, string $extraCss): string
    {
        // Inter on the cover page and stamps (Benjamin, 20 Nov 2026); the CV pages keep DejaVu, which has the bullets
        // and symbols CVs use. Inter's files live in templates/fonts; the PDF library keeps its metrics in storage.
        $fonts = str_replace('\\', '/', $this->app->root . '/templates/fonts');
        $css = '@font-face{font-family:"Inter";font-weight:normal;font-style:normal;src:url("' . $fonts . '/Inter-Regular.ttf") format("truetype")}
            @font-face{font-family:"Inter";font-weight:bold;font-style:normal;src:url("' . $fonts . '/Inter-Bold.ttf") format("truetype")}
            body{font-family:"Inter","DejaVu Sans",sans-serif;font-size:10.5px;color:#2A2238;margin:0}
            @page{margin:30px 36px 40px}
            .cover{page-break-after:always}
            .head{width:100%;border-bottom:3px solid #D8B36A;margin-bottom:18px} .head .r{text-align:right;font-size:9px;letter-spacing:1px;color:#573A73}
            h1{font-family:"Inter","DejaVu Sans",sans-serif;font-weight:bold;color:#320A67;font-size:23px;margin:6px 0 2px} .sub{color:#7946B2;margin:0 0 14px;font-size:12px}
            .facts{width:100%;border-collapse:collapse;margin-bottom:12px} .facts td{padding:6px 7px;border-bottom:1px solid #E7E2EF;vertical-align:top} .facts .k{color:#6E6780;width:36%}
            .remarks{background:#FBF5E8;border-left:3px solid #D8B36A;padding:9px 11px;margin:6px 0 12px;font-size:11px}
            .contact{background:#F1EBF9;border-left:3px solid #7946B2;padding:9px 11px;margin-top:16px;font-size:11px;line-height:1.5}
            .lbl{font-size:8.5px;letter-spacing:1px;text-transform:uppercase;color:#7E6114;font-weight:bold} .contact .lbl{color:#573A73}
            .headline{margin:-6px 0 14px;font-size:12px;color:#2A2238;font-weight:bold}
            .glance{width:100%;border-collapse:collapse;margin:0 0 12px;border:1px solid #E7E2EF} .glance td{vertical-align:top;padding:10px 12px}
            .glance .l{width:52%;border-right:1px solid #E7E2EF} .glance1{border:1px solid #E7E2EF;padding:10px 12px;margin:0 0 12px}
            .h{margin:0 0 6px;font-size:8.5px;letter-spacing:1px;text-transform:uppercase;color:#7946B2;font-weight:bold} .v{margin:0}
            .tl{border-collapse:collapse;width:100%} .tl td{padding:0 0 7px;vertical-align:top} .tl .dot{width:12px;color:#7946B2;font-size:9px;padding-top:1px} .tl .job span{color:#6E6780;font-size:9.5px}
            .chip{display:inline-block;padding:2px 7px;margin:0 2px 4px 0;border:1px solid #DCCDF0;background:#F4EFFB;color:#5B2C93;border-radius:8px;font-size:9.5px}
            .chip.m{background:#E7F5EC;border-color:#CDE8D5;color:#1F7A45}
            .small{font-size:10px} .conf{margin-top:26px;font-size:8.5px;color:#6E6780;border-top:1px solid #E7E2EF;padding-top:6px}
            .stamp-h{position:fixed;top:-20px;right:0;font-size:8px;color:#7946B2} .stamp-f{position:fixed;bottom:-26px;left:0;right:0;text-align:center;font-size:7.5px;color:#6E6780;border-top:1px solid #E7E2EF;padding-top:3px}
            .cvbody{font-family:"DejaVu Sans",sans-serif;font-size:10.5px;line-height:1.45} .cvbody img{max-width:120px} .cvbody table{border-collapse:collapse;width:100%} .cvbody td,.cvbody th{padding:3px 5px;vertical-align:top}
            .cvbody h1,.cvbody h2,.cvbody h3{font-family:"DejaVu Serif",serif;color:#320A67;font-weight:normal;margin:10px 0 4px} .cvbody h1{font-size:17px} .cvbody h2{font-size:14px} .cvbody h3{font-size:12px} .cvbody p{margin:0 0 6px}' . $extraCss;
        $fontCache = $this->app->storage('pdf-fonts');
        if (!is_dir($fontCache)) {
            @mkdir($fontCache, 0750, true);
        }
        $d = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'chroot' => $this->app->root . '/templates', 'tempDir' => $this->app->storage('uploads-tmp'), 'fontDir' => $fontCache, 'fontCache' => $fontCache]);
        $d->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $bodyHtml . '</body></html>', 'UTF-8');
        $d->setPaper('A4');
        $d->render();
        return (string) $d->output();
    }

    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $s = $this->app->db->one('SELECT s.*, r.title, r.location, r.code, r.client_id, c.name AS client, c.logo_path, c.logo_updated_at FROM shortlists s JOIN roles r ON r.id = s.role_id JOIN clients c ON c.id = r.client_id
                                  WHERE s.token_hash = ?', [hash('sha256', $token)]);
        return $s ?: null;
    }

    public function items(int $shortlistId): array
    {
        $rows = $this->app->db->all('SELECT i.*, a.candidate_id, c.email AS c_email, c.phone AS c_phone FROM shortlist_items i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id WHERE i.shortlist_id = ? ORDER BY i.sort', [$shortlistId]);
        foreach ($rows as &$r) {
            $r['p'] = self::withContacts(json_decode((string) $r['profile'], true) ?: [], $r['c_email'], $r['c_phone']);
        }
        return $rows;
    }

    /** A profile with the candidate's email and phone (filled in from their record for shortlists sent before 14 Nov 2026). */
    public static function withContacts(array $p, ?string $email, ?string $phone): array
    {
        $p['email'] ??= $email ?: null;
        $p['phone'] ??= $phone ?: null;
        return array_filter($p, fn ($v) => $v !== null && $v !== '');
    }

    public function markViewed(array $s): void
    {
        if (!$s['first_viewed_at']) {
            $this->app->db->run("UPDATE shortlists SET first_viewed_at = NOW(), status = IF(status = 'sent', 'viewed', status) WHERE id = ?", [$s['id']]);
            Audit::log('shortlist_viewed', 'role', (int) $s['role_id'], ['shortlist' => (int) $s['id']]);
        }
    }

    /** Saves the client's decisions; tells the owners. Returns how many candidates were marked. */
    public function saveFeedback(array $s, array $post): int
    {
        $db = $this->app->db;
        $marked = 0;
        $changes = [];
        foreach ($this->items((int) $s['id']) as $it) {
            $d = (string) ($post['decision'][$it['id']] ?? '');
            $comment = mb_substr(trim((string) ($post['comment'][$it['id']] ?? '')), 0, 2000);
            $d = isset(self::DECISIONS[$d]) ? $d : null;
            if ($d !== $it['decision'] || $comment !== (string) $it['comment']) {
                $db->run('UPDATE shortlist_items SET decision = ?, comment = ?, decided_at = NOW() WHERE id = ?', [$d, $comment !== '' ? $comment : null, $it['id']]);
                $changes[] = ['name' => $it['p']['name'] ?? 'Candidate', 'decision' => $d, 'comment' => $comment, 'candidate_id' => (int) $it['candidate_id']];
            }
            $marked += $d ? 1 : 0;
        }
        if ($changes) {
            $db->run("UPDATE shortlists SET status = 'answered', answered_at = NOW() WHERE id = ?", [$s['id']]);
            Audit::log('shortlist_feedback', 'role', (int) $s['role_id'], ['shortlist' => (int) $s['id'], 'changed' => count($changes)]);
            $this->notify($s, $changes);
        }
        return $marked;
    }

    /** Emails the owners (and whoever sent it) when the client responds. */
    private function notify(array $s, array $changes): void
    {
        $db = $this->app->db;
        $url = rtrim((string) ($this->app->config['app']['url'] ?? ''), '/');
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = $htmlLines = '';
        foreach ($changes as $c) {
            $label = $c['decision'] ? self::DECISIONS[$c['decision']] : 'No decision';
            $lines .= "- {$c['name']}: $label" . ($c['comment'] !== '' ? " (\"{$c['comment']}\")" : '') . "\n";
            $htmlLines .= '<li style="margin-bottom:6px;"><a href="' . $esc("$url/candidates/{$c['candidate_id']}") . '" style="color:#7946B2;">' . $esc($c['name']) . '</a>: <b>' . $esc($label) . '</b>'
                . ($c['comment'] !== '' ? '<br><span style="color:#6E6780;">&ldquo;' . $esc($c['comment']) . '&rdquo;</span>' : '') . '</li>';
        }
        $subject = "{$s['client']} replied about {$s['title']}";
        $html = '<div style="font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#2A2238; max-width:600px;">'
            . '<p style="color:#320A67; font-family:Georgia, serif; font-size:20px;">' . $esc($subject) . '</p>'
            . '<p>' . $esc((string) ($s['to_name'] ?: $s['to_email'])) . ' marked these candidates on the shortlist for <b>' . $esc($s['title']) . '</b> (' . $esc($s['code']) . '):</p>'
            . "<ul>$htmlLines</ul>"
            . '<p><a href="' . $esc("$url/shortlists/{$s['id']}") . '" style="color:#7946B2;">Open the shortlist in Eshcol Recruit</a></p>'
            . '<p style="font-size:12px; color:#6E6780;">Nobody has been moved automatically: update their stages when you are ready.</p></div>';
        $text = "$subject\n\n" . ($s['to_name'] ?: $s['to_email']) . " marked these candidates for {$s['title']} ({$s['code']}):\n$lines\nOpen the shortlist: $url/shortlists/{$s['id']}\n";
        $to = $db->all("SELECT DISTINCT email, name FROM users WHERE is_active = 1 AND (role = 'owner' OR id = ?)", [(int) $s['created_by']]);
        $m = new Mailer($this->app);
        foreach ($to as $u) {
            $m->enqueue(['kind' => 'client_reply', 'to_email' => $u['email'], 'to_name' => $u['name'], 'subject' => $subject, 'html' => $html, 'text' => $text, 'priority' => 15]);
        }
    }

    /** The profiles as one PDF, one candidate per page. */
    public function pdf(array $s, array $items): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $logo = 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->app->root . '/templates/logo.png'));
        $pages = [];
        foreach ($items as $it) {
            $p = $it['p'];
            $facts = array_filter([
                'Email' => $p['email'] ?? null, 'Phone' => $p['phone'] ?? null,
                'Current position' => trim(($p['title'] ?? '') . (isset($p['company']) ? ', ' . $p['company'] : ''), ', '),
                'Experience' => $p['experience'] ?? null, 'Location' => $p['location'] ?? null, 'Distance' => $p['distance'] ?? null, 'Qualification' => $p['qualifications'] ?? null,
                'Can join' => $p['notice'] ?? null, 'Current CTC' => $p['ctc_current'] ?? null, 'Expected CTC' => $p['ctc_expected'] ?? null,
                'Lowest acceptable CTC' => $p['ctc_min'] ?? null,
            ]);
            $rows = '';
            foreach ($facts as $k => $v) {
                $rows .= '<tr><td class="k">' . $esc($k) . '</td><td>' . $esc($v) . '</td></tr>';
            }
            $answers = '';
            foreach ($p['answers'] ?? [] as $a) {
                $answers .= '<tr><td class="k">' . $esc($a['q']) . '</td><td>' . $esc($a['a']) . '</td></tr>';
            }
            $pages[] = '<div class="page"><table class="head"><tr><td><img src="' . $logo . '" style="height:46px"></td><td class="r">Candidate profile<br><b>' . $esc($s['title']) . '</b> · ' . $esc($s['code']) . '</td></tr></table>'
                . '<h1>' . $esc($p['name'] ?? 'Candidate') . '</h1>'
                . ($it['summary'] ? '<div class="summary"><span class="lbl">Our view</span><br>' . nl2br($esc($it['summary'])) . '</div>' : '')
                . "<table class=\"facts\">$rows</table>"
                . (!empty($p['matched']) ? '<h2>Matches the requirements</h2><p>' . implode(' ', array_map(fn ($x) => '<span class="chip">' . $esc($x) . '</span>', $p['matched'])) . '</p>' : '')
                . (!empty($p['skills']) ? '<h2>Other skills</h2><p class="small">' . $esc(implode(', ', $p['skills'])) . '</p>' : '')
                . ($answers ? "<h2>Screening answers</h2><table class=\"facts\">$answers</table>" : '')
                . '<p class="foot">Presented by Eshcol Global Services (P) Ltd, Chennai. Shared in confidence for this position: please do not forward outside your organisation.</p></div>';
        }
        $css = 'body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#2A2238;margin:0}
            .page{page-break-after:always} .page:last-child{page-break-after:auto}
            .head{width:100%;border-bottom:3px solid #D8B36A;margin-bottom:14px} .head .r{text-align:right;font-size:10px;color:#573A73}
            h1{font-family:"DejaVu Serif",serif;font-weight:normal;color:#320A67;font-size:22px;margin:4px 0 10px}
            h2{font-family:"DejaVu Serif",serif;font-weight:normal;color:#7946B2;font-size:13px;margin:16px 0 6px}
            .summary{background:#FBF5E8;border-left:3px solid #D8B36A;padding:8px 10px;margin:0 0 12px;font-size:11.5px}
            .lbl{font-size:9px;letter-spacing:1px;text-transform:uppercase;color:#7E6114;font-weight:bold}
            .facts{width:100%;border-collapse:collapse} .facts td{padding:5px 6px;border-bottom:1px solid #E7E2EF;vertical-align:top} .facts .k{color:#6E6780;width:38%}
            .chip{display:inline-block;background:#F1EBF9;color:#573A73;border:1px solid #E2D7F2;border-radius:8px;padding:2px 7px;margin:0 3px 3px 0}
            .small{font-size:10px} .foot{margin-top:22px;font-size:8.5px;color:#6E6780;border-top:1px solid #E7E2EF;padding-top:6px}
            @page{margin:28px 34px}';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . implode('', $pages) . '</body></html>';
        $d = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isPhpEnabled' => false,
            'chroot' => $this->app->root . '/templates', 'tempDir' => $this->app->storage('uploads-tmp')]);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper('A4');
        $d->render();
        return (string) $d->output();
    }
}
