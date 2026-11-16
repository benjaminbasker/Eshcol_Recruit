<?php
/**
 * Contractors (B6, 14 Nov 2026): a contractor sees only the positions assigned to them and the candidates
 * on those positions. Checked at the level the router uses (every page, every record), on throwaway data.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/contractor-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Access;
use Eshcol\Ats\App;
use Eshcol\Ats\Controllers\CandidateController;
use Eshcol\Ats\Controllers\ClientController;
use Eshcol\Ats\Controllers\DashboardController;
use Eshcol\Ats\Controllers\RoleController;
use Eshcol\Ats\Controllers\SettingsController;
use Eshcol\Ats\Controllers\ShortlistController;
use Eshcol\Ats\Controllers\UploadController;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Retention;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$was = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "ZZ Contractor $tag"]);
$mk = function (string $t) use ($db, $client, $tag): int {
    $id = $db->insert('roles', ['client_id' => $client, 'title' => "$t ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
    return $id;
};
$mine = $mk('Accounts Officer');
$other = $mk('Plant Manager');
$made = [];
$users = [];
register_shutdown_function(function () use ($app, $db, &$made, &$users, $client, $was, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    foreach ($users as $u) {
        $db->run('DELETE FROM users WHERE id = ?', [$u]);
    }
    $was === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$was]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-contractor-' . getmypid() . '.txt');
$person = function (string $name, int $role) use ($imp, $tmp, $uid, $tag, &$made): array {
    file_put_contents($tmp, "$name\n" . strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccountant, 2021 – Present\n4 years of experience, Tally, GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $role, $uid);
    $made[] = (int) $r['candidate_id'];
    return [(int) $r['candidate_id'], (int) $r['cv_file_id']];
};
[$cMine, $fMine] = $person('Asha Mineonly', $mine);
[$cOther, $fOther] = $person('Bala Otherrole', $other);
$appMine = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cMine, $mine]);
$appOther = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cOther, $other]);
@unlink($tmp);

$k = $db->insert('users', ['name' => "Kiran Contractor $tag", 'email' => "kiran.$tag@contractors.test", 'role' => 'contractor', 'password_hash' => 'x']);
$users[] = $k;
$rec = $db->insert('users', ['name' => "Rita Recruiter $tag", 'email' => "rita.$tag@contractors.test", 'role' => 'recruiter', 'password_hash' => 'x']);
$users[] = $rec;
$K = ['id' => $k, 'role' => 'contractor'];
$R = ['id' => $rec, 'role' => 'recruiter'];

echo "Before any position is given\n";
$check('a contractor with no positions sees nothing', !Access::allows($K, 'role', $mine) && Access::roleFilter($K, 'r.id') === '1=0');

Access::assign($k, [$mine, 0, 999999999], $uid);
echo "\nThe contractor's own position\n";
$check('given one position, that is all they have (nonsense ids ignored)', Access::roleIds($k) === [$mine]);
$check('they may open it, and its candidate, application and CV', Access::allows($K, 'role', $mine) && Access::allows($K, 'candidate', $cMine)
    && Access::allows($K, 'application', $appMine) && Access::allows($K, 'cv', $fMine));
$check('lists are limited to it', Access::roleFilter($K, 'r.id') === "r.id IN ($mine)");

echo "\nEverything else\n";
$check('not the other position, its candidate, application or CV', !Access::allows($K, 'role', $other) && !Access::allows($K, 'candidate', $cOther)
    && !Access::allows($K, 'application', $appOther) && !Access::allows($K, 'cv', $fOther));
$route = fn (array $u, string $c, string $m, array $args = []) => Access::allowsRoute($u, $c, $m, $args);
$check('opening their position: yes; the other: no', $route($K, RoleController::class, 'show', [$mine]) && !$route($K, RoleController::class, 'show', [$other]));
$check('a candidate on their position: yes; another: no', $route($K, CandidateController::class, 'show', [$cMine]) && !$route($K, CandidateController::class, 'show', [$cOther]));
$check('moving a stage on their position only', $route($K, CandidateController::class, 'stage', [$appMine]) && !$route($K, CandidateController::class, 'stage', [$appOther]));
$check('downloading a CV on their position only', $route($K, CandidateController::class, 'downloadCv', [$fMine]) && !$route($K, CandidateController::class, 'downloadCv', [$fOther]));
$check('preparing a shortlist for their position only', $route($K, ShortlistController::class, 'create', [$mine]) && !$route($K, ShortlistController::class, 'create', [$other]));
$check('the candidate database: no', !$route($K, CandidateController::class, 'index'));
$check('clients: no', !$route($K, ClientController::class, 'index') && !$route($K, ClientController::class, 'show', [$client]));
$check('Settings: no (their own account page: yes)', !$route($K, SettingsController::class, 'index') && $route($K, SettingsController::class, 'account'));
$check('editing a position, or finding people in the database for it: no', !$route($K, RoleController::class, 'edit', [$mine]) && !$route($K, RoleController::class, 'matches', [$mine]));
$check('editing or deleting a candidate: no', !$route($K, CandidateController::class, 'edit', [$cMine]) && !$route($K, CandidateController::class, 'destroy', [$cMine]));
$check('My day and the roles list open (both show only their positions)', $route($K, DashboardController::class, 'index') && $route($K, RoleController::class, 'index'));
$check('uploading opens; the upload itself checks the position', $route($K, UploadController::class, 'page') && $route($K, UploadController::class, 'startBatch'));
$check('a page not on the list is refused, even an unknown one', !$route($K, 'Eshcol\\Ats\\Controllers\\EmailController', 'index') && !$route($K, 'Nope', 'nothing'));

echo "\nEveryone else is unaffected\n";
$check('a recruiter sees everything', $route($R, CandidateController::class, 'index') && $route($R, RoleController::class, 'show', [$other]) && Access::roleFilter($R, 'r.id') === '1=1');
$check('so does the owner', $route(['id' => $uid, 'role' => 'owner'], SettingsController::class, 'index') && Access::allows(['id' => $uid, 'role' => 'owner'], 'candidate', $cOther));

echo "\nChanging their positions\n";
Access::assign($k, [$other], $uid);
$check('given a different position, they lose the first', Access::roleIds($k) === [$other] && !Access::allows($K, 'candidate', $cMine) && Access::allows($K, 'candidate', $cOther));
Access::assign($k, [], $uid);
$check('all taken away: they see nothing again', Access::roleIds($k) === [] && !Access::allows($K, 'role', $other));
