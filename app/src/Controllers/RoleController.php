<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Categoriser;
use Eshcol\Ats\Services\FunctionRouting;
use Eshcol\Ats\Services\Scorer;
use Eshcol\Ats\Services\Skills;

final class RoleController extends Controller
{
    private const KINDS = ['must' => 'must_haves', 'nice' => 'nice_to_haves', 'qualification' => 'qualifications', 'location' => 'extra_locations'];

    public function index(): never
    {
        $status = in_array($_GET['status'] ?? '', ['open', 'on_hold', 'filled', 'cancelled', 'draft', 'all', 'pools'], true) ? $_GET['status'] : 'open';
        $where = $status === 'pools' ? 'r.is_pool = 1' : ($status === 'all' ? 'r.is_pool = 0' : 'r.is_pool = 0 AND r.status = ?');
        // My positions or everyone's (F2), the same choice as on My day. Contractors only ever see theirs anyway.
        $me = Auth::user();
        if (in_array($_GET['view'] ?? '', ['mine', 'all'], true)) {
            $_SESSION['day_view'] = $_GET['view'];
        }
        $view = $_SESSION['day_view'] ?? (($me['role'] ?? '') === 'owner' ? 'all' : 'mine');
        if ($view === 'mine' && $status !== 'pools' && !\Eshcol\Ats\Access::restricted($me)) {
            $where .= ' AND r.owner_user_id = ' . (int) $me['id'];
        }
        // Client recruitment · Staffing · Our team (15 Nov 2026): counts for the tabs, then the chosen type.
        $params = in_array($status, ['all', 'pools'], true) ? [] : [$status];
        $typeCounts = [];
        if ($status !== 'pools') {
            foreach ($this->db->all("SELECT r.engagement, COUNT(*) n FROM roles r WHERE $where AND " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . ' GROUP BY r.engagement', $params) as $tc) {
                $typeCounts[$tc['engagement']] = (int) $tc['n'];
            }
        }
        $type = isset(\Eshcol\Ats\Services\Engagement::TYPES[$_GET['type'] ?? '']) && $status !== 'pools' ? $_GET['type'] : '';
        if ($type !== '') {
            $where .= " AND r.engagement = '$type'";
        }
        $roles = $this->db->all("
            SELECT r.*, c.name AS client, c.is_self, u.name AS owner,
                   COUNT(a.id) AS cvs, SUM(a.score >= r.score_threshold) AS above,
                   SUM(" . \Eshcol\Ats\Services\Shortlists::shortlistedSql($this->app, 'st') . ") AS shortlisted,
                   SUM(" . \Eshcol\Ats\Services\Stages::sqlIs($this->app, 'st.id', \Eshcol\Ats\Services\Stages::SHORTLISTED) . " AND NOT EXISTS (SELECT 1 FROM shortlist_items i JOIN shortlists sl ON sl.id = i.shortlist_id
                       WHERE i.application_id = a.id AND sl.status <> 'draft')) AS ready
            FROM roles r JOIN clients c ON c.id = r.client_id
            LEFT JOIN users u ON u.id = r.owner_user_id
            LEFT JOIN applications a ON a.role_id = r.id
            LEFT JOIN stages st ON st.id = a.stage_id
            WHERE $where AND " . \Eshcol\Ats\Access::roleFilter(Auth::user(), 'r.id') . " GROUP BY r.id ORDER BY " . ($status === 'pools' ? 'r.title' : 'r.created_at DESC'), in_array($status, ['all', 'pools'], true) ? [] : [$status]);
        $this->view('roles/index', ['title' => 'Roles', 'roles' => $roles, 'status' => $status, 'view' => $view, 'type' => $type, 'typeCounts' => $typeCounts]);
    }

    public function create(): never
    {
        $clients = $this->clients();
        if (!$clients) {
            Http::flash('info', 'Add the client first, then create the role.');
            Http::redirect('/clients/new');
        }
        $role = ['client_id' => (int) ($_GET['client'] ?? 0), 'engagement' => isset(\Eshcol\Ats\Services\Engagement::TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : 'placement', 'status' => 'open', 'positions' => 1, 'score_threshold' => 50, 'accepts_routed' => 1, 'opened_at' => date('Y-m-d'), 'location_scope' => null, 'function_codes' => null,
            'exp_allow_below' => $this->app->setting('exp_allow_below_default', '1'), 'exp_allow_above' => $this->app->setting('exp_allow_above_default', '5')];
        $this->view('roles/form', ['title' => 'New role', 'role' => $role, 'criteria' => [], 'clients' => $clients, 'users' => $this->users(), 'errors' => [], 'weights' => Scorer::DEFAULT_WEIGHTS]);
    }

    public function store(): never
    {
        [$data, $criteria, $errors] = $this->validate();
        $jd = $this->jdFromRequest($data);
        if ($errors) {
            $this->view('roles/form', ['title' => 'New role', 'jdFile' => $jd, 'role' => $data, 'criteria' => $criteria, 'clients' => $this->clients(), 'users' => $this->users(), 'errors' => $errors, 'weights' => json_decode($data['weights'] ?? '[]', true) ?: Scorer::DEFAULT_WEIGHTS]);
        }
        $id = $this->db->tx(function () use ($data, $criteria) {
            $id = $this->db->insert('roles', $data);
            $client = (string) $this->db->col('SELECT name FROM clients WHERE id = ?', [$data['client_id']]);
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $client), 0, 3)) ?: 'ROL';
            $this->db->update('roles', ['code' => sprintf('%s-%04d', $prefix, $id)], $id);
            $this->saveCriteria($id, $criteria);
            return $id;
        });
        Audit::log('role_created', 'role', $id);
        if (isset($_POST['daily_sweep'])) {
            \Eshcol\Ats\Services\DatabaseSweep::set($this->app, $id, true); // the morning sweep (23 Nov 2026)
        }
        $this->attachJd($id, $jd);
        Http::flash('ok', 'Role created. Upload CVs to it, or check who you already have on file.');
        Http::redirect("/roles/$id");
    }

    public function show(int $id): never
    {
        $role = $this->role($id);
        $criteria = $this->db->all('SELECT kind, value FROM role_criteria WHERE role_id = ? ORDER BY sort, id', [$id]);
        $stages = $this->db->all('SELECT * FROM stages WHERE is_active = 1 ORDER BY sort');
        // Our own team (15 Nov 2026): no client to submit to, so no "Submitted to client" column (unless someone is in it),
        // and "Client interview" reads "Interview".
        if (\Eshcol\Ats\Services\Engagement::of($role) === 'inhouse') {
            $inUse = array_map('intval', array_column($this->db->all('SELECT DISTINCT stage_id FROM applications WHERE role_id = ?', [$id]), 'stage_id'));
            $S = \Eshcol\Ats\Services\Stages::class;
            $stages = array_values(array_filter($stages, fn ($s) => !$S::is($this->app, (int) $s['id'], $S::SUBMITTED) || in_array((int) $s['id'], $inUse, true)));
            foreach ($stages as &$st) {
                $st['name'] = $S::is($this->app, (int) $st['id'], $S::INTERVIEW) && $st['name'] === 'Client interview' ? 'Interview' : $st['name'];
            }
            unset($st);
        }
        $stageFilter = in_array($_GET['who'] ?? '', ['responded', 'waiting', 'short', 'found'], true) ? 0 : (int) ($_GET['stage'] ?? 0); // the reply tabs need everyone
        $apps = $this->db->all('
            SELECT a.*, COALESCE(a.override_score, a.score) AS eff_score, s.name AS stage, s.kind AS stage_kind,
                   c.full_name, c.location, c.exp_months, c.exp_as_of, c.notice_days, c.ctc_expected_lpa, c.ctc_min_lpa, c.field_sources, c.do_not_contact, c.unreliable_at, c.unreliable_reason, c.unreliable_note, c.red_listed_at, c.channel, c.source, c.email,
                   (SELECT text_status FROM cv_files f WHERE f.candidate_id = c.id ORDER BY f.id DESC LIMIT 1) AS text_status,
                   ' . \Eshcol\Ats\Services\KeyFacts::ROUND_SQL . ' AS iv_round
            FROM applications a
            JOIN candidates c ON c.id = a.candidate_id
            JOIN stages s ON s.id = a.stage_id
            WHERE a.role_id = ? AND (? = 0 OR a.stage_id = ?)
            ORDER BY s.kind IN (\'fail\', \'hold\'), eff_score IS NULL, eff_score DESC, a.applied_at DESC', [$id, $stageFilter, $stageFilter]);
        // By region, in tiers (21 Nov 2026): each candidate's tier, district and distance; tier 1 first, then the score.
        if (\Eshcol\Ats\Services\Regions::tiersOf($role) !== null && $apps) {
            $fr = new FunctionRouting($this->app);
            $cands = [];
            foreach ($this->db->all('SELECT id, location, locality, pin_code, home_district FROM candidates WHERE id IN (' . implode(',', array_map(fn ($a) => (int) $a['candidate_id'], $apps)) . ')') as $c) {
                $cands[(int) $c['id']] = $c;
            }
            foreach ($apps as $i => &$a) {
                $c = $cands[(int) $a['candidate_id']] ?? [];
                [$tier, $region] = $fr->tierOf($role, $c);
                $km = $tier ? $fr->kmToOffice($role, $c) : null;
                $a['tier'] = $tier;
                $a['tier_label'] = \Eshcol\Ats\Services\Regions::label($tier, $region) . ($km !== null ? ' · ' . \Eshcol\Ats\Services\Commute::kmLabel($km) : '');
                $a['_i'] = $i;
            }
            unset($a);
            $t = fn (array $a): int => $a['tier'] === null ? 4 : ($a['tier'] === 0 ? 5 : (int) $a['tier']);
            usort($apps, fn ($x, $y) => [(int) in_array($x['stage_kind'], ['fail', 'hold'], true), $t($x), $x['_i']] <=> [(int) in_array($y['stage_kind'], ['fail', 'hold'], true), $t($y), $y['_i']]);
        }
        $above = array_values(array_filter($apps, fn ($a) => in_array($a['stage_kind'], ['fail', 'hold'], true) || $a['eff_score'] === null || (int) $a['eff_score'] >= (int) $role['score_threshold']));
        $below = array_values(array_filter($apps, fn ($a) => !in_array($a['stage_kind'], ['fail', 'hold'], true) && $a['eff_score'] !== null && (int) $a['eff_score'] < (int) $role['score_threshold']));
        $counts = [];
        foreach ($this->db->all('SELECT stage_id, COUNT(*) n FROM applications WHERE role_id = ? GROUP BY stage_id', [$id]) as $r) {
            $counts[$r['stage_id']] = (int) $r['n'];
        }
        $qs = (new \Eshcol\Ats\Services\Questionnaires($this->app))->forRole($id);
        $qCounts = ['sent' => 0, 'answered' => 0, 'flagged' => 0];
        foreach ($qs as $q) {
            in_array($q['status'], ['sent', 'opened'], true) && $qCounts['sent']++;
            if ($q['status'] === 'answered') {
                $qCounts['answered']++;
                json_decode((string) $q['flags'], true) && $qCounts['flagged']++;
            }
        }
        $qCustom = (int) $this->db->col('SELECT COUNT(*) FROM role_questions WHERE role_id = ?', [$id]);
        // Claude's fit assessments (the hybrid): who was assessed, who wasn't relevant and why.
        $fitSvc = new \Eshcol\Ats\Services\FitAssessor($this->app);
        $fitRows = [];
        foreach ($this->db->all('SELECT * FROM fit_assessments WHERE role_id = ?', [$id]) as $f) {
            $f['result'] = json_decode((string) $f['result'], true) ?: [];
            $f['breakdown'] = json_decode((string) $f['breakdown'], true) ?: [];
            $fitRows[(int) $f['application_id']] = $f;
        }
        $notRelevant = [];
        if (!$role['is_pool']) {
            $need = [];
            foreach (array_merge($above, $below) as $a) {
                if ($a['stage_kind'] !== 'fail' && !isset($fitRows[(int) $a['id']])) {
                    $need[] = (int) $a['id'];
                }
            }
            // Remembered between visits (B12): only changed candidates are checked again.
            foreach ($fitSvc->relevanceMany($id, $need) as $appId => [$ok, $why]) {
                $notRelevant[$appId] = $ok ? '' : $why;
            }
        }
        // Found in your database (13 Nov 2026): everyone on file who fits by field, experience and location. Free.
        $catMode = Categoriser::mode($this->app);
        $found = !$role['is_pool'] && $catMode !== 'off' && !\Eshcol\Ats\Access::restricted(Auth::user()) ? (new FunctionRouting($this->app))->suggestions($role, 100) : null;
        $fit = ['on' => \Eshcol\Ats\Services\FitAssessor::enabled($this->app), 'rows' => $fitRows, 'version' => $fitSvc->roleVersion($role),
            'reqs' => $fitSvc->requirements($id), 'ownReqs' => (int) $this->db->col('SELECT COUNT(*) FROM role_requirements WHERE role_id = ?', [$id]),
            'ladder' => $fitSvc->ladder($role), 'copyFrom' => $fitSvc->copySources($role), 'notRelevant' => $notRelevant, 'used' => $fitSvc->usedThisMonth(), 'limit' => $fitSvc->limit(), 'cost' => $fitSvc->costThisMonth()];
        // The Claude tab is drawn only when it is opened (14 Nov 2026): it was over half of a position page
        // (517 KB of 886 on Associate, 1.1 MB of 1.7 on Manager). The page asks for it with ?part=claude.
        if (($_GET['part'] ?? '') === 'claude') {
            header('Cache-Control: no-store');
            echo \Eshcol\Ats\Back::filter(\Eshcol\Ats\View::render('roles/_fit', ['role' => $role, 'fit' => $fit, 'all' => array_merge($above, $below)], null), false, "/roles/$id#tab-claude");
            exit;
        }
        $this->view('roles/show', ['title' => $role['title'], 'role' => $role, 'criteria' => $criteria, 'stages' => $stages, 'fit' => $fit,
            'found' => $found, 'catMode' => $catMode, 'roleFields' => (new Categoriser($this->app))->roleFunctions($role), 'fieldsSet' => (bool) $role['function_codes'],
            // Who this client has already been sent (any position), for a badge on the pipeline (audit UI 7.1).
            'sentToClient' => array_column($this->db->all("SELECT a2.candidate_id, MAX(s.sent_at) AS sent_at FROM shortlist_items si JOIN shortlists s ON s.id = si.shortlist_id
                JOIN applications a2 ON a2.id = si.application_id JOIN roles r2 ON r2.id = s.role_id
                WHERE r2.client_id = ? AND s.status <> 'draft' AND s.sent_at IS NOT NULL GROUP BY a2.candidate_id", [$role['client_id']]), 'sent_at', 'candidate_id'),
            // Last two-way contact per candidate (our last sent email, or their last message), for the board-card footer (14 Nov 2026).
            'lastContact' => array_column($this->db->all("SELECT x.candidate_id, MAX(x.at) AS at FROM (
                    SELECT q.candidate_id, MAX(q.sent_at) AS at FROM email_queue q JOIN applications a3 ON a3.candidate_id = q.candidate_id AND a3.role_id = ? WHERE q.status = 'sent' GROUP BY q.candidate_id
                    UNION ALL
                    SELECT m.candidate_id, MAX(m.received_at) FROM candidate_messages m JOIN applications a4 ON a4.candidate_id = m.candidate_id AND a4.role_id = ? GROUP BY m.candidate_id
                ) x GROUP BY x.candidate_id", [$id, $id]), 'at', 'candidate_id'),
            // The new-position checklist and the position's history (B13, B14).
            'toClarify' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? [] : (new \Eshcol\Ats\Services\RoleHistory($this->app))->toClarify($role),
            'history' => (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? [] : (new \Eshcol\Ats\Services\RoleHistory($this->app))->history($id),
            'apps' => $above, 'below' => $below, 'counts' => $counts, 'stageFilter' => $stageFilter, 'qs' => $qs, 'qCounts' => $qCounts, 'qCustom' => $qCustom,
            'docs' => (new \Eshcol\Ats\Services\Documents($this->app))->for('role', $id),
            'slots' => $this->db->all("SELECT s.*, c.full_name AS taken_by,
                (SELECT CONCAT(c2.full_name, ' (', r2.title, ')') FROM interviews i2 JOIN applications a2 ON a2.id = i2.application_id JOIN roles r2 ON r2.id = a2.role_id JOIN candidates c2 ON c2.id = a2.candidate_id
                  WHERE r2.client_id = ? AND r2.id <> s.role_id AND i2.status = 'booked' AND i2.starts_at < s.starts_at + INTERVAL s.duration_min MINUTE AND i2.starts_at + INTERVAL i2.duration_min MINUTE > s.starts_at LIMIT 1) AS clash_by
                FROM interview_slots s LEFT JOIN interviews i ON i.id = s.interview_id LEFT JOIN applications a ON a.id = i.application_id LEFT JOIN candidates c ON c.id = a.candidate_id WHERE s.role_id = ? AND s.starts_at > NOW() - INTERVAL 2 DAY ORDER BY s.round, s.starts_at", [$role['client_id'], $id]),
            'ivs' => $this->db->all('SELECT i.*, a.candidate_id, c.full_name FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id WHERE a.role_id = ? ORDER BY i.starts_at IS NULL, i.starts_at', [$id]),
            'clientShortlisted' => (int) $this->db->col("SELECT COUNT(DISTINCT i.application_id) FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id WHERE s.role_id = ? AND i.decision = 'shortlist'", [$id]),
            'shortlists' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM shortlist_items i WHERE i.shortlist_id = s.id) AS n, (SELECT COUNT(*) FROM shortlist_items i WHERE i.shortlist_id = s.id AND i.decision IS NOT NULL) AS decided FROM shortlists s WHERE s.role_id = ? ORDER BY s.id DESC', [$id]),
            'clientDecisions' => array_column($this->db->all('SELECT i.application_id, i.decision FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id WHERE s.role_id = ? AND i.decision IS NOT NULL ORDER BY i.id', [$id]), 'decision', 'application_id')]);
    }

    public function edit(int $id): never
    {
        $role = $this->role($id);
        $criteria = $this->db->all('SELECT kind, value FROM role_criteria WHERE role_id = ? ORDER BY sort, id', [$id]);
        $this->view('roles/form', ['title' => 'Edit ' . $role['title'], 'role' => $role, 'criteria' => $criteria, 'clients' => $this->clients(), 'users' => $this->users(), 'errors' => [],
            'weights' => json_decode($role['weights'] ?? '[]', true) ?: Scorer::DEFAULT_WEIGHTS]);
    }

    public function update(int $id): never
    {
        $current = $this->role($id);
        [$data, $criteria, $errors] = $this->validate((bool) $current['is_pool']);
        $jd = $this->jdFromRequest($data);
        if ($errors) {
            $this->view('roles/form', ['title' => 'Edit role', 'jdFile' => $jd, 'role' => $data + ['id' => $id], 'criteria' => $criteria, 'clients' => $this->clients(), 'users' => $this->users(), 'errors' => $errors, 'weights' => json_decode($data['weights'] ?? '[]', true) ?: Scorer::DEFAULT_WEIGHTS]);
        }
        $hist = (new \Eshcol\Ats\Services\Migrator($this->app))->pending() ? null : new \Eshcol\Ats\Services\RoleHistory($this->app);
        $before = $hist?->snapshot($id);
        $this->db->tx(function () use ($id, $data, $criteria) {
            if (in_array($data['status'], ['filled', 'cancelled'], true)) {
                $data['closed_at'] ??= date('Y-m-d');
            }
            $this->db->update('roles', $data, $id);
            $this->db->run('DELETE FROM role_criteria WHERE role_id = ?', [$id]);
            $this->saveCriteria($id, $criteria);
        });
        $hist?->record($id, $before, $hist->snapshot($id), (int) Auth::user()['id']); // position history (B13)
        if (!$current['is_pool']) {
            \Eshcol\Ats\Services\DatabaseSweep::set($this->app, $id, isset($_POST['daily_sweep'])); // the morning sweep (23 Nov 2026)
        }
        $this->attachJd($id, $jd);
        $n = $this->rescoreAll($id);
        Audit::log('role_updated', 'role', $id);
        Http::flash('ok', 'Role saved' . ($n ? " and $n candidate" . ($n > 1 ? 's' : '') . ' re-scored against the new requirements.' : '.'));
        Http::redirect("/roles/$id");
    }

    public function rescore(int $id): never
    {
        $this->role($id);
        $n = $this->rescoreAll($id);
        Http::flash('ok', "Re-scored $n candidate" . ($n === 1 ? '' : 's') . '.');
        Http::redirect("/roles/$id");
    }

    /** Candidates already on file who fit this role, best first. */
    public function matches(int $id): never
    {
        $role = $this->role($id);
        $results = (new \Eshcol\Ats\Services\Matcher($this->app))->forRole($role, 30);
        $months = (int) $this->app->setting('reconfirm_after_months', '6');
        $this->view('roles/matches', ['title' => 'Matches for ' . $role['title'], 'role' => $role, 'results' => $results, 'reconfirmMonths' => $months]);
    }

    /** "Found in your database": add the ticked ones (or all that fit), or "not this one". */
    /**
     * The screening rules on everyone already on a position (17 Nov 2026): GET lists who fails them (not a B.Com where
     * B.Com is needed; another city on a local-only position), POST moves them to Not suitable with the reason.
     * Owner only. Nobody at Shortlisted or later is touched, and no email goes out.
     */
    public function screen(int $id): never
    {
        Auth::requireOwner();
        $role = $this->role($id);
        $fr = new FunctionRouting($this->app);
        if (Http::method() === 'POST') {
            $moved = $fr->screenPosition($id, true, (int) Auth::user()['id']);
            Http::flash('ok', $moved ? count($moved) . ' moved to Not suitable, each with the reason. Nobody was emailed.' : 'Nobody to move: everyone meets the rules.');
            Http::redirect("/roles/$id#tab-pipeline");
        }
        $this->view('roles/screen', ['title' => 'Screening: ' . $role['title'], 'role' => $role, 'rows' => $fr->screenPosition($id, false, 0),
            'bcom' => \Eshcol\Ats\Services\QualificationGate::requiresBcom(array_column($this->db->all("SELECT value FROM role_criteria WHERE role_id = ? AND kind = 'qualification'", [$id]), 'value')),
            'scope' => FunctionRouting::scopeOf($role)]);
    }

    public function found(int $id): never
    {
        $role = $this->role($id);
        $routing = new FunctionRouting($this->app);
        $uid = (int) Auth::user()['id'];
        if (($_POST['action'] ?? '') === 'dismiss') {
            $routing->dismiss($id, (int) ($_POST['candidate_id'] ?? 0), $uid);
            Http::flash('ok', 'Taken off the list for this position.');
            Http::redirect("/roles/$id#found");
        }
        $ids = ($_POST['action'] ?? '') === 'add_all'
            ? array_map(fn ($r) => (int) $r['id'], array_slice($routing->suggestions($role, 200)['fits'], 0, 200))
            : array_map('intval', (array) ($_POST['ids'] ?? []));
        $n = $ids ? $routing->add($id, $ids, $uid) : 0;
        Http::flash($n ? 'ok' : 'info', $n ? "$n added to this position. Claude assesses the relevant ones in the next half-price batch (about ₹0.9 each)." : 'Tick the people to add first.');
        Http::redirect("/roles/$id#found");
    }

    public function addCandidate(int $id, int $cid): never
    {
        $this->role($id);
        $this->findOr404('candidates', $cid);
        $added = (new CandidateImporter($this->app))->attach($cid, $id, (int) Auth::user()['id'], 'database');
        Audit::log('added_from_database', 'role', $id, ['candidate' => $cid]);
        $reason = $added ? $this->db->col("SELECT stage_reason FROM applications WHERE candidate_id = ? AND role_id = ?", [$cid, $id]) : null;
        Http::flash('ok', !$added ? 'Already on this role.' : ($reason ? "Added, but screened out straight away: $reason" : 'Added to the role and scored.'));
        Http::redirect(Http::input('back') === 'matches' ? "/roles/$id/matches" : "/roles/$id");
    }

    /** Suggests requirements from a pasted JD, using only the skills dictionary. */
    /**
     * Reads a JD file chosen on the role form: keeps the file for when the role is saved, and returns
     * its text and the fields found in it, for the form to fill in.
     */
    public function readJd(): never
    {
        [$ext, $problem] = \Eshcol\Ats\Services\Documents::check($_FILES['jd_file'] ?? []);
        if ($problem) {
            Http::json(['ok' => false, 'message' => $problem], 422);
        }
        $name = mb_substr(basename(str_replace('\\', '/', (string) $_FILES['jd_file']['name'])), 0, 200);
        $token = bin2hex(random_bytes(16));
        $path = $this->app->storage("uploads-tmp/jd-$token.$ext");
        if (!move_uploaded_file($_FILES['jd_file']['tmp_name'], $path)) {
            Http::json(['ok' => false, 'message' => 'The file could not be saved. Please try again.'], 500);
        }
        $read = (new \Eshcol\Ats\Services\TextExtractor())->extract($path, $ext);
        $_SESSION['jd_uploads'][$token] = ['path' => $path, 'name' => $name];
        $_SESSION['jd_uploads'] = array_slice($_SESSION['jd_uploads'], -5, null, true);
        if ($read['status'] !== 'ok') {
            Http::json(['ok' => true, 'token' => $token, 'name' => $name, 'text' => '', 'fields' => null,
                'message' => 'This file has no readable text (it may be a scan). It will still be attached to the role; please fill in the fields yourself.']);
        }
        $fields = (new \Eshcol\Ats\Services\JdReader(new Skills($this->db)))->read($read['text'], $name);
        Http::json(['ok' => true, 'token' => $token, 'name' => $name, 'text' => mb_substr($read['text'], 0, 60000), 'fields' => $fields]);
    }

    /** The JD file kept from the form, if any: [path, name] or null. Without JavaScript the file comes with the form. */
    private function jdFromRequest(array &$data): ?array
    {
        $token = (string) ($_POST['jd_upload'] ?? '');
        if ($token !== '' && isset($_SESSION['jd_uploads'][$token]) && is_file($_SESSION['jd_uploads'][$token]['path'])) {
            return $_SESSION['jd_uploads'][$token] + ['token' => $token];
        }
        if (!empty($_FILES['jd_file']['name'])) {
            [$ext, $problem] = \Eshcol\Ats\Services\Documents::check($_FILES['jd_file']);
            if (!$problem) {
                $token = bin2hex(random_bytes(16));
                $path = $this->app->storage("uploads-tmp/jd-$token.$ext");
                if (move_uploaded_file($_FILES['jd_file']['tmp_name'], $path)) {
                    $name = mb_substr(basename(str_replace('\\', '/', (string) $_FILES['jd_file']['name'])), 0, 200);
                    $_SESSION['jd_uploads'][$token] = ['path' => $path, 'name' => $name];
                    if (!$data['jd_text']) {
                        $data['jd_text'] = mb_substr((new \Eshcol\Ats\Services\TextExtractor())->extract($path, $ext)['text'], 0, 60000) ?: null;
                    }
                    return ['path' => $path, 'name' => $name, 'token' => $token];
                }
            }
        }
        return null;
    }

    private function attachJd(int $roleId, ?array $jd): void
    {
        if ($jd) {
            (new \Eshcol\Ats\Services\Documents($this->app))->adopt('role', $roleId, 'jd', $jd['path'], $jd['name'], (int) Auth::user()['id']);
            unset($_SESSION['jd_uploads'][$jd['token']]);
        }
    }

    public function suggest(): never
    {
        $jd = (string) ($_POST['jd'] ?? '');
        $skills = new Skills($this->db);
        $exp = null;
        if (preg_match('/(\d{1,2})\s*(?:-|–|to)\s*(\d{1,2})\s*(?:\+\s*)?(?:years?|yrs?)/i', $jd, $m)) {
            $exp = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/(\d{1,2})\s*\+?\s*(?:years?|yrs?)/i', $jd, $m)) {
            $exp = [(int) $m[1], null];
        }
        Http::json([
            'skills' => array_keys($skills->find($jd, 'skill')),
            'qualifications' => array_keys($skills->find($jd, 'qualification')),
            'locations' => array_keys($skills->find($jd, 'location')),
            'experience' => $exp,
        ]);
    }

    private function rescoreAll(int $roleId): int
    {
        $imp = new CandidateImporter($this->app);
        $ids = $this->db->all('SELECT id FROM applications WHERE role_id = ?', [$roleId]);
        foreach ($ids as $r) {
            $imp->scoreApplication((int) $r['id']);
        }
        return count($ids);
    }

    /** Fields an open role can't be saved without. A draft only needs a title and client. */
    public const REQUIRED = ['title' => 'Job title', 'client_id' => 'Client', 'location' => 'Location', 'exp_min' => 'Experience from',
        'exp_max' => 'Experience to', 'ctc_max_lpa' => 'CTC budget (to)', 'notice_max_days' => 'Max notice', 'must_haves' => 'At least one must-have'];

    private function validate(bool $isPool = false): array
    {
        $ctcErrors = [];
        $weights = [];
        foreach (Scorer::DEFAULT_WEIGHTS as $k => $default) {
            $v = $this->num("w_$k");
            $weights[$k] = $v === null ? $default : max(0, min(100, (int) $v));
        }
        $data = [
            'client_id' => (int) ($_POST['client_id'] ?? 0),
            'title' => $this->str('title', 160),
            'location' => $this->str('location', 160),
            'work_mode' => isset(WORK_MODES[$_POST['work_mode'] ?? '']) ? $_POST['work_mode'] : null,
            'positions' => max(1, (int) ($this->num('positions') ?? 1)),
            'exp_min' => $this->num('exp_min'),
            'exp_max' => $this->num('exp_max'),
            'exp_allow_below' => max(0, min(20, (float) ($this->num('exp_allow_below') ?? $this->app->setting('exp_allow_below_default', '1')))),
            'exp_allow_above' => max(0, min(20, (float) ($this->num('exp_allow_above') ?? $this->app->setting('exp_allow_above_default', '5')))),
            'ctc_min_lpa' => $this->lpa('ctc_min_lpa', $ctcErrors),
            'ctc_max_lpa' => $this->lpa('ctc_max_lpa', $ctcErrors),
            'notice_max_days' => ($v = $this->num('notice_max_days')) !== null ? (int) $v : null,
            'status' => in_array($_POST['status'] ?? '', ['draft', 'open', 'on_hold', 'filled', 'cancelled'], true) ? $_POST['status'] : 'open',
            'status_reason' => $this->str('status_reason', 200),
            'owner_user_id' => ($o = (int) ($_POST['owner_user_id'] ?? 0)) ? $o : null,
            'jd_text' => $this->str('jd_text', 60000),
            'weights' => $weights === Scorer::DEFAULT_WEIGHTS ? null : json_encode($weights),
            'score_threshold' => max(0, min(100, (int) ($this->num('score_threshold') ?? 50))),
            'accepts_routed' => isset($_POST['accepts_routed']) ? 1 : 0,
            'opened_at' => $this->str('opened_at', 10),
            'target_date' => $this->str('target_date', 10),
        ];
        // The new-position checklist (B14): interview rounds and who decides (once update 044 is applied).
        if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
            $data['interview_rounds'] = ($v = $this->num('interview_rounds')) !== null ? max(1, min(9, (int) $v)) : null;
            $data['decision_maker'] = $this->str('decision_maker', 160);
        }
        // Who the position is for (15 Nov 2026): client recruitment, staffing or our own team (update 048).
        if (!$isPool && !(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
            $eng = isset(\Eshcol\Ats\Services\Engagement::TYPES[$_POST['engagement'] ?? '']) ? $_POST['engagement'] : 'placement';
            $data['engagement'] = $eng;
            $data['hiring_manager_id'] = $eng === 'inhouse' && ($hm = (int) ($_POST['hiring_manager_id'] ?? 0)) ? $hm : null;
            $data['contract_months'] = $eng === 'staffing' && ($cm = $this->num('contract_months')) !== null ? max(1, min(60, (int) $cm)) : null;
            // Staffing (phase 2): kind of work and skill level (for the minimum wage), the monthly pay band, a bill rate.
            $money = fn (string $k) => is_numeric($v = trim(str_replace([',', '₹'], '', (string) ($_POST[$k] ?? '')))) && (float) $v > 0 ? round((float) $v, 2) : null;
            $data['work_kind'] = $eng === 'staffing' && isset(\Eshcol\Ats\Services\Staffing::WORK_KINDS[$_POST['work_kind'] ?? '']) ? $_POST['work_kind'] : null;
            $data['skill_level'] = $eng === 'staffing' && isset(\Eshcol\Ats\Services\Staffing::SKILLS[$_POST['skill_level'] ?? '']) ? $_POST['skill_level'] : null;
            $data['pay_min_month'] = $eng === 'staffing' ? $money('pay_min_month') : null;
            $data['pay_max_month'] = $eng === 'staffing' ? $money('pay_max_month') : null;
            $data['bill_rate_month'] = $eng === 'staffing' ? $money('bill_rate_month') : null;
            // The position's own fee (plan v2, 4.3), and its minimum-wage zone.
            $tm = $eng === 'staffing' && isset(\Eshcol\Ats\Services\Staffing::BILLING[$_POST['terms_model'] ?? '']) ? $_POST['terms_model'] : null;
            $data['terms_model'] = $tm;
            $data['terms_markup_pct'] = $tm === 'markup' && is_numeric($v = trim(str_replace('%', '', (string) ($_POST['terms_markup_pct'] ?? '')))) ? round((float) $v, 2) : null;
            $data['terms_fixed_fee'] = $tm === 'fixed_fee' ? $money('terms_fixed_fee') : null;
            $data['wage_zone'] = $eng === 'staffing' && isset(\Eshcol\Ats\Services\Staffing::ZONES[$_POST['wage_zone'] ?? '']) ? $_POST['wage_zone'] : null;
            if ($eng === 'staffing' && $data['ctc_max_lpa'] === null && $data['pay_max_month'] !== null) {
                $data['ctc_max_lpa'] = round($data['pay_max_month'] * 12 / 100000, 2); // the budget, from the monthly pay band
            }
            if ($eng === 'inhouse') {
                $data['client_id'] = \Eshcol\Ats\Services\Engagement::selfClientId($this->app) ?? $data['client_id']; // filed under Eshcol Global
            } elseif ($data['client_id'] && $data['client_id'] === \Eshcol\Ats\Services\Engagement::selfClientId($this->app)) {
                $data['client_id'] = 0; // a client position needs a real client
            }
        }
        // Who the position considers (13 Nov 2026): its fields of work, and local candidates only or other cities too.
        $codes = Categoriser::functions($this->app);
        $fns = array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['functions'] ?? [])), fn ($c) => isset($codes[$c]))));
        $data['function_codes'] = $fns ? implode(',', array_slice($fns, 0, 6)) : null;
        $data['location_scope'] = isset(FunctionRouting::SCOPES[$_POST['location_scope'] ?? '']) ? $_POST['location_scope']
            : FunctionRouting::defaultScope($data['exp_min'], $data['exp_max'], $data['work_mode']);
        // "Within N km of the office" (17 Nov 2026): the distance, 5 to 2,000 km.
        $data['location_radius_km'] = $data['location_scope'] === 'radius' ? max(5, min(2000, (int) ($_POST['location_radius_km'] ?? FunctionRouting::DEFAULT_RADIUS_KM))) : null;
        // By region, in tiers; the engineering branches; experience by qualification (21 Nov 2026, update 076).
        if (!\Eshcol\Ats\Services\Regions::ready($this->app)) {
            $data['location_scope'] = $data['location_scope'] === 'tiers' ? 'open' : $data['location_scope']; // before update 076
        } else {
            if ($data['location_scope'] === 'tiers') {
                $tiers = [];
                foreach (['1', '2', '3'] as $k) {
                    $tiers[$k] = \Eshcol\Ats\Services\Regions::parseList((string) ($_POST['tier_' . $k] ?? ''));
                }
                $data['location_tiers'] = array_filter($tiers) ? json_encode($tiers) : json_encode(\Eshcol\Ats\Services\Regions::DEFAULT_TIERS);
            } else {
                $data['location_tiers'] = null;
            }
            $br = array_values(array_intersect(array_keys(\Eshcol\Ats\Services\EngineeringBranches::BRANCHES), array_map('strval', (array) ($_POST['eng_branches'] ?? []))));
            $data['eng_branches'] = $br ? implode(',', $br) : null;
            $win = [];
            foreach (['degree', 'diploma'] as $k) {
                $a = $this->num("expq_{$k}_min");
                $b = $this->num("expq_{$k}_max");
                if ($a !== null && $b !== null && $a <= $b) {
                    $win[$k] = [round($a, 1), round($b, 1)];
                }
            }
            $data['exp_by_qual'] = $win ? json_encode($win) : null;
            // The overall band covers both windows, so lists, the free score and the client view read it as before.
            if ($win) {
                $data['exp_min'] = min(array_column($win, 0));
                $data['exp_max'] = max(array_column($win, 1));
            }
        }
        $criteria = [];
        foreach (self::KINDS as $kind => $field) {
            foreach (preg_split('/[\n,;]+/', (string) ($_POST[$field] ?? '')) as $v) {
                $v = trim($v);
                if ($v !== '' && mb_strlen($v) <= 190) {
                    $criteria[] = ['kind' => $kind, 'value' => $v];
                }
            }
        }
        $errors = [];
        if (!$data['title']) {
            $errors['title'] = 'Give the role a title.';
        }
        if (!$isPool && $data['status'] !== 'draft') {
            foreach (['location' => 'Where is the job? It decides who is a local candidate.', 'exp_min' => 'From how many years?', 'exp_max' => 'Up to how many years?',
                      'ctc_max_lpa' => 'The client\'s budget. Candidates expecting more are flagged.', 'notice_max_days' => 'The longest notice the client will wait for.'] as $k => $msg) {
                if ($data[$k] === null) {
                    $errors[$k] = $msg;
                }
            }
            if (!array_filter($criteria, fn ($c) => $c['kind'] === 'must')) {
                $errors['must_haves'] = 'Add at least one must-have skill, so CVs can be scored.';
            }
            // A staffing position opens only when the client's terms protect wages by the 7th, and with what the minimum wage needs.
            if (($data['engagement'] ?? '') === 'staffing') {
                if (!$data['work_kind'] || !$data['skill_level'] || !$data['wage_zone']) {
                    $errors['work_kind'] = 'Choose the scheduled employment, the class of employee and the zone (they decide the minimum wage).';
                }
                if ($data['client_id'] && !\Eshcol\Ats\Services\Staffing::safeguard((new \Eshcol\Ats\Services\Staffing($this->app))->terms((int) $data['client_id']))) {
                    $errors['client_id'] = 'This client has no staffing terms that protect wages by the 7th yet. Set them on the client\x27s page, or save the position as a Draft.';
                }
            }
        }
        if (!$data['client_id'] || !$this->db->col('SELECT id FROM clients WHERE id = ?', [$data['client_id']])) {
            $errors['client_id'] = 'Choose the client.';
        }
        if ($data['exp_min'] !== null && $data['exp_max'] !== null && $data['exp_min'] > $data['exp_max']) {
            $errors['exp_min'] = 'Minimum experience is more than the maximum.';
        }
        if ($data['ctc_min_lpa'] !== null && $data['ctc_max_lpa'] !== null && $data['ctc_min_lpa'] > $data['ctc_max_lpa']) {
            $errors['ctc_min_lpa'] = '"CTC from" is more than the budget. Lower it, or clear it.';
        }
        $errors = $ctcErrors + $errors; // a figure we could not read says why, not just "missing"
        if (array_sum($weights) === 0) {
            $errors['weights'] = 'At least one weight must be above zero.';
        }
        foreach (['opened_at', 'target_date'] as $d) {
            if ($data[$d] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$d])) {
                $data[$d] = null;
            }
        }
        return [$data, $criteria, $errors];
    }

    private function saveCriteria(int $roleId, array $criteria): void
    {
        $seen = [];
        foreach ($criteria as $i => $c) {
            $key = $c['kind'] . '|' . mb_strtolower($c['value']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->db->insert('role_criteria', ['role_id' => $roleId, 'kind' => $c['kind'], 'value' => $c['value'], 'sort' => $i]);
        }
    }

    private function role(int $id): array
    {
        $r = $this->db->one('SELECT r.*, c.name AS client, c.is_self, c.logo_path, c.logo_updated_at, c.interview_address, c.maps_url FROM roles r JOIN clients c ON c.id = r.client_id WHERE r.id = ?', [$id]);
        if (!$r) {
            Http::abort(404);
        }
        return $r;
    }

    private function clients(): array
    {
        $skills = new Skills($this->db);
        return array_map(function ($c) use ($skills) {
            $c['city'] = array_key_first($skills->find((string) $c['interview_address'], 'location')) ?? '';
            return $c;
        }, $this->db->all('SELECT id, name, interview_address FROM clients WHERE is_active = 1 AND is_self = 0 ORDER BY name'));
    }

    private function users(): array
    {
        return $this->db->all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    }
}
