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
     * What a contractor may use: "Controller::method" => what the first number in the address is
     * (null: no number, or nothing to check).
     */
    private const ALLOWED = [
        'DashboardController::index' => null, 'AuthController::logout' => null, 'SearchController::quick' => null,
        'SettingsController::account' => null, 'SettingsController::changePassword' => null, 'SettingsController::forgetDevices' => null,
        'SettingsController::saveProfile' => null, 'SettingsController::changeEmail' => null,
        'RoleController::index' => null, 'RoleController::show' => 'role',
        'CandidateController::show' => 'candidate', 'CandidateController::why' => 'application', 'CandidateController::whatsapp' => 'candidate', 'CandidateController::note' => 'candidate', 'CandidateController::downloadCv' => 'cv',
        'CandidateController::stage' => 'application', 'CandidateController::permission' => 'application', 'CandidateController::review' => 'application', 'CandidateController::reason' => 'application',
        'UploadController::page' => null, 'UploadController::startBatch' => null, 'UploadController::file' => null,
        'InterviewController::addSlots' => 'role', 'InterviewController::deleteSlot' => 'slot', 'InterviewController::invite' => 'application',
        'InterviewController::inviteShortlisted' => 'role', 'InterviewController::book' => 'application', 'InterviewController::outcome' => 'interview',
        'InterviewController::cancel' => 'interview', 'InterviewController::letter' => 'interview',
        'QuestionnaireController::sendOne' => 'application', 'MessageController::send' => 'candidate',
        'ShortlistController::create' => 'role', 'ShortlistController::store' => 'role', 'ShortlistController::preview' => 'role',
        'ShortlistController::show' => 'shortlist', 'ShortlistController::pdf' => 'shortlist', 'ShortlistController::resend' => 'shortlist',
        'ShortlistController::close' => 'shortlist', 'ShortlistController::chased' => 'shortlist', 'ShortlistController::emailPreview' => 'shortlist', 'ShortlistController::send' => 'shortlist',
        'ShortlistController::discard' => 'shortlist', 'ShortlistController::cvPlan' => 'shortlist', 'ShortlistController::cvCover' => 'shortlist',
        'ShortlistController::cvSource' => 'shortlist', 'ShortlistController::cvBranded' => 'shortlist', 'ShortlistController::cvRender' => 'shortlist',
        'ShortlistController::cvView' => 'shortlist',
        'DecisionController::feedback' => 'application', 'DecisionController::reference' => 'application',
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
            if (!$hidden || !isset($args[0]) || !is_int($args[0])) {
                return true;
            }
            $ctl = strtok($key, ':');
            $kind = self::ALLOWED[$key] ?? (in_array($ctl, ['RoleController', 'FitController', 'PostingController'], true)
                || in_array($key, ['QuestionnaireController::editor', 'QuestionnaireController::save', 'QuestionnaireController::sendRole',
                    'NewTermsController::suggest', 'NewTermsController::acceptForRole'], true) ? 'role' : null);
            $kind = $kind === 'candidate' || $kind === 'cv' ? null : $kind;
            $rid = $kind ? self::roleOf($kind, $args[0]) : null;
            return $rid === null || !in_array($rid, $hidden, true);
        }
        if (!array_key_exists($key, self::ALLOWED)) {
            return false;
        }
        $kind = self::ALLOWED[$key];
        return $kind === null || self::allows($user, $kind, (int) ($args[0] ?? 0));
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
