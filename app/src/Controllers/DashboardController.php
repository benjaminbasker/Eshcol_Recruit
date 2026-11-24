<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

final class DashboardController extends Controller
{
    /** "My positions" or everyone's, as SQL on the roles table (alias r). */
    private string $scope = '1=1';

    public function index(): never
    {
        if (\Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user())) {
            \Eshcol\Ats\Http::redirect('/roles'); // a contractor starts on their positions (B6)
        }
        // My positions or everyone's (F2, 14 Nov 2026): recruiters start on theirs, the owner on everyone's; the choice is remembered.
        $me = \Eshcol\Ats\Auth::user();
        if (in_array($_GET['view'] ?? '', ['mine', 'all'], true)) {
            $_SESSION['day_view'] = $_GET['view'];
        }
        $view = $_SESSION['day_view'] ?? (($me['role'] ?? '') === 'owner' ? 'all' : 'mine');
        $this->scope = $view === 'mine' ? 'r.owner_user_id = ' . (int) $me['id'] : '1=1';
        $stats = [
            'open_roles' => (int) $this->db->col("SELECT COUNT(*) FROM roles WHERE status = 'open' AND is_pool = 0"),
            'candidates' => (int) $this->db->col('SELECT COUNT(*) FROM candidates'),
            'new_week' => (int) $this->db->col('SELECT COUNT(*) FROM candidates WHERE created_at >= NOW() - INTERVAL 7 DAY'),
            'in_play' => (int) $this->db->col("SELECT COUNT(*) FROM applications a JOIN stages s ON s.id = a.stage_id JOIN roles r ON r.id = a.role_id WHERE s.kind = 'active' AND r.status = 'open' AND r.is_pool = 0"),
            'needs_text' => (int) $this->db->col("SELECT COUNT(*) FROM cv_files WHERE text_status <> 'ok'"),
        ];
        $roles = $this->db->all("
            SELECT r.id, r.title, r.code, r.location, r.status, c.name AS client, r.client_id, c.logo_path, c.logo_updated_at, r.target_date,
                   COUNT(a.id) AS total,
                   SUM(a.score >= r.score_threshold) AS above,
                   SUM(s.kind = 'active' AND s.sort = (SELECT MIN(s0.sort) FROM stages s0 WHERE s0.kind = 'active' AND s0.is_active = 1)) AS fresh,
                   DATEDIFF(CURDATE(), COALESCE(r.opened_at, DATE(r.created_at))) AS age_days
            FROM roles r
            JOIN clients c ON c.id = r.client_id
            LEFT JOIN applications a ON a.role_id = r.id
            LEFT JOIN stages s ON s.id = a.stage_id
            WHERE r.status IN ('open','on_hold') AND r.is_pool = 0 AND {$this->scope}
            GROUP BY r.id
            ORDER BY r.status = 'open' DESC, r.target_date IS NULL, r.target_date, r.created_at DESC");
        $recent = $this->db->all('
            SELECT c.id, c.full_name, c.location, c.exp_months, c.exp_as_of, c.created_at, c.source
            FROM candidates c ORDER BY c.created_at DESC LIMIT 8');
        // Owner-only health warnings: things that quietly go wrong if nobody looks.
        $alerts = [];
        if ((\Eshcol\Ats\Auth::user()['role'] ?? '') === 'owner') {
            $due = (new \Eshcol\Ats\Services\Retention($this->app))->dueCount();
            if ($due) {
                $alerts[] = ['info', "$due candidate" . ($due > 1 ? 's have' : ' has') . ' reached the end of the keeping period. <a href="/settings#retention">Review them</a>.'];
            }
            $beat = $this->app->setting('cron_heartbeat');
            if (!$this->app->isLocal() && (!$beat || strtotime($beat) < time() - 86400)) {
                $alerts[] = ['error', 'Scheduled jobs (backups, clean-up) have not run in the last day. Check the cron job in cPanel.'];
            }
            $bk = $this->app->setting('backup_last_ok');
            if (!$this->app->isLocal() && (!$bk || strtotime($bk) < time() - 2 * 86400)) {
                $alerts[] = ['error', 'No successful backup in the last two days. See <a href="/settings#system">Settings › System</a>.'];
            }
            if ($ba = (new \Eshcol\Ats\Services\FitAssessor($this->app))->batchAlert()) {
                $alerts[] = ['error', "Claude has not taken the half-price batch since " . e(nice_date($ba[0], true)) . ": {$ba[2]} candidate(s) wait for Claude's assessment. "
                    . 'Claude said: ' . e(preg_replace('/^\S+ \S+ /', '', $ba[1])) . '. Nothing is charged; the app tries again every five minutes. '
                    . 'For results now, press "Assess now" on the role\'s Claude tab (full price).'];
            }
            $declined = (int) $this->db->col("SELECT COUNT(*) FROM fit_assessments WHERE status = 'failed' AND error = ?", [\Eshcol\Ats\Services\FitAssessor::DECLINED]);
            if ($declined) {
                $rid = (int) $this->db->col("SELECT role_id FROM fit_assessments WHERE status = 'failed' AND error = ? ORDER BY id DESC LIMIT 1", [\Eshcol\Ats\Services\FitAssessor::DECLINED]);
                $alerts[] = ['info', "Claude declined to assess $declined CV" . ($declined > 1 ? 's' : '') . ' in the half-price batch. <a href="/roles/' . $rid . '#tab-claude">See them</a> (under "Claude could not assess"; "Assess now" tries again at the full price).'];
            }
            // Scheduled jobs that failed twice in a row (B8, 14 Nov 2026).
            if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
                foreach ((new \Eshcol\Ats\Services\JobsHealth($this->app))->failing() as $t) {
                    $alerts[] = ['error', '<b>' . e($t['name']) . '</b> failed ' . (int) $t['failing'] . ' times in a row: ' . e(mb_strimwidth((string) $t['last']['message'], 0, 160, '…')) . ' <a href="/settings#jobs">See the jobs</a>.'];
                }
                $rate = (new \Eshcol\Ats\Services\EmailHealth($this->app))->summary()['bounce_rate'];
                if ($rate !== null && $rate >= 5) {
                    $alerts[] = ['error', "Emails bounced at <b>$rate%</b> in the last 30 days. Above about 5% Brevo may stop your sending. <a href=\"/settings#bounces\">See the bounces</a>."];
                }
            }
            // The client portal (13 Nov 2026): positions clients asked for, waiting as drafts.
            if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
                foreach ((new \Eshcol\Ats\Services\ClientPortal($this->app))->pendingRequests() as $rq) {
                    $alerts[] = ['info', '<b>' . e($rq['client']) . '</b> asked for a new position in the client portal: <b>' . e($rq['title']) . '</b>'
                        . ($rq['contact'] ? ' (' . e($rq['contact']) . ', ' . e(nice_date($rq['requested_at'], true)) . ')' : '') . '. <a href="/roles/' . (int) $rq['id'] . '/edit">Check it and open it</a>.'];
                }
            }
            // Categories (13 Nov 2026): people on file who fit an open position, and unsure categories to check.
            if (\Eshcol\Ats\Services\Categoriser::mode($this->app) !== 'off' && !(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
                $routing = new \Eshcol\Ats\Services\FunctionRouting($this->app);
                foreach ($this->db->all("SELECT * FROM roles WHERE status = 'open' AND is_pool = 0 ORDER BY id") as $r) {
                    $n = count($routing->suggestions($r, 500)['fits']);
                    if ($n) {
                        $alerts[] = ['info', '<b>' . e($r['title']) . "</b>: $n " . ($n > 1 ? 'people' : 'person') . ' in your database fit by field, experience and location. <a href="/roles/' . (int) $r['id'] . '#found">See them</a>.'];
                    }
                }
                $check = (int) $this->db->col('SELECT COUNT(*) FROM candidate_categories WHERE is_current = 1 AND needs_review = 1 AND reviewed_at IS NULL');
                if ($check) {
                    $alerts[] = ['info', "$check categor" . ($check > 1 ? 'ies' : 'y') . ' Claude was unsure of. <a href="/settings/categories#check">Check them</a>.'];
                }
            }
            // Placements (B9): calls before joining, invoices to raise in Zoho Books, overdue payments, check-ins.
            if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
                foreach ((new \Eshcol\Ats\Services\Placements($this->app))->reminders() as $pr) {
                    $alerts[] = $pr;
                }
                // Staffing (phase 2, 15 Nov 2026): what stops staffing offers, and the contract labour licence.
                $stf = new \Eshcol\Ats\Services\Staffing($this->app);
                if ($this->db->col("SELECT 1 FROM roles WHERE engagement = 'staffing' AND status = 'open' LIMIT 1")) {
                    if ($un = $stf->unconfirmed()) {
                        $alerts[] = ['warn', count($un) . ' statutory rate' . (count($un) > 1 ? 's are' : ' is') . ' not confirmed, so no staffing offer can be approved. <a href="/settings/staffing#rates">Check them</a>.'];
                    }
                    foreach ($this->db->all("SELECT DISTINCT work_kind, skill_level, wage_zone FROM roles WHERE engagement = 'staffing' AND status = 'open' AND work_kind IS NOT NULL AND skill_level IS NOT NULL") as $ws) {
                        if (!$stf->minWage($ws['work_kind'], $ws['skill_level'], $ws['wage_zone'])) {
                            $alerts[] = ['warn', 'No confirmed Tamil Nadu minimum wage for ' . e(\Eshcol\Ats\Services\Staffing::WORK_KINDS[$ws['work_kind']] ?? $ws['work_kind']) . ', '
                                . e(mb_strtolower(\Eshcol\Ats\Services\Staffing::SKILLS[$ws['skill_level']] ?? $ws['skill_level'])) . ($ws['wage_zone'] ? ', Zone ' . e($ws['wage_zone']) : '') . ' (basic rate and dearness allowance). <a href="/settings/staffing#wages">Confirm or add it</a>.'];
                        }
                    }
                }
                $deputed = $stf->headcount() + $stf->aboutToJoin(); // deputed, and approved offers about to join
                $lic = $stf->licence();
                if ($deputed >= \Eshcol\Ats\Services\Staffing::WARN_AT && !$lic) {
                    $alerts[] = ['warn', "$deputed people deputed or about to join. The contract labour licence is needed from " . \Eshcol\Ats\Services\Staffing::LICENCE_AT . '. <a href="/settings/staffing#licence">Add it</a>.'];
                } elseif ($lic && $lic['valid_to'] <= date('Y-m-d', strtotime('+30 days'))) {
                    $alerts[] = ['warn', 'The contract labour licence runs out on ' . e(nice_date($lic['valid_to'])) . '. <a href="/settings/staffing#licence">Renew it</a>.'];
                }
                // Clients who haven't decided on a shortlist after 5 working days (B11).
                foreach ((new \Eshcol\Ats\Services\SubmissionChecks($this->app))->toChase() as $ch) {
                    $alerts[] = ['info', 'Chase <b>' . e((string) $ch['client']) . '</b>: ' . (int) $ch['waiting'] . ' candidate' . ((int) $ch['waiting'] > 1 ? 's' : '')
                        . ' for ' . e($ch['title']) . ' sent ' . (int) $ch['days'] . ' working days ago, no decision yet. <a href="/shortlists/' . (int) $ch['id'] . '">Open</a>'
                        . ' <form method="post" action="/shortlists/' . (int) $ch['id'] . '/chased" style="display:inline">' . csrf() . '<input type="hidden" name="back" value="day"><button class="btn ghost small" type="submit">I chased them</button></form>'];
                }
            }
            if ($pending = (new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
                $alerts[] = ['info', count($pending) . ' app update' . (count($pending) > 1 ? 's are' : ' is') . ' ready to apply. <a href="/settings#system">Apply now</a>.'];
            }
        }
        if (!(new \Eshcol\Ats\Services\Migrator($this->app))->pending()) {
            if (($me['role'] ?? '') !== 'owner') {
                // A recruiter: chase the clients on their own positions (the owner sees every one above).
                $mineIds = array_map('intval', array_column($this->db->all('SELECT id FROM roles WHERE owner_user_id = ?', [$me['id']]), 'id'));
                foreach ((new \Eshcol\Ats\Services\SubmissionChecks($this->app))->toChase() as $ch) {
                    if (in_array((int) $ch['role_id'], $mineIds, true)) {
                        $alerts[] = ['info', 'Chase <b>' . e((string) $ch['client']) . '</b>: ' . (int) $ch['waiting'] . ' candidate' . ((int) $ch['waiting'] > 1 ? 's' : '')
                            . ' for ' . e($ch['title']) . ' sent ' . (int) $ch['days'] . ' working days ago, no decision yet. <a href="/shortlists/' . (int) $ch['id'] . '">Open</a>'
                            . ' <form method="post" action="/shortlists/' . (int) $ch['id'] . '/chased" style="display:inline">' . csrf() . '<input type="hidden" name="back" value="day"><button class="btn ghost small" type="submit">I chased them</button></form>'];
                    }
                }
            } else {
                $noOwner = (int) $this->db->col("SELECT COUNT(*) FROM roles WHERE status = 'open' AND is_pool = 0 AND owner_user_id IS NULL");
                $hasTeam = (int) $this->db->col("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('recruiter','contractor')");
                if ($noOwner && $hasTeam) {
                    $alerts[] = ['info', "$noOwner open position" . ($noOwner > 1 ? 's have' : ' has') . ' no recruiter assigned. Choose one under "Recruiter" when you edit the position.'];
                }
            }
        }
        $removals = (int) $this->db->col('SELECT COUNT(*) FROM candidates WHERE removal_requested_at IS NOT NULL');
        if ($removals && (\Eshcol\Ats\Auth::user()['role'] ?? '') === 'owner') {
            $alerts[] = ['info', "$removals candidate" . ($removals > 1 ? 's' : '') . ' asked us not to keep their details. <a href="/candidates?removal=1">Review</a>.'];
            // 24 Nov 2026: those in a live process were told "within 30 days".
            if ($heldRm = (new \Eshcol\Ats\Services\SelfRemoval($this->app))->heldList()) {
                $by = $heldRm[0]['close_by'];
                $alerts[] = [$by <= date('Y-m-d', strtotime('+7 days')) ? 'warn' : 'info', count($heldRm) . ' of them ' . (count($heldRm) > 1 ? 'are' : 'is')
                    . ' in a live process with a client. Tell the client they have withdrawn, then delete them: the first by <b>' . e(date('j M', strtotime($by))) . '</b>. <a href="/candidates?removal=1">Review</a>.'];
            }
        }
        $held = (int) $this->db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'held'");
        if ($held) {
            $alerts[] = ['info', "$held thank-you email" . ($held > 1 ? 's wait' : ' waits') . ' for you to check the address. <a href="/emails">Check now</a>.'];
        }
        $mailer = new \Eshcol\Ats\Services\Mailer($this->app);
        if ((\Eshcol\Ats\Auth::user()['role'] ?? '') === 'owner') {
            if ($p = $mailer->keyProblem()) {
                $waiting = (int) $this->db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'");
                $alerts[] = ['error', 'Emails are paused: Brevo refused the API key (' . e($p[1]) . "). $waiting waiting, nothing lost. In Brevo, create a new API key (Settings › SMTP &amp; API › API keys &amp; MCP, Transactional email: All) and paste it into <a href=\"/settings#email\">Settings › Email</a>. If Brevo mentions an IP address, add 162.0.217.16 under Brevo › Security › Authorized IPs instead."];
            }
            $lastOk = $this->app->setting('mail_last_brevo_ok');
            if ($mailer->live() && $lastOk && strtotime($lastOk) < time() - 75 * 86400) {
                $days = (int) floor((time() - strtotime($lastOk)) / 86400);
                $alerts[] = ['info', "No email has gone through Brevo for $days days. Brevo switches off API keys after 90 days unused, so <a href=\"/settings#email\">send a test email</a> to keep the key alive."];
            }
            $failed = (int) $this->db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY");
            if ($failed) {
                $alerts[] = ['info', "$failed email" . ($failed > 1 ? 's' : '') . ' could not be sent this week. <a href="/emails?tab=failed">See why</a>.'];
            }
            // Audit R5: handed to Brevo with no clear answer (a timeout, or the run stopped; not_before = when it was handed over).
            // Waits for you, never resent by itself. One being sent this very minute is not counted.
            $unsure = (int) $this->db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'unsure' AND (last_error LIKE 'Brevo may%' OR not_before < NOW() - INTERVAL 15 MINUTE)");
            if ($unsure) {
                $alerts[] = ['info', "$unsure email" . ($unsure > 1 ? 's' : '') . ' may or may not have gone (Brevo gave no clear answer). <a href="/emails?tab=unsure">Check and choose</a>.'];
            }
        }
        if (!$this->app->isLocal() && !$mailer->live() && (\Eshcol\Ats\Auth::user()['role'] ?? '') === 'owner'
            && $this->db->col("SELECT 1 FROM email_queue WHERE kind <> 'test' LIMIT 1")) {
            $alerts[] = ['info', 'Emails are in <b>test mode</b>: they are written to the server, not sent. Switch them on in <a href="/settings#email">Settings › Email</a> when you are ready.'];
        }
        $ai = new \Eshcol\Ats\Services\AiReading($this->app);
        $scanAlerts = \Eshcol\Ats\Services\AiReading::scanAlerts($stats['needs_text'], (int) $this->db->col("SELECT COUNT(*) FROM cv_ai_reads WHERE status = 'pending'"),
            $ai->usedThisMonth(), $ai->limit(), \Eshcol\Ats\Services\AiReading::enabled($this->app), (\Eshcol\Ats\Auth::user()['role'] ?? '') === 'owner');
        $this->view('dashboard/index', ['title' => 'My day', 'stats' => $stats, 'scanAlerts' => $scanAlerts, 'roles' => $roles, 'recent' => $recent, 'alerts' => $alerts,
            'view' => $view, 'schedule' => $this->schedule(), 'todo' => $this->todo(), 'pipes' => $this->pipes(array_column($roles, 'id')),
            'resp' => $this->responsesBoard(), 'interviewsWeek' => (int) $this->db->col("SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id JOIN roles r ON r.id = a.role_id WHERE i.status IN ('booked','done') AND YEARWEEK(i.starts_at, 1) = YEARWEEK(CURDATE(), 1) AND {$this->scope}")]);
    }

    /**
     * Applications and responses (Benjamin, 14 Nov 2026: "the responded number is critical"): for today, the week to
     * date (from Monday) and the month to date: applications received on client positions, people mailed for their
     * details, how many responded (answered, or said they're no longer interested), and of those mailed in the period,
     * the share who answered. Plus updated CVs from "Share your updated CV".
     */
    private function responsesBoard(): array
    {
        $db = $this->db;
        $out = [];
        $hasCv = !(new \Eshcol\Ats\Services\Migrator($this->app))->pending();
        // "Mailed for details" counts exactly as Responses › At a glance does (15 Nov 2026): each person once, on the
        // day their request email actually went out (the "By day sent" table), so the two pages always agree.
        $days = (new \Eshcol\Ats\Services\Questionnaires($this->app))->dashboard()['days'];
        foreach (['today' => date('Y-m-d'), 'week' => date('Y-m-d', strtotime('monday this week')), 'month' => date('Y-m-01')] as $k => $from) {
            $since = "$from 00:00:00";
            $asked = $askedAnswered = 0;
            foreach ($days as $d) {
                if ($d['day'] >= $from) {
                    $asked += (int) $d['sent'];
                    $askedAnswered += (int) $d['answered'];
                }
            }
            $out[$k] = [
                'applications' => (int) $db->col("SELECT COUNT(*) FROM applications a JOIN roles r ON r.id = a.role_id WHERE r.is_pool = 0 AND a.applied_at >= ? AND {$this->scope}", [$since]),
                'asked' => $asked,
                // People, not requests: someone who answered for two roles counts once.
                'answered' => (int) $db->col("SELECT COUNT(DISTINCT candidate_id) FROM questionnaires WHERE parent_id IS NULL AND status = 'answered' AND answered_at >= ?", [$since]),
                'declined' => (int) $db->col("SELECT COUNT(DISTINCT candidate_id) FROM questionnaires WHERE parent_id IS NULL AND status = 'declined' AND declined_at >= ?", [$since]),
                'rate' => $asked ? (int) round(100 * $askedAnswered / $asked) : null,
                'cvs' => $hasCv ? (int) $db->col('SELECT COUNT(*) FROM cv_requests WHERE cv_at >= ?', [$since]) : 0,
            ];
        }
        return $out;
    }

    /** Booked interviews from now to a week ahead, earliest first. */
    private function schedule(): array
    {
        return $this->db->all("
            SELECT i.id, i.round, i.kind, i.starts_at, i.duration_min, a.candidate_id, c.full_name, r.id AS role_id, r.title, cl.name AS client
            FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id
            JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id
            WHERE i.status = 'booked' AND i.starts_at >= NOW() - INTERVAL 2 HOUR AND i.starts_at < CURDATE() + INTERVAL 8 DAY AND {$this->scope}
            ORDER BY i.starts_at LIMIT 12");
    }

    /**
     * The things that wait on a recruiter, each with the one link that moves it on.
     * @return list<array{icon:string,tone:string,title:string,sub:string,url:string,cta:string}>
     */
    private function todo(): array
    {
        $out = [];
        // The client shortlisted people who have not been invited to interview yet.
        foreach ($this->db->all("
            SELECT r.id AS role_id, r.title, cl.name AS client, COUNT(DISTINCT i.application_id) AS n
            FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id JOIN roles r ON r.id = s.role_id JOIN clients cl ON cl.id = r.client_id
            WHERE i.decision = 'shortlist' AND r.status = 'open' AND {$this->scope}
              AND NOT EXISTS (SELECT 1 FROM interviews v WHERE v.application_id = i.application_id AND v.status IN ('choosing','booked','done'))
            GROUP BY r.id ORDER BY MAX(i.decided_at) DESC LIMIT 5") as $r) {
            $out[] = ['icon' => 'star', 'tone' => 'green', 'title' => "{$r['client']} shortlisted {$r['n']} for {$r['title']}",
                      'sub' => 'Invite them to choose an interview time', 'url' => "/roles/{$r['role_id']}#interviews", 'cta' => 'Invite'];
        }
        // Interviews that have happened but have no outcome yet.
        foreach ($this->db->all("
            SELECT i.id, i.round, i.starts_at, a.candidate_id, c.full_name, r.title
            FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id
            WHERE i.status = 'booked' AND i.starts_at < NOW() - INTERVAL i.duration_min MINUTE AND {$this->scope}
            ORDER BY i.starts_at LIMIT 5") as $r) {
            $out[] = ['icon' => 'clipboard', 'tone' => 'warn', 'title' => "How did {$r['full_name']}'s round {$r['round']} go?",
                      'sub' => $r['title'] . ' · ' . nice_date($r['starts_at']), 'url' => "/candidates/{$r['candidate_id']}#interviews", 'cta' => 'Record outcome'];
        }
        // Invited to choose a time more than a day ago, and still haven't.
        foreach ($this->db->all("
            SELECT i.id, a.candidate_id, c.full_name, r.title
            FROM interviews i JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id JOIN roles r ON r.id = a.role_id
            WHERE i.status = 'choosing' AND i.created_at < NOW() - INTERVAL 1 DAY AND {$this->scope} ORDER BY i.created_at LIMIT 5") as $r) {
            $out[] = ['icon' => 'clock', 'tone' => 'warn', 'title' => "{$r['full_name']} hasn't picked an interview time",
                      'sub' => $r['title'] . ' · a quick WhatsApp usually does it', 'url' => "/candidates/{$r['candidate_id']}#interviews", 'cta' => 'Follow up'];
        }
        // Shortlists prepared but not sent.
        foreach ($this->db->all("
            SELECT s.id, r.title, cl.name AS client FROM shortlists s JOIN roles r ON r.id = s.role_id JOIN clients cl ON cl.id = r.client_id
            WHERE s.status = 'draft' AND {$this->scope} ORDER BY s.created_at DESC LIMIT 3") as $r) {
            $out[] = ['icon' => 'send', 'tone' => '', 'title' => 'Shortlist ' . ref_shortlist($r['id']) . " for {$r['client']} isn't sent yet",
                      'sub' => $r['title'], 'url' => "/shortlists/{$r['id']}", 'cta' => 'Finish'];
        }
        // Completed applications waiting for your review: they stay until you act (approve, future
        // requirement, not suitable, or a stage change). Review opens the review box for that role.
        $pending = (new \Eshcol\Ats\Services\Questionnaires($this->app))->pendingReviews(200);
        foreach (array_slice($pending, 0, 10) as $r) {
            $flags = json_decode((string) $r['flags'], true) ?: [];
            $out[] = ['icon' => 'check', 'tone' => $flags ? 'warn' : 'green', 'title' => "{$r['full_name']} completed their application",
                      'sub' => ($r['title'] ?? 'No role named') . ($flags ? ' · ' . count($flags) . ' point' . (count($flags) > 1 ? 's' : '') . ' to check' : ' · nothing flagged'),
                      'url' => CandidateController::reviewUrl($r) . '&from=dashboard' . ($r['application_id'] ? '#review-' . (int) $r['application_id'] : '#their-details'), 'cta' => 'Review'];
        }
        if (count($pending) > 10) {
            $out[] = ['icon' => 'list', 'tone' => '', 'title' => (count($pending) - 10) . ' more completed applications to review', 'sub' => 'Review goes through them one after another', 'url' => CandidateController::reviewUrl($pending[10]) . '&from=dashboard' . ($pending[10]['application_id'] ? '#review-' . (int) $pending[10]['application_id'] : '#their-details'), 'cta' => 'Continue'];
        }
        // Said no in the last three days (for your information; already moved to Withdrew).
        foreach ($this->db->all("SELECT q.candidate_id, c.full_name, r.title FROM questionnaires q JOIN candidates c ON c.id = q.candidate_id LEFT JOIN roles r ON r.id = q.role_id
            WHERE q.parent_id IS NULL AND q.status = 'declined' AND q.declined_at >= NOW() - INTERVAL 3 DAY ORDER BY q.declined_at DESC LIMIT 5") as $r) {
            $out[] = ['icon' => 'x', 'tone' => 'warn', 'title' => "{$r['full_name']} is no longer interested", 'sub' => ($r['title'] ?? 'No role named') . ' · moved to Withdrew', 'url' => "/candidates/{$r['candidate_id']}", 'cta' => 'View'];
        }
        foreach ($this->db->all("SELECT m.candidate_id, c.full_name, m.removal, m.body, m.source FROM candidate_messages m JOIN candidates c ON c.id = m.candidate_id
                                 WHERE m.removal = 'asked' OR m.read_at IS NULL ORDER BY m.removal = 'asked' DESC, m.received_at DESC LIMIT 8") as $r) {
            $out[] = $r['removal'] === 'asked'
                ? ['icon' => 'alert', 'tone' => 'warn', 'title' => "{$r['full_name']} may be asking to be removed", 'sub' => mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $r['body']), 0, 90, '…'), 'url' => "/candidates/{$r['candidate_id']}#messages", 'cta' => 'Decide']
                : ['icon' => 'chat', 'tone' => '', 'title' => "Message from {$r['full_name']}", 'sub' => mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $r['body']), 0, 90, '…'), 'url' => "/candidates/{$r['candidate_id']}#messages", 'cta' => 'Reply'];
        }
        foreach ($this->db->all('SELECT id, full_name FROM candidates WHERE pending_contact IS NOT NULL ORDER BY updated_at DESC LIMIT 5') as $r) {
            $out[] = ['icon' => 'mail', 'tone' => 'warn', 'title' => "{$r['full_name']} gave a new email or mobile", 'sub' => 'Waiting for you to approve it', 'url' => "/candidates/{$r['id']}", 'cta' => 'Check'];
        }
        return $out;
    }

    /** For each open role, how many people sit at each active stage. */
    private function pipes(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $roleIds));
        $out = [];
        foreach ($this->db->all("SELECT a.role_id, s.sort, COUNT(*) AS n FROM applications a JOIN stages s ON s.id = a.stage_id
                                 WHERE a.role_id IN ($in) AND s.kind NOT IN ('fail','hold') GROUP BY a.role_id, s.sort") as $r) {
            $band = match (true) { $r['sort'] < 20 => 0, $r['sort'] < 40 => 1, $r['sort'] < 50 => 2, $r['sort'] < 60 => 3, default => 4 };
            $out[(int) $r['role_id']][$band] = ($out[(int) $r['role_id']][$band] ?? 0) + (int) $r['n'];
        }
        return $out;
    }
}
