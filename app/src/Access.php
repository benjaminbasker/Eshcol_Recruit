<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * Who may see what (B6, 14 Nov 2026). Owners and recruiters see everything (owners also Settings).
 * A contractor sees only the positions the owner assigned them, and the candidates on those positions.
 *
 * The rule is enforced in one place, the router, before any page runs: a contractor may use only the
 * pages listed in ALLOWED, and each one is checked against the record it opens (a position, a candidate,
 * an application, a CV, an interview, a shortlist). Everything else is refused (403). The pages they
 * may use then also filter their lists (positions, search, the upload form, a candidate's roles).
 */
final class Access
{
    /**
     * What the first number in the address is, for every signed-in page that has one (audit S2, 16 Nov 2026).
     * 'none' = not tied to a position (clients, settings, owner-only pages). A page missing here is refused to anyone
     * with hidden positions; tests/access-routes-test.php fails if a route is added without an entry.
     */
    public const KINDS = [
        'ClientController::show' => 'none', 'ClientController::edit' => 'none', 'ClientController::logo' => 'none', 'ClientController::update' => 'none',
        'ClientController::addContact' => 'none', 'ClientController::portalAccess' => 'none',
        'RoleController::show' => 'role', 'RoleController::screen' => 'role', 'RoleController::edit' => 'role', 'RoleController::update' => 'role', 'RoleController::rescore' => 'role',
        'RoleController::matches' => 'role', 'RoleController::addCandidate' => 'role', 'RoleController::found' => 'role',
        'PostingController::show' => 'role',
        'FitController::save' => 'role', 'FitController::copy' => 'role', 'FitController::suggest' => 'role', 'FitController::queue' => 'role',
        'FitController::step' => 'role', 'FitController::examplesFromClient' => 'role', 'FitController::examplesReassess' => 'role',
        'FitController::example' => 'role', 'FitController::exampleNote' => 'role', 'FitController::rulesSuggest' => 'role',
        'FitController::ruleDecide' => 'role', 'FitController::fairCheck' => 'role', 'FitController::addAll' => 'role',
        'FitController::force' => 'application',
        'CategoryController::testShow' => 'none', 'CategoryController::testStep' => 'none', 'CategoryController::testDelete' => 'none',
        'CategoryController::candidateSave' => 'candidate', 'CategoryController::candidateConfirm' => 'candidate', 'CategoryController::candidateNow' => 'candidate',
        'CandidateController::show' => 'candidate', 'CandidateController::whatsapp' => 'candidate', 'CandidateController::edit' => 'candidate',
        'CandidateController::update' => 'candidate', 'CandidateController::note' => 'candidate', 'CandidateController::preferredCv' => 'candidate',
        'CandidateController::unreliable' => 'candidate', 'CandidateController::redList' => 'candidate', 'CandidateController::liftClientRejection' => 'candidate', 'CandidateController::addToRole' => 'candidate', 'CandidateController::destroy' => 'candidate',
        'CandidateController::route' => 'candidate', 'CandidateController::detailsReviewed' => 'candidate', 'CandidateController::deleteView' => 'none',
        'CandidateController::downloadCv' => 'cv', 'CandidateController::saveCvText' => 'cv',
        'CandidateController::stage' => 'application', 'CandidateController::override' => 'application', 'CandidateController::reason' => 'application',
        'CandidateController::review' => 'application', 'CandidateController::permission' => 'application', 'CandidateController::why' => 'application',
        'DecisionController::feedback' => 'application', 'DecisionController::reference' => 'application',
        'DecisionController::offer' => 'application', 'DecisionController::joining' => 'application',
        'AiController::analyse' => 'candidate',
        'QuestionnaireController::contact' => 'candidate', 'QuestionnaireController::removal' => 'none',
        'QuestionnaireController::editor' => 'role', 'QuestionnaireController::save' => 'role', 'QuestionnaireController::sendRole' => 'role',
        'QuestionnaireController::sendOne' => 'application',
        'MessageController::send' => 'candidate',
        'ShortlistController::create' => 'role', 'ShortlistController::store' => 'role', 'ShortlistController::preview' => 'role',
        'ShortlistController::show' => 'shortlist', 'ShortlistController::pdf' => 'shortlist', 'ShortlistController::resend' => 'shortlist',
        'ShortlistController::close' => 'shortlist', 'ShortlistController::chased' => 'shortlist', 'ShortlistController::emailPreview' => 'shortlist',
        'ShortlistController::send' => 'shortlist', 'ShortlistController::discard' => 'shortlist', 'ShortlistController::cvPlan' => 'shortlist',
        'ShortlistController::cvCover' => 'shortlist', 'ShortlistController::cvSource' => 'shortlist', 'ShortlistController::cvBranded' => 'shortlist',
        'ShortlistController::cvRender' => 'shortlist', 'ShortlistController::cvView' => 'shortlist',
        'DocumentController::download' => 'document', 'DocumentController::delete' => 'document',
        'InterviewController::addSlots' => 'role', 'InterviewController::inviteShortlisted' => 'role', 'InterviewController::deleteSlot' => 'slot',
        'InterviewController::invite' => 'application', 'InterviewController::book' => 'application',
        'InterviewController::outcome' => 'interview', 'InterviewController::cancel' => 'interview', 'InterviewController::letter' => 'interview',
        'InterviewController::reschedule' => 'interview', 'InterviewController::chooseAgain' => 'interview',
        'EmailController::show' => 'none', 'EmailController::body' => 'none', 'EmailController::release' => 'none',
        'EmailController::cancel' => 'none', 'EmailController::retry' => 'none',
        'NewTermsController::suggest' => 'role', 'NewTermsController::acceptForRole' => 'role',
        'StaffingController::deleteWage' => 'none', 'StaffingController::saveTerms' => 'none',
        'ReaderTestController::show' => 'none', 'ReaderTestController::run' => 'none', 'ReaderTestController::delete' => 'none',
        'ReaderTestController::review' => 'none', 'ReaderTestController::saveTruth' => 'none',
        'HealthController::clearEmail' => 'candidate', 'HealthController::exportData' => 'candidate',
        'SettingsController::userPositions' => 'none', 'SettingsController::userActive' => 'none',
        'JoiningController::json' => 'none', 'JoiningController::status' => 'none', 'JoiningController::refresh' => 'none',
        'PlacementController::save' => 'none', 'PlacementController::checkin' => 'none', 'PlacementController::left' => 'none',
    ];

    /** What a contractor may use (the number in the address is checked by KINDS). */
    private const ALLOWED = [
        'DashboardController::index', 'AuthController::logout', 'SearchController::quick',
        'SettingsController::account', 'SettingsController::changePassword', 'SettingsController::forgetDevices',
        'SettingsController::saveProfile', 'SettingsController::changeEmail',
        'RoleController::index', 'RoleController::show',
        'CandidateController::show', 'CandidateController::why', 'CandidateController::whatsapp', 'CandidateController::note', 'CandidateController::downloadCv',
        'CandidateController::stage', 'CandidateController::permission', 'CandidateController::review', 'CandidateController::reason',
        'UploadController::page', 'UploadController::startBatch', 'UploadController::file',
        'InterviewController::addSlots', 'InterviewController::deleteSlot', 'InterviewController::invite',
        'InterviewController::inviteShortlisted', 'InterviewController::book', 'InterviewController::outcome',
        'InterviewController::cancel', 'InterviewController::letter', 'InterviewController::reschedule', 'InterviewController::chooseAgain',
        'QuestionnaireController::sendOne', 'MessageController::send',
        'ShortlistController::create', 'ShortlistController::store', 'ShortlistController::preview',
        'ShortlistController::show', 'ShortlistController::pdf', 'ShortlistController::resend',
        'ShortlistController::close', 'ShortlistController::chased', 'ShortlistController::emailPreview', 'ShortlistController::send',
        'ShortlistController::discard', 'ShortlistController::cvPlan', 'ShortlistController::cvCover',
        'ShortlistController::cvSource', 'ShortlistController::cvBranded', 'ShortlistController::cvRender',
        'ShortlistController::cvView',
        'DecisionController::feedback', 'DecisionController::reference',
    ];

    /** @var array<int, list<int>> user id => role ids, for the request */
    private static array $roles = [];
    /** @var array<int, list<int>> user id => our-own-team positions hidden from them, for the request */
    private static array $hidden = [];

    /**
     * Our own team's positions are private (15 Nov 2026): salaries of Eshcol's own hires. A recruiter sees one only when
     * they are its hiring manager or were given it (role_access). The owner sees everything; contractors see only theirs.
     * @return list<int>
     */
    public static function hiddenRoleIds(?array $user): array
    {
        if (($user['role'] ?? '') !== 'recruiter') {
            return [];
        }
        $uid = (int) $user['id'];
        try {
            return self::$hidden[$uid] ??= array_map('intval', array_column(App::get()->db->all("SELECT id FROM roles WHERE engagement = 'inhouse'
                AND (hiring_manager_id IS NULL OR hiring_manager_id <> ?) AND id NOT IN (SELECT role_id FROM role_access WHERE user_id = ?)", [$uid, $uid]), 'id'));
        } catch (\PDOException) {
            return []; // before update 048
        }
    }

    /** The position behind a record of this kind (null when it has none, e.g. a candidate). */
    private static function roleOf(string $kind, int $id): ?int
    {
        $db = App::get()->db;
        $v = match ($kind) {
            'role' => $id,
            'application' => $db->col('SELECT role_id FROM applications WHERE id = ?', [$id]),
            'interview' => $db->col('SELECT a.role_id FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = ?', [$id]),
            'slot' => $db->col('SELECT role_id FROM interview_slots WHERE id = ?', [$id]),
            'shortlist' => $db->col('SELECT role_id FROM shortlists WHERE id = ?', [$id]),
            'document' => $db->col("SELECT owner_id FROM documents WHERE id = ? AND owner_type = 'role'", [$id]),
            default => null,
        };
        return $v === null || $v === false ? null : (int) $v;
    }

    public static function restricted(?array $user): bool
    {
        return ($user['role'] ?? '') === 'contractor';
    }

    /** The positions a contractor may see (ids). */
    public static function roleIds(int $userId): array
    {
        return self::$roles[$userId] ??= array_map('intval', array_column(App::get()->db->all('SELECT role_id FROM role_access WHERE user_id = ?', [$userId]), 'role_id'));
    }

    /** May this user open this page with these numbers in its address? */
    public static function allowsRoute(array $user, string $controllerClass, string $method, array $args): bool
    {
        $key = substr(strrchr('\\' . $controllerClass, '\\'), 1) . '::' . $method;
        if (!self::restricted($user)) {
            // A recruiter and our own team's private positions (15 Nov 2026): no page about one of them, or about the
            // applications, interviews and shortlists on it. Candidates themselves stay visible (the shared database).
            $hidden = self::hiddenRoleIds($user);
            if (!$hidden) {
                return true;
            }
            // A position named in a form counts too, under whatever field the form uses (audit S2, 17 Nov 2026).
            if (array_intersect(self::postedRoleIds($key), $hidden)) {
                return false;
            }
            if (!isset($args[0]) || !is_int($args[0])) {
                return true;
            }
            if (!isset(self::KINDS[$key])) {
                return false;
            }
            $kind = self::KINDS[$key];
            $rid = in_array($kind, ['none', 'candidate', 'cv'], true) ? null : self::roleOf($kind, $args[0]);
            return $rid === null || !in_array($rid, $hidden, true);
        }
        if (!in_array($key, self::ALLOWED, true)) {
            return false;
        }
        // Positions named in the form must be theirs too (audit S2).
        $mine = self::roleIds((int) $user['id']);
        foreach (self::postedRoleIds($key) as $rid) {
            if (!in_array($rid, $mine, true)) {
                return false;
            }
        }
        $kind = self::KINDS[$key] ?? 'none';
        return $kind === 'none' || self::allows($user, $kind, (int) ($args[0] ?? 0));
    }

    /**
     * Every position a submitted form refers to (audit S2, 17 Nov 2026): role_id (add to / upload to a position),
     * owner_id when owner_type is "role" (a document on a position), and "from" on the requirements copy.
     * @return list<int>
     */
    public static function postedRoleIds(string $key, ?array $post = null): array
    {
        $p = $post ?? $_POST;
        $ids = [(int) ($p['role_id'] ?? 0)];
        if (($p['owner_type'] ?? '') === 'role') {
            $ids[] = (int) ($p['owner_id'] ?? 0);
        }
        if ($key === 'FitController::copy') {
            $ids[] = (int) ($p['from'] ?? 0);
        }
        return array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
    }

    /** May this user see this record? Always yes for owners and recruiters. */
    public static function allows(array $user, string $kind, int $id): bool
    {
        if (!self::restricted($user)) {
            return true;
        }
        $mine = self::roleIds((int) $user['id']);
        if (!$mine || $id <= 0) {
            return false;
        }
        $db = App::get()->db;
        $in = implode(',', $mine);
        return (bool) match ($kind) {
            'role' => in_array($id, $mine, true),
            'candidate' => $db->col("SELECT 1 FROM applications WHERE candidate_id = ? AND role_id IN ($in) LIMIT 1", [$id]),
            'application' => $db->col("SELECT 1 FROM applications WHERE id = ? AND role_id IN ($in)", [$id]),
            'cv' => $db->col("SELECT 1 FROM cv_files f JOIN applications a ON a.candidate_id = f.candidate_id WHERE f.id = ? AND a.role_id IN ($in) LIMIT 1", [$id]),
            'interview' => $db->col("SELECT 1 FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = ? AND a.role_id IN ($in)", [$id]),
            'slot' => $db->col("SELECT 1 FROM interview_slots WHERE id = ? AND role_id IN ($in)", [$id]),
            'shortlist' => $db->col("SELECT 1 FROM shortlists WHERE id = ? AND role_id IN ($in)", [$id]),
            default => false,
        };
    }

    /** A SQL condition limiting role ids (column $col) to what the user may see: '1=1' for everyone else. */
    public static function roleFilter(?array $user, string $col): string
    {
        if (!self::restricted($user)) {
            $hidden = self::hiddenRoleIds($user);
            return $hidden ? "$col NOT IN (" . implode(',', $hidden) . ')' : '1=1';
        }
        $mine = self::roleIds((int) $user['id']);
        return $mine ? "$col IN (" . implode(',', $mine) . ')' : '1=0';
    }

    /**
     * A SQL condition on email_queue (alias $e) leaving out, for a recruiter with hidden positions, the emails about those
     * positions (found through what the email is for: the position, interview, details request or shortlist) and internal
     * emails (alerts, summaries, client replies, tests) addressed to someone else. '1=1' for everyone else (16 Nov 2026).
     */
    public static function emailFilter(?array $user, string $e = 'e'): string
    {
        $hidden = self::restricted($user) ? [] : self::hiddenRoleIds($user);
        if (!$hidden) {
            return '1=1';
        }
        $in = implode(',', $hidden);
        $me = App::get()->db->pdo()->quote(mb_strtolower((string) (App::get()->db->col('SELECT email FROM users WHERE id = ?', [(int) $user['id']]) ?: '')));
        $role = "COALESCE(JSON_VALUE($e.meta, '$.roles[0]'),
            (SELECT a.role_id FROM interviews i JOIN applications a ON a.id = i.application_id WHERE i.id = JSON_VALUE($e.meta, '$.interview')),
            (SELECT a.role_id FROM questionnaires q JOIN applications a ON a.id = q.application_id WHERE q.id = JSON_VALUE($e.meta, '$.questionnaire')),
            (SELECT s.role_id FROM shortlists s WHERE s.id = JSON_VALUE($e.meta, '$.shortlist')), 0)";
        return "($role NOT IN ($in) AND ($e.kind NOT IN ('alert','digest','client_reply','test') OR LOWER($e.to_email) = $me))";
    }

    /** Gives a contractor exactly these positions (replacing what they had). */
    public static function assign(int $userId, array $roleIds, int $grantedBy): void
    {
        $db = App::get()->db;
        $db->tx(function () use ($db, $userId, $roleIds, $grantedBy) {
            $db->run('DELETE FROM role_access WHERE user_id = ?', [$userId]);
            foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
                if ($rid > 0 && $db->col('SELECT 1 FROM roles WHERE id = ? AND is_pool = 0', [$rid])) {
                    $db->insert('role_access', ['user_id' => $userId, 'role_id' => $rid, 'granted_by' => $grantedBy]);
                }
            }
        });
        unset(self::$roles[$userId]);
        Audit::log('contractor_positions_set', 'user', $userId, ['roles' => array_values(array_map('intval', $roleIds))]);
    }
}
