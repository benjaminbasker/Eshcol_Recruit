<?php
/**
 * 17 Nov 2026 (Benjamin): one decision for both of a client's positions, and back to the same row after a stage
 * change. Run: php tests/mirror-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Controllers\CandidateController;
use Eshcol\Ats\Services\StageMover;
use Eshcol\Ats\Services\Stages;

$app = App::boot(cli: true);
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$cl = $db->insert('clients', ['name' => "ZZ Mirror $tag"]);
$cl2 = $db->insert('clients', ['name' => "ZZ Mirror Other $tag"]);
$made = [];
register_shutdown_function(function () use ($db, &$made, $cl, $cl2, &$ok, &$bad) {
    foreach ($made as $cid) {
        $db->run('DELETE FROM stage_history WHERE application_id IN (SELECT id FROM applications WHERE candidate_id = ?)', [$cid]);
        $db->run('DELETE FROM applications WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$cid]);
    }
    $db->run('DELETE FROM roles WHERE client_id IN (?, ?)', [$cl, $cl2]);
    $db->run('DELETE FROM clients WHERE id IN (?, ?)', [$cl, $cl2]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$assoc = $db->insert('roles', ['client_id' => $cl, 'title' => "Associate $tag", 'status' => 'open']);
$mgr = $db->insert('roles', ['client_id' => $cl, 'title' => "Manager $tag", 'status' => 'open']);
$elsewhere = $db->insert('roles', ['client_id' => $cl2, 'title' => "Elsewhere $tag", 'status' => 'open']);
$applied = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
$review = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND (name LIKE '%review%' OR name LIKE '%Screening%') ORDER BY sort LIMIT 1");
$short = (int) Stages::id($app, Stages::SHORTLISTED);
$future = (int) Stages::id($app, Stages::FUTURE);
$ns = (int) Stages::id($app, Stages::NOT_SUITABLE);
$mover = new StageMover($app);
$person = function (string $name) use ($db, $tag, $assoc, $mgr, $elsewhere, $applied, &$made): array {
    $cid = $db->insert('candidates', ['full_name' => "$name $tag"]);
    $made[] = $cid;
    $ids = [];
    foreach ([$assoc, $mgr, $elsewhere] as $r) {
        $ids[] = $db->insert('applications', ['candidate_id' => $cid, 'role_id' => $r, 'stage_id' => $applied, 'source' => 'upload']);
    }
    return $ids;
};
$stageOf = fn (int $appId) => (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$appId]);
$reasonOf = fn (int $appId) => (string) $db->col('SELECT stage_reason FROM applications WHERE id = ?', [$appId]);
$move = function (int $appId, int $to, ?string $note = null) use ($db, $mover, $uid): array {
    $a = $db->one('SELECT * FROM applications WHERE id = ?', [$appId]);
    $mover->move($a, $to, $note, $uid, true);
    return $mover->mirror($a, $to, $note, $uid, true);
};

echo "Future requirement on one position\n";
[$a1, $m1, $e1] = $person('Asha');
$msgs = $move($a1, $future, 'Good, but wants 6 LPA');
$check('…the client\'s other position is Future requirement too', $stageOf($m1) === $future);
$check('…with the same note', str_contains($reasonOf($m1), 'Same as for Associate') && str_contains($reasonOf($m1), 'Good, but wants 6 LPA'));
$check('…another client\'s position is left alone', $stageOf($e1) === $applied);
$check('…and you are told: "' . ($msgs[0] ?? '') . '"', count($msgs) === 1 && str_contains($msgs[0], "Also on Manager $tag: Future requirement"));

echo "\nUnder review or Shortlisted on one position\n";
[$a2, $m2, $e2] = $person('Bala');
$move($a2, $review ?: $short);
$check('Under review on Associate: Manager becomes Not suitable', $stageOf($m2) === $ns && $reasonOf($m2) === "Taken forward for Associate $tag instead");
$check('…another client\'s position is left alone', $stageOf($e2) === $applied);
[$a3, $m3] = $person('Chitra');
$move($m3, $short);
$check('Shortlisted on Manager: Associate becomes Not suitable', $stageOf($a3) === $ns && str_contains($reasonOf($a3), "Taken forward for Manager $tag"));
[$a4, $m4] = $person('Deepa');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$short, $m4]);
$move($a4, $short);
$check('someone already Shortlisted on the other position is never touched', $stageOf($m4) === $short);
[$a5, $m5] = $person('Ezhil');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$ns, $m5]);
$msgs5 = $move($a5, $future);
$check('a closed stage on the other position stays as it is', $stageOf($m5) === $ns && $msgs5 === []);
[$a6, $m6] = $person('Fathima');
$check('Not suitable on one position changes nothing elsewhere', $move($a6, $ns, 'No GST') === [] && $stageOf($m6) === $applied);

echo "\nBack to the same row after a stage change\n";
$check('back to the list, at the row', CandidateController::backToRow('/roles/1?who=responded', 55, '/roles/1') === '/roles/1?who=responded#app-55');
$check('an anchor the page had is replaced by the row', CandidateController::backToRow('/roles/1?view=list#tab-pipeline', 55, '/roles/1') === '/roles/1?view=list#app-55');
$check('from the candidate\'s page: back there, at that position', CandidateController::backToRow('/candidates/9?role=1', 55, '/roles/1') === '/candidates/9?role=1#app-55');
$check('an address off this app is never followed', CandidateController::backToRow('//evil.example/x', 55, '/roles/1') === '/roles/1#app-55' && CandidateController::backToRow('https://evil.example', 55, '/roles/1') === '/roles/1#app-55');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$check('when the row has left the list, the page returns to where you were (scroll kept for a minute)', str_contains($js, "sessionStorage.setItem('ats-return'") && str_contains($js, 'window.scrollTo(0, r.y || 0)'));
$check('the candidate page\'s position blocks carry the row anchor', str_contains((string) file_get_contents(dirname(__DIR__) . '/views/candidates/show.php'), '<div id="app-<?= (int) $a[\'id\'] ?>" class="card'));
