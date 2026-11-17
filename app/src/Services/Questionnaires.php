<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;
use Eshcol\Ats\Audit;
use Eshcol\Ats\Secrets;

/**
 * "Complete your application" (design doc §2.6, reworked 7 Nov 2026): a personal link a candidate
 * opens on any phone, no login. Three parts: check the details we read from their CV, the details
 * we need (salary, lowest acceptable salary, joining time), and anything the role asks.
 * Their answers update their record (marked "from the candidate"), re-score them, and flag
 * anything that matters. Nobody is rejected automatically.
 *
 * One link per person ("the lead" row). It covers:
 *  - no role at all (a CV that came without a job code): application_id and role_id are NULL;
 *  - one role; or
 *  - several roles at once: the other roles are rows with parent_id = the lead, so every
 *    per-role view (pipeline, shortlist, candidate page) keeps working unchanged.
 *
 * Follow-ups: a gentle reminder 48 hours after the email, a final "Are you still interested?"
 * 48 hours after that (with a one-click "I'm no longer interested"), only between 9 am and 7 pm.
 */
final class Questionnaires
{
    /** How soon they could join, as a pull-down; stored as days. */
    public const NOTICE = ['Immediate joiner' => 0, 'Less than 1 week' => 7, '2 weeks' => 14, '3 weeks' => 21, '4 weeks' => 28,
        '6 weeks' => 42, '8 weeks' => 56, '12 weeks' => 84, 'More than 12 weeks' => 120];
    /** The questions a role can switch on or off in its screening editor. */
    public const STANDARD = ['experience', 'ctc_current', 'ctc_expected', 'ctc_min', 'notice', 'location', 'locality', 'relocate'];
    /** Asked on every role: distance to work is a common reason people drop out. */
    public const ALWAYS = ['experience', 'location', 'locality', 'relocate'];
    /** Salary and notice: asked once, not again within 30 days once the candidate has told us. */
    private const PERSONAL = ['ctc_current' => 'ctc_current_lpa', 'ctc_expected' => 'ctc_expected_lpa', 'ctc_min' => 'ctc_min_lpa', 'notice' => 'notice_days'];
    /** Part 1 "Check your details": answer key => candidate column. Email and mobile are handled apart (they wait for approval). */
    private const DETAILS = ['full_name' => 'full_name', 'linkedin' => 'linkedin_url', 'location' => 'location', 'locality' => 'locality', 'current_company' => 'current_company',
        'current_title' => 'current_title', 'experience' => 'exp_months', 'qualifications' => 'qualifications'];
    /** Part 1 keys: kept with the answers, but not shown to clients (the shortlist shows the facts instead). */
    public const DETAIL_KEYS = ['full_name', 'phone', 'email', 'linkedin', 'location', 'locality', 'current_company', 'current_title', 'experience', 'qualifications'];
    public const MAX_CUSTOM = 8;
    public const OPEN_ROLE = ['open', 'on_hold', 'draft'];

    /** @var callable|null DNS check, replaceable in tests (see Acknowledger) */
    public $dns = null;

    public function __construct(private readonly App $app)
    {
    }

    /** Which standard questions this role asks (all of them unless switched off). */
    public function standardOn(array $role): array
    {
        $s = json_decode((string) ($role['screening'] ?? ''), true);
        $chosen = is_array($s['std'] ?? null) ? $s['std'] : self::STANDARD;
        return array_values(array_intersect(self::STANDARD, array_merge($chosen, self::ALWAYS)));
    }

    /** Every question for one role (the screening editor's preview). */
    public function questions(array $role, ?array $cand = null): array
    {
        return $this->questionsFor([$role], $cand);
    }

    /**
     * The whole form for a set of roles (none = a CV with no role), in order.
     * part 1 = check your details, 2 = the details we need, 3 = about the role(s).
     * @return array<int, array{key:string, part:int, kind:string, prompt:string, required:bool, options?:array, flag?:array, hint?:string, prefill?:?string, suggest?:array, role?:string, roles?:int[]}>
     */
    public function questionsFor(array $roles, ?array $cand = null): array
    {
        $q = [];
        $exp = $cand ? exp_now($cand) : null;
        $city = trim((string) ($cand['location'] ?? ''));
        $roleCity = trim((string) ($roles[0]['location'] ?? ''));

        // Part 1: what we read from their CV, to check and correct.
        $q[] = ['key' => 'full_name', 'part' => 1, 'kind' => 'text', 'prompt' => 'Your full name', 'required' => true, 'prefill' => $cand['full_name'] ?? null];
        $q[] = ['key' => 'phone', 'part' => 1, 'kind' => 'tel', 'prompt' => 'Your mobile number', 'required' => false, 'prefill' => $cand['phone'] ?? null];
        $q[] = ['key' => 'email', 'part' => 1, 'kind' => 'email', 'prompt' => 'Your email address', 'required' => false, 'prefill' => $cand['email'] ?? null];
        $q[] = ['key' => 'linkedin', 'part' => 1, 'kind' => 'url', 'prompt' => 'Your LinkedIn profile', 'required' => false,
            'hint' => 'Optional. For example linkedin.com/in/your-name.', 'prefill' => $cand['linkedin_url'] ?? null];
        $q[] = ['key' => 'location', 'part' => 1, 'kind' => 'text', 'prompt' => 'The city you live in now', 'required' => true, 'prefill' => $city ?: null];
        $q[] = ['key' => 'locality', 'part' => 1, 'kind' => 'text', 'prompt' => 'Your area or locality', 'required' => true,
            'hint' => 'For example Wakad, Kharadi or Andheri. It helps us suggest roles that are easy for you to reach.',
            'prefill' => $cand['locality'] ?? null, 'suggest' => $this->localities($city ?: $roleCity)];
        $q[] = ['key' => 'current_company', 'part' => 1, 'kind' => 'text', 'prompt' => 'Your current (or last) employer', 'required' => false, 'prefill' => $cand['current_company'] ?? null];
        $q[] = ['key' => 'current_title', 'part' => 1, 'kind' => 'text', 'prompt' => 'Your current (or last) job title', 'required' => false, 'prefill' => $cand['current_title'] ?? null];
        $q[] = ['key' => 'experience', 'part' => 1, 'kind' => 'years', 'prompt' => 'Your total years of work experience', 'required' => false,
            'hint' => 'All your paid work, including any jobs not on your CV. For example 4.5.', 'prefill' => $exp !== null ? (string) round($exp / 12, 1) : null];
        $q[] = ['key' => 'qualifications', 'part' => 1, 'kind' => 'text', 'prompt' => 'Your qualifications', 'required' => false,
            'hint' => 'For example B.Com, M.Com, CA Inter (cleared).', 'prefill' => $cand['qualifications'] ?? null];

        // Part 2: the details we need. Salary and notice are not asked again within 30 days of them telling us.
        $on = $roles ? array_values(array_unique(array_merge(...array_map(fn ($r) => $this->standardOn($r), $roles)))) : self::STANDARD;
        if ($cand && $cand['last_confirmed_at'] && strtotime($cand['last_confirmed_at']) > time() - 30 * 86400) {
            $src = json_decode((string) ($cand['field_sources'] ?? '{}'), true) ?: [];
            $on = array_values(array_filter($on, fn ($k) => !isset(self::PERSONAL[$k]) || ($src[self::PERSONAL[$k]] ?? '') !== 'candidate'));
        }
        if (in_array('ctc_current', $on, true)) {
            $q[] = ['key' => 'ctc_current', 'part' => 2, 'kind' => 'number', 'prompt' => 'Your current salary (CTC), in lakhs per annum', 'required' => true,
                'hint' => 'For example 4.5 for ₹4,50,000 a year. Enter 0 if you are not working at present.'];
        }
        if (in_array('ctc_expected', $on, true)) {
            $q[] = ['key' => 'ctc_expected', 'part' => 2, 'kind' => 'number', 'prompt' => 'The salary (CTC) you are hoping for, in lakhs per annum', 'required' => false,
                'hint' => 'Optional.'];
        }
        if (in_array('ctc_min', $on, true)) {
            $q[] = ['key' => 'ctc_min', 'part' => 2, 'kind' => 'number', 'prompt' => 'The lowest salary (CTC) you would accept, in lakhs per annum', 'required' => true,
                'hint' => 'Please be realistic: this helps us put you forward for roles you would actually take.'];
        }
        if (in_array('notice', $on, true)) {
            $q[] = ['key' => 'notice', 'part' => 2, 'kind' => 'select', 'prompt' => 'How soon could you join?', 'required' => true,
                'hint' => 'Your notice period, or the least time you need before you can start.', 'options' => array_keys(self::NOTICE)];
        }

        // Part 3: about the role(s). Where they can work: once per place, not once per role.
        $byPlace = [];
        foreach ($roles as $r) {
            $loc = trim((string) $r['location']);
            if ($loc !== '' && ($r['work_mode'] ?? null) !== 'remote' && in_array('relocate', $this->standardOn($r), true)) {
                $byPlace[$loc . '|' . ($r['work_mode'] ?? '')][] = $r;
            }
        }
        $n = 0;
        foreach ($byPlace as $group) {
            $loc = trim((string) $group[0]['location']);
            $prompt = ($group[0]['work_mode'] ?? null) === 'hybrid'
                ? (count($roles) > 1 && count($byPlace) > 1 ? 'The ' . $group[0]['title'] . ' role is' : 'This role is') . " hybrid, based in $loc (some days in the office). Are you able to work there?"
                : (count($roles) > 1 && count($byPlace) > 1 ? 'The ' . $group[0]['title'] . ' role is' : 'This role is') . " based in $loc. Are you able to work there?";
            $q[] = ['key' => $n++ === 0 ? 'relocate' : 'relocate_' . (int) $group[0]['id'], 'part' => 3, 'kind' => 'yesno', 'prompt' => $prompt, 'required' => true,
                'options' => ['Yes', 'No'], 'flag' => ['No'], 'roles' => array_map(fn ($r) => (int) $r['id'], $group), 'place' => $loc];
        }
        // Staffing (15 Nov 2026, approved wording): is a contract role on Eshcol's payroll all right with them?
        foreach (array_values(array_filter($roles, fn ($r) => Engagement::of($r) === 'staffing')) as $i => $r) {
            $q[] = ['key' => $i === 0 ? 'contract' : 'contract_' . (int) $r['id'], 'part' => 3, 'kind' => 'choice', 'prompt' => Engagement::contractQuestion($r), 'required' => true,
                'options' => array_values(Engagement::CONTRACT_ANSWERS), 'flag' => [Engagement::CONTRACT_ANSWERS['no']], 'roles' => [(int) $r['id']],
                'role' => count($roles) > 1 ? $r['title'] : null];
        }
        // Permission to submit (B11, 14 Nov 2026): asked once, without naming the client. Not for our own team (no client).
        if ($roles && Engagement::asksPermission(Engagement::forRoles($roles))) {
            $q[] = ['key' => 'permission', 'part' => 3, 'kind' => 'yesno',
                'prompt' => 'May we put your profile forward to our client for ' . (count($roles) > 1 ? 'these roles' : 'this role') . '?',
                'required' => true, 'options' => ['Yes', 'Not yet, please ask me first'], 'flag' => ['Not yet, please ask me first'], 'roles' => null];
        }
        foreach ($roles as $r) {
            foreach ($this->app->db->all('SELECT * FROM role_questions WHERE role_id = ? ORDER BY sort, id', [$r['id']]) as $rq) {
                $q[] = ['key' => 'q' . $rq['id'], 'part' => 3, 'kind' => $rq['kind'], 'prompt' => $rq['prompt'], 'required' => true,
                    'options' => $rq['kind'] === 'yesno' ? ['Yes', 'No'] : (json_decode((string) $rq['options'], true) ?: []),
                    'flag' => json_decode((string) $rq['flag_answers'], true) ?: [], 'roles' => [(int) $r['id']], 'role' => count($roles) > 1 ? $r['title'] : null];
            }
        }
        // Gaps in the CV (B7, 15 Nov 2026): requirements the assessment found "not mentioned", at most 3 per position.
        if ($cand) {
            $gq = new GapQuestions($this->app);
            $anyGap = false;
            foreach ($roles as $r) {
                foreach ($gq->forRole((int) $cand['id'], (int) $r['id']) as $g) {
                    $anyGap = true;
                    $q[] = ['key' => 'gap_' . $g['application_id'] . '_' . substr($g['hash'], 0, 10), 'part' => 5, 'kind' => 'choice', 'prompt' => GapQuestions::PROMPT,
                        'hint' => '“' . $g['text'] . '”', 'saved_prompt' => GapQuestions::PROMPT . ' “' . $g['text'] . '”', 'required' => true,
                        'options' => array_values(GapQuestions::ANSWERS), 'flag' => $g['critical'] ? [GapQuestions::ANSWERS['no']] : [], 'roles' => [(int) $r['id']],
                        'role' => count($roles) > 1 ? $r['title'] : null, 'gap' => $g];
                }
            }
            if ($anyGap) {
                $q[] = ['key' => GapQuestions::DETAIL_KEY, 'part' => 5, 'kind' => 'textarea', 'prompt' => GapQuestions::DETAIL_PROMPT, 'required' => false,
                    'hint' => GapQuestions::DETAIL_HINT, 'roles' => null];
            }
        }
        // Last: room for anything they want to tell us. It also reaches you as a message from them.
        $q[] = ['key' => 'notes', 'part' => 4, 'kind' => 'textarea', 'prompt' => 'Anything else you would like to share with us?', 'required' => false,
            'hint' => 'Optional. For example a gap in your CV, a recent move, or a notice period you can buy out.'];
        return $q;
    }

    // ---------------------------------------------------------------- creating links

    /**
     * A new link for one application (replacing any unanswered one for it), or, with no
     * application, a general one for the candidate. Not emailed here. Returns the lead's id.
     */
    public function create(?int $applicationId, ?int $userId, ?int $candidateId = null): int
    {
        $db = $this->app->db;
        $a = $applicationId ? $db->one('SELECT candidate_id, role_id FROM applications WHERE id = ?', [$applicationId]) : null;
        $candidateId = $a ? (int) $a['candidate_id'] : (int) $candidateId;
        if ($applicationId) {
            foreach ($db->all("SELECT id, parent_id FROM questionnaires WHERE application_id = ? AND status IN ('sent','opened')", [$applicationId]) as $old) {
                $this->setStatus($old['parent_id'] ? (int) $old['id'] : (int) $old['id'], 'cancelled', !$old['parent_id']);
            }
        } else {
            foreach ($db->all("SELECT id FROM questionnaires WHERE candidate_id = ? AND application_id IS NULL AND status IN ('sent','opened')", [$candidateId]) as $old) {
                $this->setStatus((int) $old['id'], 'cancelled', true);
            }
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $days = max(5, (int) $this->app->setting('questionnaire_days', '14'));
        return $db->insert('questionnaires', [
            'token_hash' => hash('sha256', $token), 'token_enc' => Secrets::seal($this->app, $token, 'questionnaire'),
            'application_id' => $applicationId, 'candidate_id' => $candidateId, 'role_id' => $a ? (int) $a['role_id'] : null,
            'expires_at' => date('Y-m-d H:i:s', strtotime("+$days days 23:59:59")), 'created_by' => $userId,
        ]);
    }

    /**
     * Adds another of the candidate's applications to an open link, so one page covers both roles.
     * A general link (no role yet) simply becomes this role's link.
     */
    public function attach(int $leadId, int $applicationId): void
    {
        $db = $this->app->db;
        $lead = $db->one('SELECT * FROM questionnaires WHERE id = ? AND parent_id IS NULL', [$leadId]);
        $a = $db->one('SELECT a.candidate_id, a.role_id, r.is_pool FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$applicationId]);
        if (!$lead || !$a || $a['is_pool'] || (int) $a['candidate_id'] !== (int) $lead['candidate_id'] || !in_array($lead['status'], ['sent', 'opened'], true)) {
            return;
        }
        if ((int) $lead['application_id'] === $applicationId || $db->col("SELECT 1 FROM questionnaires WHERE parent_id = ? AND application_id = ? AND status <> 'cancelled'", [$leadId, $applicationId])) {
            return; // already covered
        }
        // Any other open link for this application gives way to this one.
        foreach ($db->all("SELECT id, parent_id FROM questionnaires WHERE application_id = ? AND status IN ('sent','opened') AND id <> ?", [$applicationId, $leadId]) as $old) {
            $this->setStatus((int) $old['id'], 'cancelled', !$old['parent_id']);
        }
        if (!$lead['application_id']) {
            $db->run('UPDATE questionnaires SET application_id = ?, role_id = ? WHERE id = ?', [$applicationId, $a['role_id'], $leadId]);
            return;
        }
        $db->insert('questionnaires', [
            'token_hash' => hash('sha256', random_bytes(24)), 'token_enc' => '-', // never sent: the lead carries the link
            'application_id' => $applicationId, 'candidate_id' => $lead['candidate_id'], 'role_id' => $a['role_id'], 'parent_id' => $leadId,
            'status' => $lead['status'], 'email_id' => $lead['email_id'], 'expires_at' => $lead['expires_at'], 'created_by' => $lead['created_by'],
        ]);
    }

    /** The candidate's open link, if there is one. */
    public function openLead(int $candidateId): ?array
    {
        return $this->app->db->one("SELECT * FROM questionnaires WHERE candidate_id = ? AND parent_id IS NULL AND status IN ('sent','opened') AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$candidateId]);
    }

    /** The lead and its other roles: rows with role details (role fields NULL for a general link). */
    public function group(int $leadId): array
    {
        return $this->app->db->all('SELECT q.*, r.title, r.location, r.work_mode, r.status AS role_status, r.notice_max_days, r.ctc_max_lpa, r.screening, r.client_id
                                    FROM questionnaires q LEFT JOIN roles r ON r.id = q.role_id WHERE q.id = ? OR q.parent_id = ? ORDER BY q.parent_id IS NOT NULL, q.id', [$leadId, $leadId]);
    }

    /** The roles a link covers that are still open (role rows, in order). */
    public function openRoles(int $leadId): array
    {
        $ids = array_filter(array_map(fn ($g) => (int) $g['role_id'], array_filter($this->group($leadId), fn ($g) => $g['status'] !== 'cancelled')));
        $roles = [];
        foreach (array_unique($ids) as $rid) {
            $r = $this->app->db->one('SELECT r.*, cl.name AS client_name FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ?', [$rid]);
            if ($r && in_array($r['status'], self::OPEN_ROLE, true)) {
                $roles[] = $r;
            }
        }
        return $roles;
    }

    /** Changes the status of a lead and every role hanging off it (or of one row only). */
    private function setStatus(int $id, string $status, bool $withChildren = true, array $extra = []): void
    {
        $set = 'status = ?' . implode('', array_map(fn ($k) => ", $k = ?", array_keys($extra)));
        $params = array_merge([$status], array_values($extra), [$id]);
        $this->app->db->run("UPDATE questionnaires SET $set WHERE id = ?" . ($withChildren ? ' OR parent_id = ?' : ''), $withChildren ? array_merge($params, [$id]) : $params);
    }

    /** The personal link for a questionnaire. */
    public function link(int $qid): string
    {
        $enc = (string) $this->app->db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$qid]);
        return rtrim((string) ($this->app->config['app']['url'] ?? ''), '/') . '/q/' . Secrets::open($this->app, $enc, 'questionnaire');
    }

    // ---------------------------------------------------------------- sending

    /**
     * Sends (or re-sends) the request for one application, on its own.
     * @return array{status:string, message:string}  status: queued | held | skipped
     */
    public function invite(int $applicationId, ?int $userId): array
    {
        $db = $this->app->db;
        $a = $db->one('SELECT a.*, r.is_pool FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$applicationId]);
        if (!$a) {
            return ['status' => 'skipped', 'message' => 'Application not found.'];
        }
        if ($a['is_pool']) {
            return ['status' => 'skipped', 'message' => 'Talent pools have no questionnaire.'];
        }
        if ($db->col("SELECT 1 FROM questionnaires WHERE application_id = ? AND status = 'answered'", [$applicationId])) {
            return ['status' => 'skipped', 'message' => 'Already answered.'];
        }
        return $this->inviteCandidate((int) $a['candidate_id'], [$applicationId], $userId);
    }

    /**
     * One email to one person, covering the applications given (the one-time request, or a single role).
     * @param int[] $applicationIds
     * @return array{status:string, message:string}
     */
    public function inviteCandidate(int $candidateId, array $applicationIds, ?int $userId): array
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if (!$c) {
            return ['status' => 'skipped', 'message' => 'Candidate not found.'];
        }
        if (Contact::silenced($c)) {
            return ['status' => 'skipped', 'message' => 'Marked do not contact.'];
        }
        if (!$c['email']) {
            return ['status' => 'skipped', 'message' => 'No email address.'];
        }
        $apps = array_values(array_filter(array_map('intval', $applicationIds), fn ($id) => (bool) $db->col(
            'SELECT 1 FROM applications a JOIN roles r ON r.id = a.role_id WHERE a.id = ? AND a.candidate_id = ? AND r.is_pool = 0', [$id, $candidateId])));
        if (!$apps) {
            return ['status' => 'skipped', 'message' => 'No client role to ask about.'];
        }
        // A fresh link replaces any open one for this person.
        foreach ($db->all("SELECT id FROM questionnaires WHERE candidate_id = ? AND parent_id IS NULL AND status IN ('sent','opened')", [$candidateId]) as $old) {
            $this->setStatus((int) $old['id'], 'cancelled');
        }
        $lead = $this->create(array_shift($apps), $userId);
        foreach ($apps as $id) {
            $this->attach($lead, $id);
        }
        $emailId = $this->email($lead, 'invite', $userId);
        $held = $db->col('SELECT status FROM email_queue WHERE id = ?', [$emailId]) === 'held';
        Audit::log('questionnaire_sent', 'candidate', $candidateId, ['roles' => count($applicationIds), 'held' => $held]);
        return $held ? ['status' => 'held', 'message' => 'The request waits for you to check the address (under Emails).']
                     : ['status' => 'queued', 'message' => 'Request for details sent.'];
    }

    /**
     * "the Accounting Manager role (Hybrid Work) with our client in Pune", for one or more roles;
     * "a role with an Eshcol Global client" when no role is known.
     */
    public static function rolePhrase(array $roles, bool $html): string
    {
        $esc = fn ($v) => $html ? CandidateEmail::h($v) : $v;
        if (!$roles) {
            return 'a role with an Eshcol Global client';
        }
        $modes = array_unique(array_map(fn ($r) => (string) ($r['work_mode'] ?? ''), $roles));
        $same = count($modes) === 1;
        $names = array_map(fn ($r) => ($html ? '<b style="color:inherit;">' . $esc($r['title']) . '</b>' : $r['title'])
            . (!$same && isset(WORK_MODES[$r['work_mode'] ?? '']) ? ' (' . WORK_MODES[$r['work_mode']] . ')' : ''), $roles);
        $list = count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
        $mode = $same && isset(WORK_MODES[reset($modes)]) ? ' (' . WORK_MODES[reset($modes)] . ')' : '';
        $locs = array_unique(array_filter(array_map(fn ($r) => trim((string) $r['location']), $roles)));
        $clients = array_unique(array_map(fn ($r) => (int) ($r['client_id'] ?? 0), $roles));
        // The where-and-for-whom part depends on the type of position (15 Nov 2026): "with our client in Pune" as before,
        // "at Eshcol Global, Chennai" for our own team, "on Eshcol's payroll, working at …'s site in Tiruvallur" for staffing.
        return 'the ' . $list . (count($roles) === 1 ? ' role' : ' roles') . $mode . Engagement::whereClause(Engagement::forRoles($roles), $roles, $html);
    }

    /** "Accounting Manager (Hybrid Work)" for subjects; null for several roles or none. */
    public static function subjectTitle(array $roles): ?string
    {
        return count($roles) === 1 ? role_title_mode((string) $roles[0]['title'], $roles[0]['work_mode'] ?? null) : null;
    }

    /** Queues the request, the reminder or the final email for a link; returns the email id. */
    public function email(int $leadId, string $step, ?int $userId): int
    {
        $db = $this->app->db;
        $q = $db->one('SELECT * FROM questionnaires WHERE id = ?', [$leadId]);
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$q['candidate_id']]);
        $roles = $this->openRoles($leadId);
        $first = self::firstName($c);
        $link = $this->link($leadId);
        $phraseH = self::rolePhrase($roles, true);
        $phraseT = self::rolePhrase($roles, false);
        $st = self::subjectTitle($roles);
        $type = Engagement::forRoles($roles); // wording by type of position (15 Nov 2026)
        $critical = 'These details are critical for evaluating your application, and we can only put forward profiles that are complete.';

        if ($step === 'reminder') {
            $subject = 'Reminder: complete your ' . ($st ? "$st " : '') . 'application';
            $p = ['title' => $subject, 'preheader' => 'Your application is waiting for a few details. About two minutes.',
                'eyebrow' => 'A gentle reminder', 'headline' => $first !== '' ? "Just one step left, $first." : 'Just one step left.',
                'sub_html' => 'Your application for ' . $phraseH . ' is waiting for a few details.', 'sub_text' => "Your application for $phraseT is waiting for a few details.",
                'action' => ['kicker' => 'About 2 minutes, on your phone',
                    'paras' => [['Two days ago we asked for a few details to complete your application. We haven\'t received them yet, and <strong style="color:#320A67;">your application can\'t move forward without them</strong>: ' . Engagement::whoNeeds($type) . ' to know your salary expectations and how soon you could join before considering a profile.',
                        "Two days ago we asked for a few details to complete your application. We haven't received them yet, and your application can't move forward without them: " . Engagement::whoNeeds($type) . " to know your salary expectations and how soon you could join before considering a profile."]],
                    'buttons' => [['Complete my application', $link]],
                    'small' => 'The link is personal to you. If you have already sent your answers, thank you, and please ignore this email.']];
        } elseif ($step === 'final') {
            $subject = $st ? 'Are you still interested in the ' . $roles[0]['title'] . ' role?' : 'Are you still interested?';
            $p = ['title' => $subject, 'preheader' => 'Please let us know either way. One click is enough.',
                'eyebrow' => 'Final reminder', 'headline' => $first !== '' ? "Are you still interested, $first?" : 'Are you still interested?',
                'sub_html' => 'We have written twice about your application for ' . $phraseH . '.', 'sub_text' => "We have written twice about your application for $phraseT.",
                'action' => [
                    'paras' => [['We haven\'t yet received the details we need to consider your application, and we\'d hate for you to miss out. <strong style="color:#320A67;">Please let us know either way.</strong>',
                        "We haven't yet received the details we need to consider your application, and we'd hate for you to miss out. Please let us know either way."]],
                    'buttons' => [['Yes, complete my application', $link], ["I'm no longer interested", $link . '/no', false]],
                    'small' => "If we don't hear from you, we will take it that you are no longer interested in this role. You are always welcome to apply for future roles."]];
        } else { // invite: the request on its own (the one-time email, or a single role)
            $subject = 'Action needed: complete your ' . ($st ? "$st " : '') . 'application';
            $p = ['title' => $subject, 'preheader' => 'Your profile is being considered. We need a few details to take it forward.',
                'eyebrow' => 'Your application', 'headline' => $first !== '' ? "One step left, $first." : 'One step left.',
                'sub_html' => 'Thank you for your interest in ' . $phraseH . '.', 'sub_text' => "Thank you for your interest in $phraseT.",
                'action' => ['kicker' => 'Action needed · about 2 minutes',
                    'paras' => [['We have read your CV, and your profile is being considered. To evaluate it against ' . Engagement::requirements($type) . ', we need a few details that CVs rarely include. <strong style="color:#320A67;">' . $critical . '</strong>',
                        "We have read your CV, and your profile is being considered. To evaluate it against " . Engagement::requirements($type) . ", we need a few details that CVs rarely include. $critical"]],
                    'list' => ['Your current salary and the lowest salary you would accept', 'How soon you could join', 'A quick check that the details we have for you are correct'],
                    'buttons' => [['Complete my application', $link]],
                    'small' => (count($roles) > 1 ? 'One short page covers both roles. ' : '') . 'Works on your phone, with nothing to sign up for. The link is personal to you.'],
                'steps' => true,
                'about' => ['Your answers are kept with your application, as our <a href="' . CandidateEmail::LINKS['privacy'] . '" target="_blank" style="color:#7946B2;">privacy policy</a> explains. If you would rather we deleted your details, just reply with "Please remove me".',
                    'Your answers are kept with your application, as our privacy policy explains: ' . CandidateEmail::LINKS['privacy'] . "\nIf you would rather we deleted your details, just reply with \"Please remove me\"."]];
        }
        $p += ['footer_reason' => Engagement::footerReason($type), 'requirements' => Engagement::requirements($type)];
        [$html, $text] = CandidateEmail::render($p);
        $ack = new Acknowledger($this->app);
        $ack->dns = $this->dns;
        $hold = $ack->addressDoubt($c);
        $id = (new Mailer($this->app))->enqueue([
            'kind' => 'questionnaire', 'candidate_id' => (int) $c['id'], 'to_email' => $c['email'], 'to_name' => $c['full_name'],
            'subject' => $subject, 'html' => $html, 'text' => $text, 'meta' => ['questionnaire' => $leadId, 'step' => $step, 'reminder' => $step !== 'invite'],
            'status' => $hold ? 'held' : 'pending', 'hold_reason' => $hold, 'created_by' => $userId,
        ]);
        $db->run('UPDATE questionnaires SET email_id = ? WHERE id = ? OR parent_id = ?', [$id, $leadId, $leadId]);
        return $id;
    }

    public static function firstName(?array $c): string
    {
        $first = trim((string) (($c['first_name'] ?? '') ?: strtok((string) ($c['full_name'] ?? ''), ' ')));
        return $first !== '' ? mb_convert_case(mb_strtolower($first), MB_CASE_TITLE) : '';
    }

    // ---------------------------------------------------------------- the candidate's side

    /** The link's lead row (with its first role's details), or null if the link is unknown. */
    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $q = $this->app->db->one('SELECT q.*, r.title, r.location, r.work_mode, r.notice_max_days, r.ctc_max_lpa, r.screening, r.status AS role_status
                                  FROM questionnaires q LEFT JOIN roles r ON r.id = q.role_id WHERE q.token_hash = ? AND q.parent_id IS NULL', [hash('sha256', $token)]);
        if ($q && in_array($q['status'], ['sent', 'opened'], true) && strtotime($q['expires_at']) < time()) {
            $this->setStatus((int) $q['id'], 'expired');
            $q['status'] = 'expired';
        }
        return $q ?: null;
    }

    public function markOpened(array $q): void
    {
        if ($q['status'] === 'sent') {
            $this->app->db->run("UPDATE questionnaires SET status = 'opened', opened_at = NOW() WHERE (id = ? OR parent_id = ?) AND status = 'sent'", [$q['id'], $q['id']]);
        }
    }

    /** "I'm no longer interested": stops every email, and moves them to Withdrew on each role. */
    public function decline(array $q): void
    {
        $db = $this->app->db;
        $wid = Stages::id($this->app, Stages::WITHDREW);
        $withdrew = $wid ? $db->one("SELECT id FROM stages WHERE id = ? AND is_active = 1", [$wid]) : null;
        $db->tx(function () use ($db, $q, $withdrew) {
            $this->setStatus((int) $q['id'], 'declined', true, ['declined_at' => date('Y-m-d H:i:s')]);
            foreach ($this->group((int) $q['id']) as $g) {
                if (!$g['application_id'] || !$withdrew) {
                    continue;
                }
                $cur = $db->one('SELECT a.stage_id, s.kind FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$g['application_id']]);
                if ($cur && $cur['kind'] === 'active') {
                    $db->update('applications', ['stage_id' => $withdrew['id']], (int) $g['application_id']);
                    $db->insert('stage_history', ['application_id' => $g['application_id'], 'from_stage_id' => $cur['stage_id'], 'to_stage_id' => $withdrew['id'],
                        'note' => 'The candidate said they are no longer interested']);
                }
            }
            $db->run("UPDATE email_queue SET status = 'cancelled', last_error = 'The candidate said they are no longer interested' WHERE candidate_id = ? AND kind = 'questionnaire' AND status IN ('pending','held')", [$q['candidate_id']]);
        });
        Audit::log('questionnaire_declined', 'candidate', (int) $q['candidate_id'], ['questionnaire' => (int) $q['id']]);
    }

    /**
     * Checks and saves the answers. Returns errors keyed by question (empty when saved).
     * @param array<string, mixed> $post
     */
    public function submit(array $q, array $post): array
    {
        $db = $this->app->db;
        $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$q['candidate_id']]);
        $roles = $this->openRoles((int) $q['id']);
        $questions = $this->questionsFor($roles, $cand);
        $errors = [];
        $answers = [];
        foreach ($questions as $i => $qq) {
            $v = trim((string) ($post[$qq['key']] ?? ''));
            if ($v === '') {
                if ($qq['required']) {
                    $errors[$qq['key']] = 'Please answer this one.';
                }
                continue;
            }
            if ($qq['kind'] === 'number') {
                $v = str_replace([',', '₹', ' '], ['.', '', ''], mb_strtolower($v));
                $v = preg_replace('/(lakhs?|lpa|lac|per annum|pa)$/', '', $v);
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > 500) {
                    $errors[$qq['key']] = 'Please enter a number of lakhs per annum, for example 4.5.';
                    continue;
                }
                $v = (string) round((float) $v, 2);
            } elseif ($qq['kind'] === 'years') {
                $v = str_replace([',', ' ', 'years', 'yrs', 'year'], ['.', '', '', '', ''], mb_strtolower($v));
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > 50) {
                    $errors[$qq['key']] = 'Please enter a number of years, for example 4.5. Enter 0 if you have not worked yet.';
                    continue;
                }
                $v = (string) round((float) $v, 1);
            } elseif ($qq['kind'] === 'email') {
                $v = mb_strtolower($v);
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[$qq['key']] = 'Please check this email address.';
                    continue;
                }
            } elseif ($qq['kind'] === 'url') {
                $v = FieldExtractor::linkedin($v) ?? '';
                if ($v === '') {
                    $errors[$qq['key']] = 'Please paste your LinkedIn profile link, for example linkedin.com/in/your-name, or leave this empty.';
                    continue;
                }
            } elseif ($qq['kind'] === 'tel') {
                if (FieldExtractor::normalisePhone($v) === null) {
                    $errors[$qq['key']] = 'Please enter a 10-digit mobile number.';
                    continue;
                }
                $v = mb_substr($v, 0, 30);
            } elseif (in_array($qq['kind'], ['choice', 'yesno', 'select'], true) && !in_array($v, $qq['options'] ?? ['Yes', 'No'], true)) {
                $errors[$qq['key']] = 'Please choose one of the options.';
                continue;
            } else {
                $v = mb_substr($v, 0, match ($qq['key']) { 'full_name' => 160, 'qualifications' => 255, 'notes' => 2000, default => 500 });
            }
            $answers[$qq['key']] = ['sort' => $i, 'prompt' => $qq['saved_prompt'] ?? $qq['prompt'], 'answer' => $v, 'flagged' => in_array($v, $qq['flag'] ?? [], true), 'roles' => $qq['roles'] ?? null];
        }
        if (isset($answers['ctc_min'], $answers['ctc_expected']) && (float) $answers['ctc_min']['answer'] > (float) $answers['ctc_expected']['answer']) {
            $errors['ctc_min'] = 'This is higher than the salary you are hoping for. Please check both.';
        }
        if ($errors) {
            return $errors;
        }
        $keep = !empty($post['keep']);
        $cvMonths = exp_now($cand);

        // A new email or mobile waits for your approval: one typo would cut us off from them.
        $contact = [];
        $direct = [];
        foreach (['email' => 'email_norm', 'phone' => 'phone_norm'] as $k => $normCol) {
            if (!isset($answers[$k])) {
                continue;
            }
            $new = $answers[$k]['answer'];
            $norm = $k === 'email' ? $new : FieldExtractor::normalisePhone($new);
            if ($norm === (string) $cand[$normCol]) {
                continue;
            }
            $taken = (bool) $db->col("SELECT 1 FROM candidates WHERE $normCol = ? AND id <> ?", [$norm, $cand['id']]);
            if (trim((string) $cand[$k]) === '' && !$taken) {
                $direct[$k] = $new;
                $direct[$normCol] = $norm;
            } else {
                $contact[$k] = $new;
            }
        }

        // Each role keeps its own copy of the answers, with its own flags.
        $rows = array_values(array_filter($this->group((int) $q['id']), fn ($g) => in_array($g['status'], ['sent', 'opened'], true)));
        $plans = [];
        foreach ($rows as $g) {
            $mine = array_filter($answers, fn ($a) => $a['roles'] === null || ($g['role_id'] && in_array((int) $g['role_id'], $a['roles'], true)));
            // "relocate_12" is stored as "relocate" on its own role, so every per-role view finds it.
            $store = [];
            foreach ($mine as $k => $a) {
                $store[str_starts_with($k, 'relocate') ? 'relocate' : (str_starts_with($k, 'contract') ? 'contract' : $k)] = $a;
            }
            $flags = [];
            foreach ($store as $k => $a) {
                if ($a['flagged']) {
                    $flags[] = $k === 'relocate' ? 'Not able to work in ' . $g['location'] : $a['prompt'] . ': ' . $a['answer'];
                }
            }
            if (isset($store['notice']) && $g['notice_max_days'] !== null && self::NOTICE[$store['notice']['answer']] > (int) $g['notice_max_days']) {
                $store['notice']['flagged'] = true;
                $flags[] = 'Notice ' . mb_strtolower($store['notice']['answer']) . ' (role wants ' . (int) $g['notice_max_days'] . ' days or less)';
            }
            if (isset($store['experience']) && $cvMonths !== null && abs((float) $store['experience']['answer'] * 12 - $cvMonths) > 24) {
                $store['experience']['flagged'] = true;
                $flags[] = 'Says ' . $store['experience']['answer'] . ' years of experience; the CV suggests about ' . experience($cvMonths);
            }
            $salaryKey = isset($store['ctc_min']) ? 'ctc_min' : (isset($store['ctc_expected']) ? 'ctc_expected' : null);
            if ($salaryKey && $g['ctc_max_lpa'] !== null && (float) $store[$salaryKey]['answer'] > (float) $g['ctc_max_lpa']) {
                $store[$salaryKey]['flagged'] = true;
                $flags[] = ($salaryKey === 'ctc_min' ? "Won't accept less than " : 'Expects ') . lpa($store[$salaryKey]['answer']) . ' (budget up to ' . lpa($g['ctc_max_lpa']) . ')';
            }
            foreach ($contact as $k => $v) {
                $flags[] = 'Gave a new ' . ($k === 'email' ? 'email' : 'mobile') . ": $v (waiting for your approval)";
            }
            if (isset($store['permission']) && $g['application_id']) {
                (new SubmissionChecks($this->app))->setPermission((int) $g['application_id'], $store['permission']['answer'] === 'Yes', 'details_form', null);
            }
            $plans[] = [(int) $g['id'], $store, $flags];
        }

        $db->tx(function () use ($db, $q, $plans, $answers, $keep, $contact, $direct, $cand) {
            foreach ($plans as [$id, $store, $flags]) {
                foreach ($store as $k => $a) {
                    $db->run('INSERT INTO questionnaire_answers (questionnaire_id, qkey, sort, prompt, answer, flagged) VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE answer = VALUES(answer), flagged = VALUES(flagged)',
                        [$id, $k, $a['sort'], mb_substr($a['prompt'], 0, 255), $a['answer'], $a['flagged'] ? 1 : 0]);
                }
                $db->run("UPDATE questionnaires SET status = 'answered', answered_at = NOW(), flags = ? WHERE id = ?", [json_encode($flags), $id]);
            }

            // Their own answers become their record, marked as coming from them.
            $src = json_decode((string) ($cand['field_sources'] ?? '{}'), true) ?: [];
            $upd = ['last_confirmed_at' => date('Y-m-d H:i:s')];
            // Open to a contract role on Eshcol's payroll (staffing): kept on the candidate for future staffing positions.
            foreach ($answers as $k => $a) {
                $v = array_search($a['answer'], Engagement::CONTRACT_ANSWERS, true);
                if (str_starts_with((string) $k, 'contract') && $v !== false) {
                    $upd['open_to_contract'] = $v;
                    $upd['open_to_contract_at'] = date('Y-m-d H:i:s');
                }
            }
            foreach (self::DETAILS + self::PERSONAL as $k => $col) {
                if (!isset($answers[$k])) {
                    continue;
                }
                $v = $answers[$k]['answer'];
                $upd[$col] = match ($k) {
                    'notice' => self::NOTICE[$v],
                    'location' => mb_substr($this->place($v), 0, 120),
                    'locality' => mb_substr((new Skills($this->app->db))->canonical($v, 'locality'), 0, 120),
                    'experience' => (int) round((float) $v * 12),
                    'full_name' => preg_replace('/\s+/', ' ', $v),
                    default => $v,
                };
                $src[$col] = 'candidate';
            }
            if (isset($upd['full_name']) && $upd['full_name'] !== (string) $cand['full_name']) {
                $upd['first_name'] = mb_substr((string) strtok($upd['full_name'], ' '), 0, 80);
            }
            if (isset($upd['exp_months'])) {
                $upd['exp_as_of'] = date('Y-m-d');
            }
            foreach ($direct as $col => $v) {
                $upd[$col] = $v;
                if (!str_ends_with($col, '_norm')) {
                    $src[$col] = 'candidate';
                }
            }
            if ($contact) {
                $upd['pending_contact'] = json_encode($contact + ['at' => date('Y-m-d H:i:s')]);
            }
            $upd['field_sources'] = json_encode($src);
            if (!$keep) {
                $upd['removal_requested_at'] = date('Y-m-d H:i:s');
            }
            $db->update('candidates', $upd, (int) $q['candidate_id']);
        });
        // "No", "Nil", "NA" in the "Anything else" box is not a message worth your attention.
        if (isset($answers['notes']) && !preg_match('/^\W*(no|nil|none|na|n\/a|nothing|no thanks?|not really|ok|okay|-+|\.+)\W*$/i', $answers['notes']['answer'])) {
            (new CandidateMessages($this->app))->record((int) $q['candidate_id'], $answers['notes']['answer'], 'form', null,
                'On the application form' . ($roles ? ': ' . implode(', ', array_column($roles, 'title')) : ''));
        }
        (new Retention($this->app))->touch((int) $q['candidate_id']);
        // Their answers about gaps in the CV re-score the assessment of each position asked about (B7).
        $asked = [];
        foreach ($questions as $qq) {
            if (isset($qq['gap'])) {
                $asked[$qq['key']] = $qq['gap'];
            }
        }
        if ($asked) {
            (new GapQuestions($this->app))->save((int) $q['candidate_id'], (int) $q['id'], $answers, $asked);
        }
        // New salary, notice and location change the score on every role they're on.
        $imp = new CandidateImporter($this->app);
        foreach ($db->all('SELECT id FROM applications WHERE candidate_id = ?', [$q['candidate_id']]) as $a) {
            $imp->scoreApplication((int) $a['id']);
        }
        Audit::log('questionnaire_answered', 'candidate', (int) $q['candidate_id'], ['roles' => count($plans), 'keep' => $keep, 'contact_change' => (bool) $contact]);
        // Their own city and qualifications are in now: the screening rules again (17 Nov 2026), so someone who turns
        // out to live in another city, or not to be a B.Com, is moved to Not suitable without waiting for you.
        if (Categoriser::mode($this->app) === 'on') {
            $fr = new FunctionRouting($this->app);
            $cand2 = $db->one('SELECT * FROM candidates WHERE id = ?', [$q['candidate_id']]);
            $cat2 = (new Categoriser($this->app))->current((int) $q['candidate_id']);
            $ns = Stages::id($this->app, Stages::NOT_SUITABLE);
            foreach ($db->all("SELECT a.*, r.title AS role_title FROM applications a JOIN roles r ON r.id = a.role_id JOIN stages s ON s.id = a.stage_id
                    WHERE a.candidate_id = ? AND r.is_pool = 0 AND s.kind = 'active'", [$q['candidate_id']]) as $ap) {
                $role2 = $db->one('SELECT * FROM roles WHERE id = ?', [$ap['role_id']]);
                if ($ns && $cand2 && $role2 && !Shortlists::isShortlisted($this->app, (int) $ap['stage_id']) && ($fail = $fr->screenFail($cand2, $cat2, $role2))) {
                    $owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
                    (new StageMover($this->app))->move($ap, (int) $ns, 'Screened out automatically: ' . $fail[1], $owner, false);
                    $db->run('UPDATE applications SET fit_note = ? WHERE id = ?', [mb_substr('Screened out: ' . $fail[1], 0, 255), $ap['id']]);
                }
            }
        }
        // The consent record (B2): "Keep my details on file" ticked or not.
        (new Consents($this->app))->record((int) $q['candidate_id'], $keep ? 'given' : 'withdrawn', 'details_form');
        return [];
    }

    /** You approve (or turn down) a new email or mobile the candidate gave. */
    public function resolveContact(int $candidateId, bool $accept): string
    {
        $db = $this->app->db;
        $c = $db->one('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        $p = json_decode((string) ($c['pending_contact'] ?? ''), true) ?: [];
        if (!$p) {
            return 'Nothing waiting.';
        }
        $upd = ['pending_contact' => null];
        $msg = 'Kept the details we had.';
        if ($accept) {
            $src = json_decode((string) ($c['field_sources'] ?? '{}'), true) ?: [];
            $done = [];
            foreach (['email' => 'email_norm', 'phone' => 'phone_norm'] as $k => $normCol) {
                if (empty($p[$k])) {
                    continue;
                }
                $norm = $k === 'email' ? mb_strtolower($p[$k]) : FieldExtractor::normalisePhone($p[$k]);
                $other = $db->one("SELECT id FROM candidates WHERE $normCol = ? AND id <> ?", [$norm, $candidateId]);
                if ($other) {
                    return 'Not changed: ' . ref_candidate((int) $other['id']) . ' already has that ' . ($k === 'email' ? 'email address' : 'mobile number') . '.';
                }
                $upd[$k] = $k === 'email' ? $norm : $p[$k];
                $upd[$normCol] = $norm;
                $src[$k] = 'candidate';
                $done[] = $k === 'email' ? 'email' : 'mobile';
            }
            $upd['field_sources'] = json_encode($src);
            $msg = 'New ' . implode(' and ', $done) . ' saved.';
        }
        $db->update('candidates', $upd, $candidateId);
        Audit::log($accept ? 'contact_change_accepted' : 'contact_change_dismissed', 'candidate', $candidateId);
        return $msg;
    }

    /** Known localities of a city, for suggestions as the candidate types. */
    private function localities(string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }
        // "Chennai", "Madras", "Chennai, Tamil Nadu", "Pimprala, Jalgaon": the same matching as the Edit page (17 Nov 2026).
        $map = locality_map();
        return $map['cities'][locality_city($city, $map)] ?? [];
    }

    /** "chennai" -> "Chennai", "bangalore" -> "Bengaluru" (from the dictionary); unknown places tidied up. */
    private function place(string $v): string
    {
        $c = (new Skills($this->app->db))->canonical($v, 'location');
        return $c === trim($v) ? mb_convert_case(mb_strtolower(trim($v)), MB_CASE_TITLE) : $c;
    }

    // ---------------------------------------------------------------- scheduled

    /** How long the details link stays open after the last email we send about it (Benjamin, 17 Nov 2026). */
    public const OPEN_DAYS_AFTER_LAST = 14;

    /** The end of the 14th day after the last email (that day, 23:59:59). Pure. */
    public static function openUntil(string $lastEmailAt): string
    {
        return date('Y-m-d 23:59:59', strtotime(substr($lastEmailAt, 0, 10) . ' +' . self::OPEN_DAYS_AFTER_LAST . ' days'));
    }

    /**
     * Scheduled (hourly): the 48-hour reminder, the final email 48 hours later, and expiry.
     * Follow-ups only go between 9 am and 7 pm, India time. Returns a line for the log.
     */
    public function housekeeping(?int $hourNow = null): string
    {
        $db = $this->app->db;
        $expired = $db->run("UPDATE questionnaires SET status = 'expired' WHERE status IN ('sent','opened') AND expires_at < NOW()")->rowCount();
        $hour = $hourNow ?? (int) date('G');
        $reminders = $finals = [];
        if ($hour >= 9 && $hour < 19) {
            $rh = max(1, (int) $this->app->setting('questionnaire_reminder_hours', '48'));
            $fh = max(1, (int) $this->app->setting('questionnaire_final_hours', '48'));
            $ok = "q.status IN ('sent','opened') AND q.parent_id IS NULL AND " . Contact::putForwardSql('c') . " AND c.email IS NOT NULL AND q.expires_at > NOW() + INTERVAL 1 DAY";
            $reminders = $db->all("SELECT q.id FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id
                                   WHERE $ok AND q.reminded_at IS NULL
                                     AND EXISTS (SELECT 1 FROM email_queue e WHERE e.id = q.email_id AND e.status = 'sent' AND e.sent_at < NOW() - INTERVAL $rh HOUR) LIMIT 100");
            foreach ($reminders as $r) {
                $this->email((int) $r['id'], 'reminder', null);
                $db->run('UPDATE questionnaires SET reminded_at = NOW() WHERE id = ? OR parent_id = ?', [$r['id'], $r['id']]);
            }
            $finals = $db->all("SELECT q.id FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id
                                WHERE $ok AND q.final_at IS NULL AND q.reminded_at < NOW() - INTERVAL $fh HOUR
                                  AND EXISTS (SELECT 1 FROM email_queue e WHERE e.id = q.email_id AND e.status = 'sent') LIMIT 100");
            foreach ($finals as $r) {
                $this->email((int) $r['id'], 'final', null);
                // The link stays open 14 days after the last email (Benjamin, 17 Nov 2026), whatever it was set to before.
                $db->run('UPDATE questionnaires SET final_at = NOW(), expires_at = GREATEST(expires_at, ?) WHERE id = ? OR parent_id = ?',
                    [self::openUntil(date('Y-m-d H:i:s')), $r['id'], $r['id']]);
            }
        }
        return ($expired || $reminders || $finals) ? count($reminders) . ' reminder(s) and ' . count($finals) . " final email(s) queued, $expired link(s) expired" : '';
    }

    /** The latest questionnaire for each application of a role: [application_id => row]. */
    public function forRole(int $roleId): array
    {
        $rows = $this->app->db->all("SELECT q.application_id, q.status, q.flags, q.answered_at, q.created_at FROM questionnaires q
                                     WHERE q.role_id = ? AND q.status <> 'cancelled' ORDER BY q.id", [$roleId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['application_id']] = $r;
        }
        return $out;
    }

    /**
     * Completed applications waiting for your review: answered, not yet reviewed, and the
     * application still in play on an open role (or answered with no role). Oldest first.
     * @return array<int, array>
     */
    public function pendingReviews(int $limit = 100): array
    {
        return $this->app->db->all("SELECT q.id, q.application_id, q.role_id, q.flags, q.answered_at, c.id AS candidate_id, c.full_name, r.title
            FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id
            LEFT JOIN roles r ON r.id = q.role_id LEFT JOIN applications a ON a.id = q.application_id LEFT JOIN stages s ON s.id = a.stage_id
            WHERE q.status = 'answered' AND q.reviewed_at IS NULL
              AND ((q.application_id IS NULL AND q.parent_id IS NULL) OR (s.kind = 'active' AND r.status IN ('open','on_hold','draft')))
            ORDER BY q.answered_at, q.id LIMIT " . (int) $limit);
    }

    /** You looked at their answers and acted: for one application, or (with no application) their details with no role. */
    public function markReviewed(?int $applicationId, int $candidateId, ?int $userId): void
    {
        if ($applicationId) {
            $this->app->db->run("UPDATE questionnaires SET reviewed_at = NOW(), reviewed_by = ? WHERE application_id = ? AND status = 'answered' AND reviewed_at IS NULL", [$userId, $applicationId]);
        } else {
            $this->app->db->run("UPDATE questionnaires SET reviewed_at = NOW(), reviewed_by = ? WHERE candidate_id = ? AND application_id IS NULL AND status = 'answered' AND reviewed_at IS NULL", [$userId, $candidateId]);
        }
    }

    /**
     * How candidates on open client roles have responded: counts per person, and one row per
     * application with what they told us (salary, joining time, where they live, can they work
     * there) and the flags. Answered first (within budget before flagged), then opened, then waiting.
     * @return array{counts: array<string,int>, rows: array<int, array>}
     */
    public function responses(): array
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT q.id, q.application_id, q.status, q.flags, q.answered_at, q.declined_at, q.opened_at, q.created_at, q.reminded_at, q.final_at, q.reviewed_at,
                                 c.id AS candidate_id, c.full_name, c.ctc_current_lpa, c.ctc_expected_lpa, c.ctc_min_lpa, c.notice_days, c.location, c.locality,
                                 r.id AS role_id, r.title, r.work_mode, r.ctc_max_lpa, s.name AS stage_name, s.kind AS stage_kind, ap.stage_reason,
                                 (SELECT a.answer FROM questionnaire_answers a WHERE a.questionnaire_id = q.id AND a.qkey = 'relocate') AS relocate
                          FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id JOIN roles r ON r.id = q.role_id
                          JOIN applications ap ON ap.id = q.application_id JOIN stages s ON s.id = ap.stage_id
                          WHERE q.status <> 'cancelled' AND r.status = 'open' AND r.is_pool = 0
                            AND q.id = (SELECT MAX(q2.id) FROM questionnaires q2 WHERE q2.application_id = q.application_id AND q2.status <> 'cancelled')");
        $rank = ['answered' => 0, 'opened' => 1, 'sent' => 2, 'expired' => 3, 'declined' => 4];
        foreach ($rows as &$r) {
            $r['flags'] = json_decode((string) $r['flags'], true) ?: [];
            $r['notice_label'] = $r['notice_days'] === null ? null : (array_search((int) $r['notice_days'], self::NOTICE, true) ?: (int) $r['notice_days'] . ' days');
        }
        unset($r);
        usort($rows, fn ($a, $b) => [$rank[$a['status']] ?? 9, count($a['flags']), $b['answered_at'] ?? $b['created_at']] <=> [$rank[$b['status']] ?? 9, count($b['flags']), $a['answered_at'] ?? $a['created_at']]);
        // Per person: their best status across roles (answered beats opened beats waiting).
        $person = [];
        foreach ($rows as $r) {
            $s = $r['status'] === 'expired' ? 'sent' : $r['status'];
            $cid = (int) $r['candidate_id'];
            if (!isset($person[$cid]) || ($rank[$s] ?? 9) < ($rank[$person[$cid]] ?? 9)) {
                $person[$cid] = $s;
            }
        }
        $counts = ['asked' => count($person), 'answered' => 0, 'opened' => 0, 'sent' => 0, 'declined' => 0];
        foreach ($person as $s) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        $counts['within_budget'] = count(array_unique(array_column(array_filter($rows, fn ($r) => $r['status'] === 'answered' && !$r['flags']), 'candidate_id')));
        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * The at-a-glance numbers for the "Complete your application" requests (Benjamin, 10 Nov 2026):
     * every request, with a role or without, counted once per person (their best answer counts).
     * @return array{people:int, queued:int, sent:int, opened:int, answered:int, declined:int, waiting:int, rate:int,
     *   gave:array{ctc:int, min:int, notice:int, all:int}, stage:array<string,int>, after:array<string,int>, median_hours:?float,
     *   roles:list<array{title:string, asked:int, answered:int, declined:int, within:int}>, days:list<array{day:string, sent:int, answered:int, declined:int}>}
     */
    public function dashboard(): array
    {
        $db = $this->app->db;
        // The request each person was sent: their newest lead row (a role's or a general one), and its email.
        $leads = $db->all("SELECT q.id, q.candidate_id, q.status, q.created_at, q.opened_at, q.answered_at, q.declined_at, q.reminded_at, q.final_at, q.expires_at,
                                  e.status AS email_status, e.sent_at, c.ctc_current_lpa, c.ctc_min_lpa, c.notice_days
                           FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id LEFT JOIN email_queue e ON e.id = q.email_id
                           WHERE q.parent_id IS NULL AND q.status <> 'cancelled' ORDER BY q.id");
        $rank = ['answered' => 0, 'declined' => 1, 'opened' => 2, 'sent' => 3, 'expired' => 4];
        $person = [];
        foreach ($leads as $l) {
            // Not sent yet: still in the email queue (or held for an address check).
            $l['out'] = $l['email_status'] === 'sent' || in_array($l['status'], ['opened', 'answered', 'declined'], true);
            $cid = (int) $l['candidate_id'];
            $p = $person[$cid] ?? null;
            if (!$p || ($rank[$l['status']] ?? 9) < ($rank[$p['status']] ?? 9) || (($rank[$l['status']] ?? 9) === ($rank[$p['status']] ?? 9) && $l['id'] > $p['id'])) {
                $person[$cid] = $l;
            }
        }
        $out = ['people' => count($person), 'queued' => 0, 'sent' => 0, 'opened' => 0, 'answered' => 0, 'declined' => 0, 'waiting' => 0, 'rate' => 0,
            'gave' => ['ctc' => 0, 'min' => 0, 'notice' => 0, 'all' => 0],
            'stage' => ['first' => 0, 'reminded' => 0, 'final' => 0, 'expired' => 0],
            'after' => ['first' => 0, 'reminder' => 0, 'final' => 0], 'median_hours' => null, 'roles' => [], 'days' => []];
        $hours = [];
        $days = [];
        foreach ($person as $p) {
            if (!$p['out']) {
                $out['queued']++;
                continue;
            }
            $out['sent']++;
            $day = substr((string) ($p['sent_at'] ?: $p['created_at']), 0, 10);
            $days[$day] ??= ['day' => $day, 'sent' => 0, 'answered' => 0, 'declined' => 0];
            $days[$day]['sent']++;
            if (in_array($p['status'], ['opened', 'answered', 'declined'], true) || $p['opened_at']) {
                $out['opened']++;
            }
            if ($p['status'] === 'answered') {
                $out['answered']++;
                $days[$day]['answered']++;
                $out['gave']['ctc'] += $p['ctc_current_lpa'] !== null ? 1 : 0;
                $out['gave']['min'] += $p['ctc_min_lpa'] !== null ? 1 : 0;
                $out['gave']['notice'] += $p['notice_days'] !== null ? 1 : 0;
                $out['gave']['all'] += $p['ctc_current_lpa'] !== null && $p['ctc_min_lpa'] !== null && $p['notice_days'] !== null ? 1 : 0;
                $at = (string) $p['answered_at'];
                $out['after'][$p['final_at'] && $at > $p['final_at'] ? 'final' : ($p['reminded_at'] && $at > $p['reminded_at'] ? 'reminder' : 'first')]++;
                $from = $p['sent_at'] ?: $p['created_at'];
                if ($at && $from) {
                    $hours[] = max(0, (strtotime($at) - strtotime((string) $from)) / 3600);
                }
            } elseif ($p['status'] === 'declined') {
                $out['declined']++;
                $days[$day]['declined']++;
            } else {
                $out['waiting']++;
                $out['stage'][$p['status'] === 'expired' || $p['expires_at'] < date('Y-m-d H:i:s') ? 'expired' : ($p['final_at'] ? 'final' : ($p['reminded_at'] ? 'reminded' : 'first'))]++;
            }
        }
        $out['rate'] = $out['sent'] ? (int) round(100 * $out['answered'] / $out['sent']) : 0;
        if ($hours) {
            sort($hours);
            $n = count($hours);
            $out['median_hours'] = round($n % 2 ? $hours[intdiv($n, 2)] : ($hours[$n / 2 - 1] + $hours[$n / 2]) / 2, 1);
        }
        krsort($days);
        $out['days'] = array_values($days);
        // By role: everyone on each open client role, whatever request reached them (most CVs come with
        // no role, get a general request, and are put on roles afterwards). Someone on two roles counts in both.
        $roles = [];
        foreach ($db->all("SELECT r.id, r.title, r.work_mode, r.ctc_max_lpa, a.candidate_id, c.ctc_min_lpa
                FROM applications a JOIN roles r ON r.id = a.role_id JOIN candidates c ON c.id = a.candidate_id
                WHERE r.status = 'open' AND r.is_pool = 0 ORDER BY r.id") as $a) {
            $rid = (int) $a['id'];
            $roles[$rid] ??= ['title' => role_title_mode($a['title'], $a['work_mode']), 'on' => 0, 'asked' => 0, 'answered' => 0, 'declined' => 0, 'within' => 0, 'budget' => $a['ctc_max_lpa']];
            $roles[$rid]['on']++;
            $p = $person[(int) $a['candidate_id']] ?? null;
            if (!$p || !$p['out']) {
                continue;
            }
            $roles[$rid]['asked']++;
            if ($p['status'] === 'answered') {
                $roles[$rid]['answered']++;
                $roles[$rid]['within'] += $a['ctc_max_lpa'] === null || $a['ctc_min_lpa'] === null || (float) $a['ctc_min_lpa'] <= (float) $a['ctc_max_lpa'] ? 1 : 0;
            } elseif ($p['status'] === 'declined') {
                $roles[$rid]['declined']++;
            }
        }
        $out['roles'] = array_values($roles);
        return $out;
    }

    /**
     * Who to ask for details on open client roles: one row per person, with their roles,
     * whether the free check found them relevant, and whether they have been asked already.
     * @return array<int, array{candidate:array, apps:array<int,array>, relevant:bool, asked:?string}>
     */
    public function askList(): array
    {
        $db = $this->app->db;
        $rows = $db->all("SELECT a.id AS app_id, a.candidate_id, r.id AS role_id, r.title, r.work_mode, r.code, c.full_name, c.email, c.do_not_contact, c.removal_requested_at, c.unreliable_at,
                                 (SELECT q.status FROM questionnaires q WHERE q.application_id = a.id AND q.status <> 'cancelled' ORDER BY q.id DESC LIMIT 1) AS qstatus
                          FROM applications a JOIN roles r ON r.id = a.role_id JOIN candidates c ON c.id = a.candidate_id JOIN stages s ON s.id = a.stage_id
                          WHERE r.is_pool = 0 AND r.status = 'open' AND s.kind = 'active'
                          ORDER BY c.full_name, r.id");
        $fit = new FitAssessor($this->app);
        // Speed (B12): anyone Claude has assessed (or is assessing) already passed the relevance check, so it isn't
        // worked out again for them on every page view; that check matches keywords and dictionaries, and was most
        // of this page's 1.4 seconds.
        $passed = array_flip(array_map('intval', array_column($db->all("SELECT application_id FROM fit_assessments WHERE status IN ('done','pending','reading','batched','skipped')"), 'application_id')));
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['candidate_id'];
            $out[$cid] ??= ['candidate' => ['id' => $cid, 'full_name' => $r['full_name'], 'email' => $r['email'],
                'blocked' => !Contact::canPutForward($r) || !$r['email']], 'apps' => [], 'relevant' => false, 'asked' => null];
            $rel = isset($passed[(int) $r['app_id']]) ? true : $fit->relevance((int) $r['app_id'])[0];
            $out[$cid]['apps'][] = ['id' => (int) $r['app_id'], 'title' => $r['title'], 'work_mode' => $r['work_mode'], 'code' => $r['code'], 'relevant' => $rel, 'qstatus' => $r['qstatus']];
            $out[$cid]['relevant'] = $out[$cid]['relevant'] || $rel;
            if ($r['qstatus'] && $r['qstatus'] !== 'expired') {
                $out[$cid]['asked'] = $r['qstatus'];
            }
        }
        // Answered recently with no role (from the thank-you email) counts as asked too.
        foreach ($out as $cid => &$p) {
            if (!$p['asked'] && $db->col("SELECT 1 FROM questionnaires WHERE candidate_id = ? AND application_id IS NULL AND status IN ('sent','opened','answered','declined')", [$cid])) {
                $p['asked'] = (string) $db->col("SELECT status FROM questionnaires WHERE candidate_id = ? AND application_id IS NULL AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [$cid]);
            }
        }
        return array_values($out);
    }
}
