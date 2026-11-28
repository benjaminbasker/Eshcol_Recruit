<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FieldExtractor;
use Eshcol\Ats\Services\Retention;

final class CandidateController extends Controller
{
    private const EDITABLE = ['full_name', 'email', 'phone', 'linkedin_url', 'current_title', 'current_company', 'exp_months', 'ctc_current_lpa', 'ctc_expected_lpa', 'ctc_min_lpa', 'notice_days', 'location', 'locality', 'qualifications'];

    public function index(): never
    {
        $f = [];
        foreach (['q', 'skill', 'position', 'level', 'qualification', 'location', 'source', 'added', 'sort', 'exp_min', 'exp_max', 'notice_max', 'ctc_max', 'removal', 'unreliable', 'future', 'scans',
                'function', 'seniority', 'open_to', 'uncategorised', 'responded'] as $k) {
            $f[$k] = trim((string) ($_GET[$k] ?? ''));
        }
        [$q, $skill] = [$f['q'], $f['skill']];
        // A reference number goes straight to the record.
        if ($q !== '' && ($ref = parse_ref($q))) {
            [$type, $rid] = $ref;
            $found = match ($type) {
                'candidate' => $this->db->col('SELECT id FROM candidates WHERE id = ?', [$rid]) ? "/candidates/$rid" : null,
                'shortlist' => $this->db->col('SELECT id FROM shortlists WHERE id = ?', [$rid]) ? "/shortlists/$rid" : null,
                default => ($c = $this->db->col('SELECT a.candidate_id FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = ?', [$rid])) ? "/candidates/$c#interviews" : null,
            };
            if ($found) {
                Http::redirect($found);
            }
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 40;
        $where = ['1=1'];
        $args = [];
        // Today's experience in months: the last known figure grown by the time since (see exp_now()).
        $exp = '(c.exp_months + IF(c.exp_as_of IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(MONTH, c.exp_as_of, CURDATE()))))';
        $order = match ($f['sort']) {
            'exp_desc' => "c.exp_months IS NULL, $exp DESC",
            'exp_asc' => "c.exp_months IS NULL, $exp ASC",
            'name' => 'c.full_name IS NULL, c.full_name',
            default => 'c.created_at DESC',
        };
        if ($f['position'] !== '') {
            // "Accounts" finds Accounts Executive and Accounts Manager: positions the CV shows they have held.
            // (Not roles they applied for: people apply across fields, which is what routing sorts out.)
            $where[] = '(c.current_title LIKE ? OR c.id IN (SELECT candidate_id FROM candidate_titles WHERE title LIKE ?))';
            // Singular stem, so "Accounts" also finds Accountant and Senior Accountant.
            $stem = mb_strlen($f['position']) > 4 ? preg_replace('/s$/i', '', $f['position']) : $f['position'];
            $like = '%' . addcslashes($stem, '%_\\') . '%';
            array_push($args, $like, $like);
        }
        if (is_numeric($f['exp_min'])) {
            $where[] = "$exp >= ?";
            $args[] = (int) round((float) $f['exp_min'] * 12);
        }
        if (is_numeric($f['exp_max'])) {
            $where[] = "$exp <= ?";
            $args[] = (int) round((float) $f['exp_max'] * 12);
        }
        foreach (level_bands() as [$name, $from, $to]) {
            if ($f['level'] === $name) {
                $where[] = "$exp >= ? AND $exp < ?";
                array_push($args, $from, $to);
            }
        }
        if ($f['qualification'] !== '') {
            // Dots and spaces don't matter (16 Nov 2026): "MCom", "M Com" and "M.Com" find the same people.
            $where[] = "REPLACE(REPLACE(c.qualifications, '.', ''), ' ', '') LIKE ?";
            $args[] = '%' . addcslashes(str_replace(['.', ' '], '', $f['qualification']), '%_\\') . '%';
        }
        // Their answer to our details request (16 Nov 2026): responded / asked, no answer yet / never asked.
        if ($f['responded'] === 'yes') {
            $where[] = "c.id IN (SELECT candidate_id FROM questionnaires WHERE status = 'answered')";
        } elseif ($f['responded'] === 'waiting') {
            $where[] = "c.id IN (SELECT candidate_id FROM questionnaires WHERE status IN ('sent','opened')) AND c.id NOT IN (SELECT candidate_id FROM questionnaires WHERE status = 'answered')";
        } elseif ($f['responded'] === 'never') {
            $where[] = 'c.id NOT IN (SELECT candidate_id FROM questionnaires)';
        }
        if ($f['location'] !== '') {
            $where[] = 'c.location = ?';
            $args[] = $f['location'];
        }
        if (is_numeric($f['notice_max'])) {
            $where[] = 'c.notice_days <= ?';
            $args[] = (int) $f['notice_max'];
        }
        if (is_numeric($f['ctc_max'])) {
            $where[] = 'COALESCE(c.ctc_min_lpa, c.ctc_expected_lpa) <= ?';
            $args[] = (float) $f['ctc_max'];
        }
        // "Came from": the job board, website, referral... ("none" = not recorded).
        if ($f['source'] === 'none') {
            $where[] = 'c.channel IS NULL';
        } elseif (isset(CHANNELS[$f['source']])) {
            $where[] = 'c.channel = ?';
            $args[] = $f['source'];
        }
        if ($f['unreliable'] === '1') {
            $where[] = 'c.unreliable_at IS NOT NULL';
        }
        if ($f['future'] === '1') {
            $where[] = "c.id IN (SELECT a.candidate_id FROM applications a JOIN stages s ON s.id = a.stage_id WHERE s.kind = 'hold')";
        }
        if ($f['removal'] === '1') {
            $where[] = 'c.removal_requested_at IS NOT NULL';
        }
        if ($f['scans'] === '1') { // 24 Nov 2026: "See who" on the My day scanned-CV note
            $where[] = "c.id IN (SELECT candidate_id FROM cv_files WHERE text_status <> 'ok')";
        }
        if (in_array($f['added'], ['7', '30', '90', '365'], true)) {
            $where[] = 'c.created_at >= NOW() - INTERVAL ' . (int) $f['added'] . ' DAY';
        }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            [$nwSql, $nwArgs] = name_words_sql('c.full_name', $q); // each word anywhere in the name (17 Nov 2026)
            $where[] = '(c.full_name LIKE ? OR ' . $nwSql . ' OR c.email_norm LIKE ? ' . (strlen($digits) >= 6 ? 'OR c.phone_norm LIKE ? ' : '') .
                'OR c.id IN (SELECT candidate_id FROM cv_files WHERE MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE)))';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, ...$nwArgs);
            $args[] = mb_strtolower($like);
            if (strlen($digits) >= 6) {
                $args[] = '%' . $digits . '%';
            }
            $args[] = $q;
        }
        if ($skill !== '') {
            $where[] = 'c.id IN (SELECT candidate_id FROM candidate_skills WHERE skill = ?)';
            $args[] = $skill;
        }
        // Categories (13 Nov 2026): field of work (first or second), seniority, and a city they live in or said they'd work in.
        if ($f['function'] !== '' && isset(\Eshcol\Ats\Services\Categoriser::functions($this->app)[$f['function']])) {
            $where[] = 'c.id IN (SELECT candidate_id FROM candidate_categories WHERE is_current = 1 AND (function_code = ? OR secondary_code = ?))';
            array_push($args, $f['function'], $f['function']);
        }
        if (isset(\Eshcol\Ats\Services\Categoriser::SENIORITY[$f['seniority']])) {
            $where[] = 'c.id IN (SELECT candidate_id FROM candidate_categories WHERE is_current = 1 AND seniority = ?)';
            $args[] = $f['seniority'];
        }
        if ($f['open_to'] !== '') {
            $where[] = '(c.location = ? OR c.id IN (SELECT candidate_id FROM candidate_categories WHERE is_current = 1 AND FIND_IN_SET(?, REPLACE(preferred_cities, \', \', \',\'))))';
            array_push($args, $f['open_to'], $f['open_to']);
        }
        if ($f['uncategorised'] === '1') {
            $where[] = 'c.id NOT IN (SELECT candidate_id FROM candidate_categories WHERE is_current = 1)';
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->col("SELECT COUNT(*) FROM candidates c WHERE $w", $args);
        $rows = $this->db->all("
            SELECT c.*, (SELECT COUNT(*) FROM applications a WHERE a.candidate_id = c.id) AS roles,
                   (SELECT GROUP_CONCAT(skill ORDER BY skill SEPARATOR '|') FROM candidate_skills s WHERE s.candidate_id = c.id) AS skills
            FROM candidates c WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $args);
        // Choices for the filters, from what is actually in the database.
        $opts = [
            'skills' => $this->db->all('SELECT skill, COUNT(*) n FROM candidate_skills GROUP BY skill ORDER BY skill'),
            'positions' => array_column($this->db->all('SELECT DISTINCT title FROM candidate_titles ORDER BY title'), 'title'),
            'qualifications' => array_column($this->db->all("SELECT DISTINCT canonical FROM skills_dictionary WHERE category = 'qualification' ORDER BY canonical"), 'canonical'),
            'locations' => array_column($this->db->all('SELECT DISTINCT location FROM candidates WHERE location IS NOT NULL ORDER BY location'), 'location'),
            'sources' => array_values(array_filter(array_column($this->db->all('SELECT DISTINCT channel FROM candidates WHERE channel IS NOT NULL'), 'channel'), fn ($x) => isset(CHANNELS[$x]))),
            'roles' => $this->db->all("SELECT r.id, r.title, r.is_pool, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.status IN ('open','on_hold','draft') ORDER BY r.is_pool, r.title"),
            'functions' => $this->db->all('SELECT f.code, f.name, COUNT(cc.id) AS n FROM functions f LEFT JOIN candidate_categories cc ON cc.is_current = 1 AND (cc.function_code = f.code OR cc.secondary_code = f.code)
                WHERE f.is_active = 1 GROUP BY f.code ORDER BY f.sort'),
        ];
        sort($opts['positions']);
        $filtered = (bool) array_filter($f, fn ($v, $k) => $v !== '' && $k !== 'sort', ARRAY_FILTER_USE_BOTH);
        $this->view('candidates/index', ['title' => 'Candidates', 'rows' => $rows, 'f' => $f, 'q' => $q, 'skill' => $skill, 'total' => $total,
            'page' => $page, 'per' => $per, 'opts' => $opts, 'filtered' => $filtered,
            'views' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? [] : $this->db->all('SELECT id, name, query FROM saved_views WHERE user_id = ? ORDER BY name', [Auth::user()['id']])]);
    }

    /**
     * One candidate's "Why this score" on a position, fetched when you open it (B12, 14 Nov 2026): the position
     * pages no longer carry hundreds of these collapsed (3.7 of 5.2 MB on Accounting Manager).
     * ?kind=score: the free score's breakdown; ?kind=fit: Claude's evidence.
     */
    public function why(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        if (($_GET['kind'] ?? '') === 'fit') {
            $f = $this->db->one('SELECT * FROM fit_assessments WHERE application_id = ?', [$appId]);
            if (!$f || $f['status'] !== 'done') {
                echo '<p class="muted small">Not assessed by Claude yet.</p>';
                exit;
            }
            $f['result'] = json_decode((string) $f['result'], true) ?: [];
            $f['breakdown'] = json_decode((string) $f['breakdown'], true) ?: [];
            $reqs = (new \Eshcol\Ats\Services\FitAssessor($this->app))->requirements((int) $a['role_id']);
            include dirname(__DIR__, 2) . '/views/partials/fit_detail.php';
            exit;
        }
        $bd = json_decode($a['score_breakdown'] ?? 'null', true);
        include dirname(__DIR__, 2) . '/views/partials/scorecard.php';
        exit;
    }

    /** Saves the current filters under a name (B15). */
    public function saveView(): never
    {
        $name = $this->str('name', 80);
        $query = (string) ($_POST['query'] ?? '');
        parse_str($query, $parts);
        $query = http_build_query(array_filter($parts, fn ($v) => is_string($v) && $v !== '' && strlen($v) < 200)); // only plain filter values
        if (!$name || $query === '') {
            Http::flash('error', $name ? 'Choose some filters first, then save them.' : 'Give the view a name.');
            Http::redirect('/candidates' . ($query !== '' ? "?$query" : ''));
        }
        $this->db->run('DELETE FROM saved_views WHERE user_id = ? AND name = ?', [Auth::user()['id'], $name]); // same name: replaced
        $this->db->insert('saved_views', ['user_id' => Auth::user()['id'], 'name' => $name, 'query' => mb_substr($query, 0, 1000)]);
        Http::flash('ok', "Saved as \"$name\". It's at the top of this page.");
        Http::redirect("/candidates?$query");
    }

    public function deleteView(int $id): never
    {
        $this->db->run('DELETE FROM saved_views WHERE id = ? AND user_id = ?', [$id, Auth::user()['id']]);
        Http::flash('ok', 'View removed.');
        Http::redirect('/candidates');
    }

    /** Adds the ticked search results to a role in one go. */
    public function bulkAdd(): never
    {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $back = (string) ($_POST['back'] ?? '/candidates');
        $back = preg_match('#^/(?![/\\\\])[^\s]*$#', $back) ? $back : '/candidates';
        if (!$roleId || !$this->db->col("SELECT id FROM roles WHERE id = ? AND status IN ('open','on_hold','draft')", [$roleId])) {
            Http::flash('error', 'Choose a role to add them to.');
            Http::redirect($back);
        }
        if (!$ids) {
            Http::flash('error', 'Tick at least one candidate.');
            Http::redirect($back);
        }
        $imp = new CandidateImporter($this->app);
        $added = 0;
        foreach (array_slice($ids, 0, 200) as $cid) {
            if ($this->db->col('SELECT id FROM candidates WHERE id = ? AND ' . \Eshcol\Ats\Services\Contact::putForwardSql('candidates')
                . ' AND ' . \Eshcol\Ats\Services\ClientRejections::notRejectedSql($this->app, 'candidates', '(SELECT client_id FROM roles WHERE id = ' . (int) $roleId . ')'), [$cid]) && $imp->attach($cid, $roleId, (int) Auth::user()['id'], 'database')) {
                $added++;
            }
        }
        Audit::log('bulk_added_to_role', 'role', $roleId, ['count' => $added]);
        Http::flash('ok', "$added candidate" . ($added === 1 ? '' : 's') . ' added to the role and scored' . ($added < count($ids) ? ' (the rest were already on it, marked do not contact, or turned down by this client before)' : '') . '.');
        Http::redirect("/roles/$roleId");
    }

    public function show(int $id): never
    {
        $c = $this->findOr404('candidates', $id);
        Audit::log('candidate_viewed', 'candidate', $id);
        $hrmsExit = $this->db->one('SELECT x.*, cl.name AS client FROM hrms_exits x LEFT JOIN clients cl ON cl.id = x.client_id WHERE x.candidate_id = ? ORDER BY x.last_day DESC LIMIT 1', [$id]);
        $files = $this->db->all('SELECT id, original_name, ext, size_bytes, text, text_status, text_source, created_at FROM cv_files WHERE candidate_id = ? ORDER BY id DESC', [$id]);
        $apps = $this->db->all('
            SELECT a.*, COALESCE(a.override_score, a.score) AS eff_score, r.title, r.code, r.engagement, r.is_pool, r.score_threshold, cl.name AS client, s.name AS stage, s.kind AS stage_kind, r.ctc_max_lpa AS role_ctc_max, r.notice_max_days AS role_notice_max, r.location AS role_location, r.work_mode AS role_work_mode, r.status AS role_status,
                   ' . \Eshcol\Ats\Services\KeyFacts::ROUND_SQL . ' AS iv_round
            FROM applications a JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id JOIN stages s ON s.id = a.stage_id
            WHERE a.candidate_id = ? AND ' . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'a.role_id') . ' ORDER BY a.applied_at DESC', [$id]);
        $skills = $this->db->all('SELECT skill FROM candidate_skills WHERE candidate_id = ? ORDER BY skill', [$id]);
        $titles = array_column($this->db->all('SELECT title FROM candidate_titles WHERE candidate_id = ? ORDER BY title', [$id]), 'title');
        $notes = $this->db->all('SELECT n.*, u.name AS author FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE entity_type = \'candidate\' AND entity_id = ? ORDER BY n.id DESC', [$id]);
        $stages = $this->db->all('SELECT * FROM stages WHERE is_active = 1 ORDER BY sort');
        $openRoles = $this->db->all("SELECT r.id, r.title, cl.name AS client FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.status = 'open' AND r.is_pool = 0 AND r.id NOT IN (SELECT role_id FROM applications WHERE candidate_id = ?) AND " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id')
            . (\Eshcol\Ats\Services\ClientRejections::ready($this->app) ? " AND NOT EXISTS (SELECT 1 FROM client_rejections cr WHERE cr.candidate_id = " . (int) $id . " AND cr.client_id = r.client_id)" : '')
            . " ORDER BY r.title", [$id]); // not a client who turned them down (20 Nov 2026)
        $this->view('candidates/show', ['title' => $c['full_name'] ?: 'Candidate', 'c' => $c, 'files' => $files, 'apps' => $apps, 'hrmsExit' => $hrmsExit,
            'clientRejections' => (new \Eshcol\Ats\Services\ClientRejections($this->app))->forCandidate($id),
            'skills' => array_column($skills, 'skill'), 'titles' => $titles, 'notes' => $notes, 'stages' => $stages,
            'screening' => $this->screening($id),
            'messages' => $this->messages($id),
            'attachDocs' => (new \Eshcol\Ats\Services\CandidateMessages($this->app))->attachableDocs($id),
            'workHistory' => (new \Eshcol\Ats\Services\AiReading($this->app))->history($id),
            'education' => (new \Eshcol\Ats\Services\AiReading($this->app))->education($id),
            'clientViews' => array_column($this->db->all('SELECT i.application_id, i.decision, i.comment, i.decided_at, i.shortlist_id FROM shortlist_items i JOIN applications a ON a.id = i.application_id WHERE a.candidate_id = ? ORDER BY i.id', [$id]), null, 'application_id'),
            'emails' => $this->db->all('SELECT e.id, e.kind, e.subject, e.status, e.hold_reason, e.sent_at, e.created_at FROM email_queue e WHERE e.candidate_id = ? AND '
                . \Eshcol\Ats\Access::emailFilter(Auth::user(), 'e') . ' ORDER BY e.id DESC LIMIT 20', [$id]), 'openRoles' => $openRoles,
            'sources' => json_decode($c['field_sources'] ?? '{}', true) ?: [], 'timeline' => $this->timeline($id, $notes),
            'category' => $this->db->one('SELECT cc.*, u.name AS reviewer FROM candidate_categories cc LEFT JOIN users u ON u.id = cc.reviewed_by WHERE cc.candidate_id = ? AND cc.is_current = 1 ORDER BY cc.id DESC LIMIT 1', [$id]),
            'categoryHistory' => $this->db->all('SELECT cc.*, u.name AS who FROM candidate_categories cc LEFT JOIN users u ON u.id = cc.created_by WHERE cc.candidate_id = ? ORDER BY cc.id DESC LIMIT 10', [$id]),
            'categoryQueued' => (string) $this->db->col('SELECT status FROM categorise_queue WHERE candidate_id = ?', [$id]),
            'consents' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? [] : (new \Eshcol\Ats\Services\Consents($this->app))->history($id),
            'cvRequest' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? null : (new \Eshcol\Ats\Services\CvRequests($this->app))->forCandidate($id),
            // Placements: the money is the owner's only (B9).
            'placements' => (Auth::user()['role'] ?? '') !== 'owner' || (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? []
                : array_column($this->db->all('SELECT * FROM placements WHERE candidate_id = ?', [$id]), null, 'application_id'),
            // Opened from Settings › Categories "Unsure: please check": how many are left, and the next one.
            'catFrom' => ($_GET['from'] ?? '') === 'categories',
            'catLeft' => (int) $this->db->col('SELECT COUNT(*) FROM candidate_categories WHERE is_current = 1 AND needs_review = 1 AND reviewed_at IS NULL'),
            'catNext' => $this->db->one('SELECT cc.candidate_id, c.full_name FROM candidate_categories cc JOIN candidates c ON c.id = cc.candidate_id
                WHERE cc.is_current = 1 AND cc.needs_review = 1 AND cc.reviewed_at IS NULL AND cc.candidate_id <> ? ORDER BY cc.id DESC LIMIT 1', [$id]),
            'catMode' => \Eshcol\Ats\Services\Categoriser::mode($this->app)] + $this->reportData($id, $files));
    }

    /** The CV report card: the free report, plus Claude's analysis if one was made. */
    private function reportData(int $id, array $files): array
    {
        $analyst = new \Eshcol\Ats\Services\CvAnalyst($this->app);
        $ai = $analyst->latest($id);
        $newest = $files[0]['id'] ?? null;
        $report = (new \Eshcol\Ats\Services\CvReport($this->app))->build($id);
        // A contractor sees only their own positions in the report, and not the analysis that lists every open role (B6).
        if (\Eshcol\Ats\Access::restricted(Auth::user())) {
            $mine = \Eshcol\Ats\Access::roleIds((int) Auth::user()['id']);
            $report['roles'] = array_values(array_filter($report['roles'] ?? [], fn ($x) => in_array((int) $x['id'], $mine, true)));
            $ai = null;
        }
        return ['report' => $report, 'ai' => $ai,
            'aiOn' => \Eshcol\Ats\Services\CvAnalyst::enabled($this->app),
            'aiStale' => $ai && $newest && (int) $ai['cv_file_id'] !== (int) $newest];
    }

    /**
     * Everything that happened with this candidate, newest first: notes, stage moves, emails,
     * screening answers, client decisions and interviews.
     * @return list<array{at:string,icon:string,text:string,sub:string,body?:string,url?:string}>
     */
    private function timeline(int $id, array $notes): array
    {
        $t = [];
        foreach ($notes as $n) {
            $t[] = ['at' => $n['created_at'], 'icon' => 'edit', 'text' => ($n['author'] ?? 'Someone') . ' added a note', 'sub' => '', 'body' => $n['body']];
        }
        foreach ($this->db->all('SELECT h.created_at, h.note, s.name AS stage, r.title, u.name AS who FROM stage_history h JOIN applications a ON a.id = h.application_id
                                 JOIN stages s ON s.id = h.to_stage_id JOIN roles r ON r.id = a.role_id LEFT JOIN users u ON u.id = h.user_id
                                 WHERE a.candidate_id = ? AND ' . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . ' ORDER BY h.id DESC LIMIT 60', [$id]) as $h) {
            $t[] = ['at' => $h['created_at'], 'icon' => 'arrow-right', 'text' => 'Moved to ' . $h['stage'], 'sub' => $h['title'] . ($h['who'] ? ' · ' . $h['who'] : '') . ($h['note'] ? ' · ' . $h['note'] : '')];
        }
        foreach ($this->db->all("SELECT e.id, e.subject, e.status, e.sent_at, e.created_at FROM email_queue e WHERE e.candidate_id = ? AND e.status IN ('sent','held','pending','failed') AND "
            . \Eshcol\Ats\Access::emailFilter(Auth::user(), 'e') . ' ORDER BY e.id DESC LIMIT 30', [$id]) as $m) {
            $label = ['sent' => 'Email sent', 'held' => 'Email waiting for you to check the address', 'pending' => 'Email queued', 'failed' => 'Email failed'][$m['status']];
            $t[] = ['at' => $m['sent_at'] ?: $m['created_at'], 'icon' => 'mail', 'text' => $label, 'sub' => $m['subject'], 'url' => '/emails/' . (int) $m['id']];
        }
        foreach ($this->db->all("SELECT q.status, q.answered_at, q.declined_at, r.title FROM questionnaires q LEFT JOIN roles r ON r.id = q.role_id WHERE q.candidate_id = ? AND q.status IN ('answered','declined') AND (q.role_id IS NULL OR " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . ")", [$id]) as $q) {
            $t[] = $q['status'] === 'answered'
                ? ['at' => $q['answered_at'], 'icon' => 'clipboard', 'text' => 'Completed their application (salary, joining time, details)', 'sub' => $q['title'] ?? 'No role named']
                : ['at' => $q['declined_at'], 'icon' => 'clipboard', 'text' => 'Said they are no longer interested', 'sub' => $q['title'] ?? 'No role named'];
        }
        foreach ($this->db->all('SELECT i.decision, i.comment, i.decided_at, r.title FROM shortlist_items i JOIN applications a ON a.id = i.application_id JOIN roles r ON r.id = a.role_id
                                 WHERE a.candidate_id = ? AND i.decided_at IS NOT NULL AND ' . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id'), [$id]) as $d) {
            $t[] = ['at' => $d['decided_at'], 'icon' => 'star', 'text' => 'Client: ' . (\Eshcol\Ats\Services\Shortlists::DECISIONS[$d['decision']] ?? $d['decision']), 'sub' => $d['title'], 'body' => (string) $d['comment']];
        }
        foreach ($this->db->all("SELECT i.round, i.kind, i.starts_at, i.status, i.outcome, i.created_at, r.title FROM interviews i JOIN applications a ON a.id = i.application_id JOIN roles r ON r.id = a.role_id
                                 WHERE a.candidate_id = ? AND i.status IN ('booked','done','no_show') AND " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id'), [$id]) as $i) {
            $t[] = ['at' => $i['starts_at'] ?: $i['created_at'], 'icon' => 'calendar', 'text' => 'Round ' . $i['round'] . ' interview' . ($i['status'] === 'booked' ? (strtotime((string) $i['starts_at']) > time() ? ' booked' : '') : ': ' . (\Eshcol\Ats\Services\Interviews::OUTCOMES[$i['outcome']] ?? 'done')), 'sub' => $i['title']];
        }
        $c = $this->db->one('SELECT unreliable_at, unreliable_reason, unreliable_note FROM candidates WHERE id = ?', [$id]);
        if ($c && $c['unreliable_at']) {
            $t[] = ['at' => $c['unreliable_at'], 'icon' => 'flag', 'text' => 'Marked unreliable', 'sub' => \Eshcol\Ats\Services\Reliability::describe($c)];
        }
        usort($t, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($t, 0, 80);
    }

    public function edit(int $id): never
    {
        $c = $this->findOr404('candidates', $id);
        $this->view('candidates/edit', ['title' => 'Edit ' . ($c['full_name'] ?: 'candidate'), 'c' => $c, 'errors' => []]);
    }

    public function update(int $id): never
    {
        $c = $this->findOr404('candidates', $id);
        $errors = [];
        $data = [
            'full_name' => $this->str('full_name', 160),
            'email' => ($e = $this->str('email', 190)) ? mb_strtolower($e) : null,
            'phone' => $this->str('phone', 30),
            'linkedin_url' => ($li = $this->str('linkedin_url', 255)) ? (FieldExtractor::linkedin($li) ?? $li) : null,
            'current_title' => $this->str('current_title', 160),
            'current_company' => $this->str('current_company', 160),
            'exp_months' => ($y = $this->num('exp_years')) !== null ? (int) round($y * 12) : null,
            // The form shows today's figure, so whatever is saved is true as of today.
            'exp_as_of' => $this->num('exp_years') !== null ? date('Y-m-d') : null,
            'ctc_current_lpa' => $this->lpa('ctc_current_lpa', $errors),
            'ctc_expected_lpa' => $this->lpa('ctc_expected_lpa', $errors),
            'ctc_min_lpa' => $this->lpa('ctc_min_lpa', $errors),
            'notice_days' => ($n = $this->num('notice_days')) !== null ? (int) $n : null,
            'location' => $this->str('location', 120),
            'locality' => ($l = $this->str('locality', 120)) ? (new \Eshcol\Ats\Services\Skills($this->db))->canonical($l, 'locality') : null,
            'qualifications' => $this->str('qualifications', 255),
            'channel' => isset(CHANNELS[$_POST['channel'] ?? '']) ? $_POST['channel'] : null,
            'keep_note' => $this->str('keep_note', 255),
            'do_not_contact' => isset($_POST['do_not_contact']) ? 1 : 0,
        ];
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That email address does not look right.';
        }
        if ($data['linkedin_url'] && !FieldExtractor::linkedin($data['linkedin_url'])) {
            $errors['linkedin_url'] = 'Paste a LinkedIn profile link, such as linkedin.com/in/their-name.';
        }
        if ($errors) {
            $this->view('candidates/edit', ['title' => 'Edit candidate', 'c' => $data + $c, 'errors' => $errors]);
        }
        $sources = json_decode($c['field_sources'] ?? '{}', true) ?: [];
        $compare = $c;
        $compare['exp_months'] = exp_now($c); // the form showed today's figure
        foreach (self::EDITABLE as $k) {
            if ((string) ($data[$k] ?? '') !== (string) ($compare[$k] ?? '')) {
                $sources[$k] = 'you';
            }
        }
        $data['first_name'] = $data['full_name'] ? explode(' ', $data['full_name'])[0] : null;
        $data['email_norm'] = $data['email'];
        $data['phone_norm'] = FieldExtractor::normalisePhone($data['phone']);
        $data['field_sources'] = json_encode($sources);
        $data['last_confirmed_at'] = date('Y-m-d H:i:s');
        $this->db->update('candidates', $data, $id);
        (new Retention($this->app))->touch($id);
        $this->rescoreCandidate($id);
        Audit::log('candidate_updated', 'candidate', $id);
        Http::flash('ok', 'Details saved and scores updated.');
        Http::redirect("/candidates/$id");
    }

    /** Marks a candidate unreliable (or clears the mark). */
    public function unreliable(int $id): never
    {
        $this->findOr404('candidates', $id);
        $svc = new \Eshcol\Ats\Services\Reliability($this->app);
        if (($_POST['clear'] ?? '') === '1') {
            $svc->clear($id, (int) Auth::user()['id']);
            Http::flash('ok', 'Mark cleared. They can be approached again.');
        } else {
            $svc->mark($id, (string) ($_POST['reason'] ?? 'other'), $this->str('note', 300), (int) Auth::user()['id']);
            Http::flash('ok', 'Marked unreliable. They are now left out of searches, shortlists, screening and interview invitations.');
        }
        Http::redirect("/candidates/$id");
    }

    /** Clears a candidate's red list mark (it is set automatically by a hard rejection, never manually). */
    public function redList(int $id): never
    {
        $this->findOr404('candidates', $id);
        (new \Eshcol\Ats\Services\RedList($this->app))->clear($id, (int) Auth::user()['id']);
        Http::flash('ok', 'Cleared. They can be put forward again.');
        Http::redirect("/candidates/$id");
    }

    /** Lifts a client's no on this candidate (e.g. the client asks to see them again). */
    public function liftClientRejection(int $id): never
    {
        $this->findOr404('candidates', $id);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $n = (new \Eshcol\Ats\Services\ClientRejections($this->app))->lift($id, $clientId, (int) Auth::user()['id']);
        Http::flash($n ? 'ok' : 'info', $n ? 'Lifted. Their CV can be sent to this client again.' : 'Nothing to lift.');
        Http::redirect("/candidates/$id");
    }

    public function note(int $id): never
    {
        $this->findOr404('candidates', $id);
        $body = $this->str('body', 5000);
        if ($body) {
            $this->db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $id, 'user_id' => Auth::user()['id'], 'body' => $body]);
        }
        Http::redirect("/candidates/$id#notes");
    }

    /** Which CV goes to clients when there are several (B13, 15 Nov 2026). cv_id 0: back to "the newest". */
    public function preferredCv(int $id): never
    {
        $this->findOr404('candidates', $id);
        $cv = (int) Http::input('cv_id');
        if ($cv && !$this->db->col('SELECT 1 FROM cv_files WHERE id = ? AND candidate_id = ?', [$cv, $id])) {
            Http::abort(404);
        }
        $this->db->update('candidates', ['preferred_cv_id' => $cv ?: null], $id);
        Audit::log('preferred_cv', 'candidate', $id, ['cv' => $cv ?: null]);
        Http::flash('ok', $cv ? 'This CV will go to clients from now on (shortlists already sent keep theirs).' : 'Clients will get the newest CV again.');
        Http::redirect("/candidates/$id#tab-cv");
    }

    /** Streams a stored CV to a signed-in user. Files are never reachable by URL. */
    public function downloadCv(int $id): never
    {
        $f = $this->findOr404('cv_files', $id);
        $path = $this->app->storage($f['storage_path']);
        if (!is_file($path)) {
            Http::abort(404, 'The file is missing from storage.');
        }
        Audit::log('cv_downloaded', 'candidate', (int) $f['candidate_id'], ['cv_file' => $id, 'inline' => isset($_GET['view'])]);
        $inline = isset($_GET['view']) && in_array($f['ext'], ['pdf', 'jpg', 'jpeg', 'png'], true);
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        header('Content-Type: ' . ($types[$f['ext']] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]/', '_', $f['original_name']) . '"');
        if ($inline) {
            // Shown inside the candidate page: allow framing by this app only. Chrome's PDF viewer
            // needs no further CSP, and the content type is fixed, so nothing here can run script.
            header_remove('Content-Security-Policy');
            header("Content-Security-Policy: frame-ancestors 'self'");
            header('X-Frame-Options: SAMEORIGIN');
        } else {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        }
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Text read from a scanned CV in the recruiter's browser (OCR), sent back to be saved. */
    public function saveCvText(int $id): never
    {
        $f = $this->findOr404('cv_files', $id);
        $text = trim((string) ($_POST['text'] ?? ''));
        if (mb_strlen($text) < 40) {
            Http::json(['ok' => false, 'message' => 'Too little text was read to be useful.'], 422);
        }
        $text = mb_substr($text, 0, 200000);
        $this->db->update('cv_files', ['text' => $text, 'text_status' => 'ok', 'text_source' => 'browser_ocr'], $id);

        $importer = new CandidateImporter($this->app);
        $info = (new FieldExtractor($importer->skills()))->extract($text, $f['original_name']);
        $c = $this->db->one('SELECT * FROM candidates WHERE id = ?', [$f['candidate_id']]);
        $sources = json_decode($c['field_sources'] ?? '{}', true) ?: [];
        $fill = [];
        foreach ($info['fields'] as $k => $v) {
            if (($c[$k] ?? null) === null || $c[$k] === '' || ($sources[$k] ?? '') === 'filename') {
                $fill[$k] = $v;
                $sources[$k] = 'ocr';
            }
        }
        if (isset($fill['email'])) {
            $fill['email_norm'] = mb_strtolower($fill['email']);
        }
        if (isset($fill['exp_months'])) {
            $fill['exp_as_of'] = date('Y-m-d');
        }
        if (isset($fill['phone'])) {
            $fill['phone_norm'] = FieldExtractor::normalisePhone($fill['phone']);
        }
        $fill['field_sources'] = json_encode($sources);
        $this->db->update('candidates', $fill, (int) $c['id']);
        foreach ($info['skills'] as $s) {
            $this->db->run('INSERT IGNORE INTO candidate_skills (candidate_id, skill) VALUES (?, ?)', [$c['id'], $s]);
        }
        foreach ($info['titles'] ?? [] as $t) {
            $this->db->run('INSERT IGNORE INTO candidate_titles (candidate_id, title) VALUES (?, ?)', [$c['id'], $t]);
        }
        $this->rescoreCandidate((int) $c['id']);
        // Now that the scan is readable, its experience can route it to the right roles.
        (new \Eshcol\Ats\Services\RoleRouter($this->app))->route((int) $c['id'], null, (int) \Eshcol\Ats\Auth::user()['id']);
        Audit::log('cv_ocr_saved', 'candidate', (int) $c['id']);
        Http::json(['ok' => true, 'filled' => array_keys(array_diff_key($fill, ['field_sources' => 1, 'email_norm' => 1, 'phone_norm' => 1]))]);
    }

    /** Permanent deletion, e.g. when a candidate asks to be removed. */
    public function destroy(int $id): never
    {
        Auth::requireOwner();
        $this->findOr404('candidates', $id);
        if (($_POST['confirm'] ?? '') !== 'DELETE') {
            Http::flash('error', 'Type DELETE to confirm. Nothing was deleted.');
            Http::redirect("/candidates/$id#delete");
        }
        $requested = ($_POST['reason'] ?? '') === 'requested';
        (new Retention($this->app))->delete($id, $requested ? 'requested' : 'manual', $requested);
        Http::flash('ok', 'Deleted permanently, including their CV files' . ($requested ? '. If they apply again, you will be told they once asked to be removed.' : '.'));
        Http::redirect('/candidates');
    }

    /**
     * "Delete the ticked" on the removal list (24 Nov 2026): owner only, type DELETE. Only people who asked to be
     * removed; anyone still in a live process with a client is skipped and named.
     */
    public function bulkDelete(): never
    {
        Auth::requireOwner();
        $back = '/candidates?removal=1';
        if (($_POST['confirm'] ?? '') !== 'DELETE') {
            Http::flash('error', 'Type DELETE to confirm. Nothing was deleted.');
            Http::redirect($back);
        }
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!$ids) {
            Http::flash('error', 'Tick at least one candidate.');
            Http::redirect($back);
        }
        $res = (new \Eshcol\Ats\Services\SelfRemoval($this->app))->bulk($ids, (int) Auth::user()['id']);
        $msg = $res['deleted'] . ' deleted permanently, including their CV files. If they apply again, you will be told they once asked to be removed.';
        if ($res['skipped']) {
            $msg .= ' Not deleted, because they are in a live process: ' . implode('; ', array_map(fn ($s) => $s['name'] . ' (' . implode(', ', $s['why']) . ')', $res['skipped']))
                . '. Close those with the client first, then delete them.';
        }
        Http::flash($res['skipped'] && !$res['deleted'] ? 'error' : 'ok', $msg);
        Http::redirect($back);
    }

    /**
     * Opens WhatsApp with a ready-written message (you press send there). Notes it on the
     * candidate, so the history shows who was messaged about what.
     */
    public function whatsapp(int $id): never
    {
        $c = $this->findOr404('candidates', $id);
        if (\Eshcol\Ats\Services\Contact::silenced($c)) {
            Http::flash('error', 'Not opened: this candidate is marked do not contact, or asked us not to keep their details.');
            Http::redirect("/candidates/$id");
        }
        $num = \Eshcol\Ats\Services\WhatsApp::number($c);
        if (!$num) {
            Http::flash('error', 'No mobile number that WhatsApp can use. Add it under "Edit and confirm details" (with +country code if outside India).');
            Http::redirect("/candidates/$id");
        }
        $appId = (int) ($_GET['app'] ?? 0);
        $application = $appId ? $this->db->one('SELECT * FROM applications WHERE id = ? AND candidate_id = ?', [$appId, $id]) : null;
        if ($application && !\Eshcol\Ats\Access::allows(Auth::user(), 'application', (int) $application['id'])) {
            $application = null; // a contractor writes only about their own positions
        }
        $messages = (new \Eshcol\Ats\Services\WhatsApp($this->app))->messages($c, $application ?: null, (string) Auth::user()['name']);
        $m = $messages[(string) ($_GET['t'] ?? 'hello')] ?? $messages['hello'];
        $role = $application ? (string) $this->db->col('SELECT title FROM roles WHERE id = ?', [$application['role_id']]) : '';
        $this->db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $id, 'user_id' => Auth::user()['id'],
            'body' => 'WhatsApp opened: ' . $m['label'] . ($role !== '' ? " ($role)" : '') . '.']);
        Audit::log('whatsapp_opened', 'candidate', $id, ['t' => (string) ($_GET['t'] ?? 'hello')]);
        Http::redirect('https://wa.me/' . $num . '?text=' . rawurlencode($m['text']));
    }

    /** Their messages, newest first; opening the page marks them read (the ones new until now keep their "new" mark this once). */
    private function messages(int $candidateId): array
    {
        $svc = new \Eshcol\Ats\Services\CandidateMessages($this->app);
        $rows = $svc->thread($candidateId);
        $svc->markRead($candidateId);
        return $rows;
    }

    /** Latest questionnaire per application, with its answers: [application_id => row + answers]. */
    private function screening(int $candidateId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM questionnaires WHERE candidate_id = ? AND status <> 'cancelled' ORDER BY id", [$candidateId]) as $q) {
            $q['answers'] = $this->db->all('SELECT prompt, answer, flagged FROM questionnaire_answers WHERE questionnaire_id = ? ORDER BY sort', [$q['id']]);
            $out[(int) $q['application_id']] = $q;
        }
        return $out;
    }

    /** Manually check every open role for one this candidate fits. */
    public function route(int $id): never
    {
        $this->findOr404('candidates', $id);
        $added = (new \Eshcol\Ats\Services\RoleRouter($this->app))->route($id, null, (int) Auth::user()['id']);
        Http::flash($added ? 'ok' : 'info', $added
            ? \Eshcol\Ats\Services\RoleRouter::describe($added, 'Added to')
            : 'No other open role fits this candidate\'s experience and must-haves right now, and they are already where they belong.');
        Http::redirect("/candidates/$id");
    }

    public function addToRole(int $id): never
    {
        $this->findOr404('candidates', $id);
        $roleId = (int) ($_POST['role_id'] ?? 0);
        if (!$this->db->col("SELECT id FROM roles WHERE id = ? AND status IN ('open','on_hold','draft')", [$roleId])) {
            Http::flash('error', 'Choose an open role.');
            Http::redirect("/candidates/$id");
        }
        $added = (new CandidateImporter($this->app))->attach($id, $roleId, (int) Auth::user()['id'], 'database');
        Audit::log('added_from_database', 'role', $roleId, ['candidate' => $id]);
        $reason = $added ? $this->db->col("SELECT stage_reason FROM applications WHERE candidate_id = ? AND role_id = ?", [$id, $roleId]) : null;
        Http::flash('ok', !$added ? 'Already on that role.' : ($reason ? "Added, but screened out straight away: $reason" : 'Added to the role and scored.'));
        Http::redirect("/candidates/$id");
    }

    /** The reason a candidate was not taken forward on a role (Not suitable, Withdrew), kept for future reference. */
    public function reason(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $reason = $this->str('reason', 255);
        $this->db->update('applications', ['stage_reason' => $reason, 'stage_reason_at' => $reason ? date('Y-m-d H:i:s') : null], $appId);
        // Also on the stage history, so the timeline says why.
        $h = $this->db->one('SELECT id FROM stage_history WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$appId]);
        if ($h) {
            $this->db->run('UPDATE stage_history SET note = ? WHERE id = ?', [$reason, $h['id']]);
        }
        // "Why rejected by client?": shown with the client's rejection on their page (20 Nov 2026).
        if (\Eshcol\Ats\Services\ClientRejections::ready($this->app)) {
            $this->db->run("UPDATE client_rejections SET note = ? WHERE application_id = ? AND source = 'stage'", [$reason, $appId]);
        }
        Audit::log('stage_reason_saved', 'candidate', (int) $a['candidate_id'], ['role' => (int) $a['role_id']]);
        if (($_POST['ajax'] ?? '') === '1') {
            Http::json(['ok' => true]);
        }
        Http::flash('ok', $reason ? 'Reason saved.' : 'Reason cleared.');
        $back = (string) ($_POST['back'] ?? '');
        Http::redirect(preg_match('#^/(?![/\\\\])[^\s]*$#', $back) ? $back : '/candidates/' . $a['candidate_id']);
    }

    /**
     * Moves an application to a stage. A closing stage (Not suitable, Withdrew, Future requirement)
     * keeps the reason given; moving back into play clears it. Any move you make counts as having
     * reviewed their answers, so it leaves "Waiting on you".
     */
    private function moveStage(array $a, int $to, ?string $note): void
    {
        $u = Auth::user();
        $mover = new \Eshcol\Ats\Services\StageMover($this->app);
        $byOwner = ($u['role'] ?? '') === 'owner';
        foreach ($mover->move($a, $to, $note, (int) $u['id'], $byOwner) as $msg) {
            Http::flash('ok', $msg);
        }
        // The same decision on their other positions with this client (17 Nov 2026).
        foreach ($mover->mirror($a, $to, $note, (int) $u['id'], $byOwner) as $msg) {
            Http::flash('ok', $msg);
        }
        // A hard rejection red-lists them for every position, any client (18 Nov 2026).
        foreach ($mover->redList($a, $to, $note, (int) $u['id'], $byOwner) as $msg) {
            Http::flash('ok', $msg);
        }
        // The client turned them down: never sent to that client again (20 Nov 2026).
        foreach ($mover->clientRejected($a, $to, $note, (int) $u['id'], $byOwner) as $msg) {
            Http::flash('ok', $msg);
        }
        (new \Eshcol\Ats\Services\Questionnaires($this->app))->markReviewed((int) $a['id'], (int) $a['candidate_id'], (int) $u['id']);
    }

    /**
     * Your decision on a completed application: approve (moves on from the first stage), keep for
     * a future requirement, or not suitable (both need a reason). Reviewing from "Waiting on you"
     * takes you straight to the next one waiting.
     */
    public function review(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $decision = (string) ($_POST['decision'] ?? '');
        $reason = $this->str('reason', 255);
        $name = (string) ($this->db->col('SELECT full_name FROM candidates WHERE id = ?', [$a['candidate_id']]) ?: 'Candidate');
        $back = '/candidates/' . $a['candidate_id'] . '?review=' . $appId . (($_POST['from'] ?? '') === 'dashboard' ? '&from=dashboard' : '') . '#review-' . $appId;
        if (in_array($decision, ['future', 'unsuitable'], true) && !$reason) {
            Http::flash('error', $decision === 'future' ? 'Add a short note on why they are worth keeping, then choose Future requirement again.' : 'Add the reason they are not suitable, then choose Not suitable again.');
            Http::redirect($back);
        }
        if ($decision === 'approve') {
            // From the first stage they move on to the next one (e.g. Applied → Screening); further on, they stay.
            $first = $this->db->one("SELECT id, sort FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
            $to = (int) $a['stage_id'];
            if ($first && (int) $a['stage_id'] === (int) $first['id']) {
                $to = (int) ($this->db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 AND sort > ? ORDER BY sort LIMIT 1", [$first['sort']]) ?: $to);
            }
            $this->moveStage($a, $to, $reason ?: 'Details reviewed and approved');
            $msg = "$name approved" . ($to !== (int) $a['stage_id'] ? ' and moved to ' . $this->db->col('SELECT name FROM stages WHERE id = ?', [$to]) : '') . '.';
        } elseif ($decision === 'future' || $decision === 'unsuitable') {
            $kind = $decision === 'future' ? 'hold' : 'fail';
            $prefer = \Eshcol\Ats\Services\Stages::id($this->app, $kind === 'fail' ? \Eshcol\Ats\Services\Stages::NOT_SUITABLE : \Eshcol\Ats\Services\Stages::FUTURE) ?? 0;
            $to = (int) $this->db->col('SELECT id FROM stages WHERE kind = ? AND is_active = 1 ORDER BY id = ? DESC, sort LIMIT 1', [$kind, $prefer]);
            if (!$to) {
                Http::flash('error', 'There is no ' . ($decision === 'future' ? 'Future requirement' : 'Not suitable') . ' stage. Add it in Settings › Stages.');
                Http::redirect($back);
            }
            $this->moveStage($a, $to, $reason);
            $msg = "$name: " . ($decision === 'future' ? 'kept for a future requirement.' : 'marked not suitable.');
        } else {
            Http::abort(422, 'Unknown decision.');
        }
        Audit::log('application_reviewed', 'candidate', (int) $a['candidate_id'], ['role' => (int) $a['role_id'], 'decision' => $decision]);
        if (($_POST['from'] ?? '') === 'dashboard') {
            $next = (new \Eshcol\Ats\Services\Questionnaires($this->app))->pendingReviews(200);
            if ($next) {
                $n = $next[0];
                Http::flash('ok', $msg . ' Next to review: ' . ($n['full_name'] ?: 'candidate') . ' (' . count($next) . ' left).');
                Http::redirect(self::reviewUrl($n) . '&from=dashboard' . ($n['application_id'] ? '#review-' . (int) $n['application_id'] : '#their-details'));
            }
            Http::flash('ok', $msg . ' That was the last one waiting.');
            Http::redirect('/');
        }
        Http::flash('ok', $msg);
        Http::redirect('/candidates/' . $a['candidate_id'] . '#review-' . $appId);
    }

    /** The page for reviewing one completed application (without the #anchor). */
    public static function reviewUrl(array $p): string
    {
        return '/candidates/' . (int) $p['candidate_id'] . '?' . ($p['application_id'] ? 'review=' . (int) $p['application_id'] : 'review=details');
    }

    /** Details given with no role: you looked at them. */
    public function detailsReviewed(int $candidateId): never
    {
        $this->findOr404('candidates', $candidateId);
        (new \Eshcol\Ats\Services\Questionnaires($this->app))->markReviewed(null, $candidateId, (int) Auth::user()['id']);
        if (($_POST['from'] ?? '') === 'dashboard') {
            $next = (new \Eshcol\Ats\Services\Questionnaires($this->app))->pendingReviews(200);
            if ($next) {
                $n = $next[0];
                Http::flash('ok', 'Marked as reviewed. Next to review: ' . ($n['full_name'] ?: 'candidate') . ' (' . count($next) . ' left).');
                Http::redirect(self::reviewUrl($n) . '&from=dashboard' . ($n['application_id'] ? '#review-' . (int) $n['application_id'] : '#their-details'));
            }
            Http::flash('ok', 'Marked as reviewed. That was the last one waiting.');
            Http::redirect('/');
        }
        Http::flash('ok', 'Marked as reviewed.');
        Http::redirect("/candidates/$candidateId#their-details");
    }

    /** "They agreed" / "Not yet": permission to put them forward for this position, recorded after a call (B11). */
    public function permission(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $yes = ($_POST['permission'] ?? '') === 'yes';
        (new \Eshcol\Ats\Services\SubmissionChecks($this->app))->setPermission($appId, $yes, 'phone', (int) Auth::user()['id']);
        $this->db->insert('notes', ['entity_type' => 'candidate', 'entity_id' => $a['candidate_id'], 'user_id' => Auth::user()['id'],
            'body' => $yes ? 'Agreed to be put forward to the client (recorded after a call).' : 'Asked us to check with them before putting them forward.']);
        Http::flash('ok', $yes ? 'Recorded: they agreed to be put forward.' : 'Recorded: check with them before putting them forward.');
        Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#app-' . $appId);
    }

    public function stage(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        // "Client interview · Round 2" (posted as "stage:round", 20 Nov 2026): close the earlier round as passed and
        // invite them to choose a round 2 time, or open the booking form when the client hasn't given times yet.
        if (preg_match('/^(\d+):(\d)$/', (string) ($_POST['stage_id'] ?? ''), $m) && \Eshcol\Ats\Services\Stages::is($this->app, (int) $m[1], \Eshcol\Ats\Services\Stages::INTERVIEW)) {
            $u = Auth::user();
            [$ok, $msg, $book] = (new \Eshcol\Ats\Services\Interviews($this->app))->moveToRound($appId, (int) $m[2], (int) $u['id'], ($u['role'] ?? '') === 'owner');
            if (($_POST['ajax'] ?? '') === '1') {
                Http::json(['ok' => $ok, 'message' => $msg]);
            }
            Http::flash($ok ? 'ok' : 'error', $msg);
            Http::redirect($book ? '/candidates/' . (int) $a['candidate_id'] . '?round=' . (int) $m[2] . '&app=' . $appId . '#interviews' : self::backToRow((string) ($_POST['back'] ?? ''), $appId, '/roles/' . (int) $a['role_id']));
        }
        $to = (int) ($_POST['stage_id'] ?? 0);
        if (!$this->db->col('SELECT id FROM stages WHERE id = ?', [$to])) {
            Http::abort(422, 'Unknown stage.');
        }
        // Our own team and staffing: Eshcol makes the offer, so "Offer made" needs the owner's approval first (15 Nov 2026).
        if ($why = (new \Eshcol\Ats\Services\StageMover($this->app))->blocked($appId, $to)) {
            if (($_POST['ajax'] ?? '') === '1') {
                Http::json(['ok' => false, 'error' => $why], 409);
            }
            Http::flash('error', $why);
            Http::redirect('/candidates/' . (int) $a['candidate_id'] . '#decide-' . $appId);
        }
        $this->moveStage($a, $to, $this->str('note', 255));
        if (($_POST['ajax'] ?? '') === '1') {
            Http::json(['ok' => true, 'stage_id' => $to]);
        }
        // Only ever return to a page on this app (a path starting with one slash), and to this candidate's row in it
        // (17 Nov 2026: changing a stage from a list sent you back to the top).
        $back = (string) ($_POST['back'] ?? '');
        Http::redirect(self::backToRow($back, $appId, '/roles/' . (int) $a['role_id']));
    }

    /** $back (a path on this app, else $fallback) with "#app-{id}" for the row, replacing any anchor it had. Pure. */
    public static function backToRow(string $back, int $appId, string $fallback): string
    {
        $path = preg_match('#^/(?![/\\\\])[^\s]*$#', $back) ? $back : $fallback;
        return strtok($path, '#') . '#app-' . $appId;
    }

    public function override(int $appId): never
    {
        $a = $this->findOr404('applications', $appId);
        $score = $this->num('override_score');
        $reason = $this->str('override_reason', 255);
        if ($score !== null && !$reason) {
            Http::flash('error', 'Add a short reason when you set your own score, so the team knows why.');
            Http::redirect('/candidates/' . $a['candidate_id']);
        }
        $this->db->update('applications', [
            'override_score' => $score === null ? null : max(0, min(100, (int) $score)),
            'override_by' => $score === null ? null : Auth::user()['id'],
            'override_reason' => $score === null ? null : $reason,
        ], $appId);
        Audit::log('score_override', 'candidate', (int) $a['candidate_id'], ['role' => $a['role_id'], 'score' => $score, 'reason' => $reason]);
        Http::flash('ok', $score === null ? 'Back to the calculated score.' : 'Your score is saved and shown in place of the calculated one.');
        Http::redirect('/candidates/' . $a['candidate_id']);
    }

    private function rescoreCandidate(int $candidateId): void
    {
        $imp = new CandidateImporter($this->app);
        foreach ($this->db->all('SELECT id FROM applications WHERE candidate_id = ?', [$candidateId]) as $r) {
            $imp->scoreApplication((int) $r['id']);
        }
    }
}
