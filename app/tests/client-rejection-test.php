<?php
/**
 * 20 Nov 2026 (Benjamin): "Rejected by client" is recorded on the candidate, and their CV is never sent to that
 * client again. Run: php tests/client-rejection-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\ClientRejections;
use Eshcol\Ats\Services\FunctionRouting;
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
$clA = $db->insert('clients', ['name' => "ZZ Reject A $tag"]);
$clB = $db->insert('clients', ['name' => "ZZ Reject B $tag"]);
$made = [];
register_shutdown_function(function () use ($db, &$made, $clA, $clB, &$ok, &$bad) {
    foreach ($made as $cid) {
        $db->run('DELETE FROM client_rejections WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM stage_history WHERE application_id IN (SELECT id FROM applications WHERE candidate_id = ?)', [$cid]);
        $db->run('DELETE FROM applications WHERE candidate_id = ?', [$cid]);
        $db->run('DELETE FROM candidates WHERE id = ?', [$cid]);
    }
    $db->run('DELETE FROM shortlists WHERE role_id IN (SELECT id FROM roles WHERE client_id IN (?, ?))', [$clA, $clB]);
    $db->run('DELETE FROM roles WHERE client_id IN (?, ?)', [$clA, $clB]);
    $db->run('DELETE FROM clients WHERE id IN (?, ?)', [$clA, $clB]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$a1 = $db->insert('roles', ['client_id' => $clA, 'title' => "A one $tag", 'status' => 'open']);
$a2 = $db->insert('roles', ['client_id' => $clA, 'title' => "A two $tag", 'status' => 'open']);
$b1 = $db->insert('roles', ['client_id' => $clB, 'title' => "B one $tag", 'status' => 'open']);
$applied = (int) $db->col("SELECT id FROM stages WHERE kind = 'active' AND is_active = 1 ORDER BY sort LIMIT 1");
$short = (int) Stages::id($app, Stages::SHORTLISTED);
$rej = (int) Stages::id($app, Stages::CLIENT_REJECTED);
$mover = new StageMover($app);
$svc = new ClientRejections($app);
$person = function (string $name) use ($db, $tag, $a1, $a2, $b1, $applied, &$made): array {
    $cid = $db->insert('candidates', ['full_name' => "$name $tag", 'source' => 'upload']);
    $made[] = $cid;
    $ids = [$cid];
    foreach ([$a1, $a2, $b1] as $r) {
        $ids[] = $db->insert('applications', ['candidate_id' => $cid, 'role_id' => $r, 'stage_id' => $applied, 'source' => 'upload']);
    }
    return $ids;
};
$stageOf = fn (int $appId) => (int) $db->col('SELECT stage_id FROM applications WHERE id = ?', [$appId]);
$reasonOf = fn (int $appId) => (string) $db->col('SELECT stage_reason FROM applications WHERE id = ?', [$appId]);
$move = function (int $appId, int $to, ?string $note = null) use ($db, $mover, $uid): array {
    $a = $db->one('SELECT * FROM applications WHERE id = ?', [$appId]);
    $mover->move($a, $to, $note, $uid, true);
    return array_merge($mover->mirror($a, $to, $note, $uid, true), $mover->redList($a, $to, $note, $uid, true), $mover->clientRejected($a, $to, $note, $uid, true));
};
$blockedFor = fn (int $cid, int $client) => !$db->col('SELECT c.id FROM candidates c WHERE c.id = ? AND ' . ClientRejections::notRejectedSql($app, 'c', (string) $client), [$cid]);

echo "The stage\n";
$check('"Rejected by client" is in the stage list, as a closing stage', $rej > 0 && $db->col('SELECT kind FROM stages WHERE id = ?', [$rej]) === 'fail'
    && $db->col('SELECT name FROM stages WHERE id = ?', [$rej]) === 'Rejected by client');
$check('…it sits with the closing stages, after Not suitable', (int) $db->col('SELECT sort FROM stages WHERE id = ?', [$rej]) > (int) $db->col('SELECT sort FROM stages WHERE code = ?', [Stages::NOT_SUITABLE]));

echo "\nMoving one application to Rejected by client\n";
[$c1, $x1, $x2, $x3] = $person('Anil');
$msgs = $move($x1, $rej, 'Client felt communication was weak');
$row = $svc->find($c1, $clA);
$check('it is recorded on the candidate, for that client, with the note', $row !== null && $row['source'] === 'stage' && (int) $row['role_id'] === $a1 && $row['note'] === 'Client felt communication was weak');
$check('…their other open application with the same client closes too', $stageOf($x2) === $rej && str_contains($reasonOf($x2), "Rejected by ZZ Reject A $tag for A one $tag"));
$check('…another client\'s application is left alone', $stageOf($x3) === $applied);
$check('…and you are told their CV won\'t go to that client again', str_contains($msgs[0] ?? '', "won't be sent to ZZ Reject A $tag again"));
$check('…not red-listed: other clients can still see them', !$db->col('SELECT red_listed_at FROM candidates WHERE id = ?', [$c1]));
$check('only one record for the client, not one per closed application', (int) $db->col('SELECT COUNT(*) FROM client_rejections WHERE candidate_id = ?', [$c1]) === 1);
$move($x1, $applied);
$move($x1, $rej, 'Again');
$check('moving out and back in does not add a second record for the same application', (int) $db->col('SELECT COUNT(*) FROM client_rejections WHERE candidate_id = ?', [$c1]) === 1);

echo "\nNever sent to that client again\n";
$check('left out for that client', $blockedFor($c1, $clA));
$check('…but not for another client', !$blockedFor($c1, $clB));
$fr = new FunctionRouting($app);
$cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$c1]);
[$res, $why] = $fr->decide($cand, null, $db->one('SELECT * FROM roles WHERE id = ?', [$a2]));
$check('automatic sorting never puts them on that client\'s positions', $res === 'client_rejected' && str_contains($why, "ZZ Reject A $tag"));
[$res2] = $fr->decide($cand, null, $db->one('SELECT * FROM roles WHERE id = ?', [$b1]));
$check('…another client\'s position is judged as usual', $res2 !== 'client_rejected');
$check('the reason has a label in the sorting report', isset(FunctionRouting::RESULTS['client_rejected']));
$src = fn (string $f) => (string) file_get_contents(dirname(__DIR__) . "/$f");
$check('shortlists: never offered or sent to that client', substr_count($src('src/Controllers/ShortlistController.php'), 'ClientRejections::notRejectedSql') === 2);
$check('"Find in database": left out', str_contains($src('src/Services/Matcher.php'), 'ClientRejections::notRejectedSql'));
$check('"Add to role": refused for that client\'s positions, and not offered on their page', str_contains($src('src/Controllers/CandidateController.php'), "notRejectedSql(\$this->app, 'candidates'")
    && str_contains($src('src/Controllers/CandidateController.php'), 'FROM client_rejections cr WHERE cr.candidate_id = " . (int) $id'));

echo "\nAlready shortlisted with the same client\n";
[$c2, $y1, $y2] = $person('Bhavna');
$db->run('UPDATE applications SET stage_id = ? WHERE id = ?', [$short, $y2]);
$msgs2 = $move($y1, $rej, 'Not this one');
$check('an application already Shortlisted or further is left for you to decide', $stageOf($y2) === $short && count(array_filter($msgs2, fn ($m) => str_contains($m, 'decide that one yourself'))) === 1);

echo "\nThe client's answer on a shortlist link\n";
[$c3, , , $z3] = $person('Chandru');
$sl = $db->insert('shortlists', ['role_id' => $b1, 'token_hash' => hash('sha256', $tag), 'token_enc' => 'x', 'to_email' => 'zz@eshcol.test', 'expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
$item = $db->insert('shortlist_items', ['shortlist_id' => $sl, 'application_id' => $z3, 'profile' => '{}']);
$svc->fromPortal($item, 'reject', 'Too junior');
$p = $svc->find($c3, $clB);
$check('"Not suitable" from the client is recorded too', $p !== null && $p['source'] === 'portal' && $p['note'] === 'Too junior');
$check('…and blocks that client', $blockedFor($c3, $clB) && !$blockedFor($c3, $clA));
$svc->fromPortal($item, 'reject', 'Too junior for now');
$check('…answering again updates it, no duplicate', (int) $db->col('SELECT COUNT(*) FROM client_rejections WHERE candidate_id = ?', [$c3]) === 1 && $svc->find($c3, $clB)['note'] === 'Too junior for now');
$svc->fromPortal($item, 'hold', null);
$check('…the client changing the answer takes it back', $svc->find($c3, $clB) === null && !$blockedFor($c3, $clB));
$check('the shortlist page saves it with each answer', str_contains($src('src/Services/Shortlists.php'), 'ClientRejections($this->app))->fromPortal((int) $it[\'id\'], $d, $comment)'));

echo "\nOn the candidate's page\n";
$list = $svc->forCandidate($c1);
$check('their page lists the client who turned them down', count($list) === 1 && $list[0]['client'] === "ZZ Reject A $tag" && $list[0]['title'] === "A one $tag");
$view = $src('views/candidates/show.php');
$check('…shown as a banner with a Lift button', str_contains($view, 'Rejected by <?= e($cr[\'client\']) ?>') && str_contains($view, '/client-rejection/lift'));
$check('lifting it allows their CV to that client again', $svc->lift($c1, $clA, $uid) === 1 && !$blockedFor($c1, $clA));
$check('the lift route is wired', str_contains($src('routes.php'), "'/candidates/{id}/client-rejection/lift'"));
$check('the update also carries over the client\'s earlier "Not suitable" answers', str_contains($src('migrations/074_client_rejections.sql'), "WHERE si.decision = 'reject'"));
$check('"Why rejected by client?" is saved with the client\'s rejection', str_contains($src('src/Controllers/CandidateController.php'), "UPDATE client_rejections SET note = ? WHERE application_id = ? AND source = 'stage'"));
