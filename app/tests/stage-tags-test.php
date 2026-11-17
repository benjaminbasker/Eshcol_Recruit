<?php
/**
 * 17 Nov 2026: (1) a candidate's stage shows next to their name on every list; (2) a position's
 * Responded / Yet to respond lists, ranked by Claude's assessment. Run: php tests/stage-tags-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\KeyFacts as KF;

$app = App::boot(cli: true);
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};

echo "Stage tags\n";
$tag = bin2hex(random_bytes(3));
$cl = $db->insert('clients', ['name' => "Zz StageTags $tag"]);
$r1 = $db->insert('roles', ['client_id' => $cl, 'title' => "Zz Associate $tag", 'status' => 'open']);
$r2 = $db->insert('roles', ['client_id' => $cl, 'title' => "Zz Manager $tag", 'status' => 'open']);
$r3 = $db->insert('roles', ['client_id' => $cl, 'title' => "Zz Old $tag", 'status' => 'filled']);
$c = $db->insert('candidates', ['full_name' => "Zz Tagged $tag", 'email' => "tagged.$tag@candidates.test"]);
$stage = fn (string $n) => (int) $db->col('SELECT id FROM stages WHERE name = ?', [$n]);
$db->insert('applications', ['candidate_id' => $c, 'role_id' => $r1, 'stage_id' => $stage('Shortlisted')]);
$db->insert('applications', ['candidate_id' => $c, 'role_id' => $r2, 'stage_id' => $stage('Future requirement') ?: $stage('Applied')]);
$db->insert('applications', ['candidate_id' => $c, 'role_id' => $r3, 'stage_id' => $stage('Applied')]);
KF::forgetStages();
KF::primeStages($app, [$c]);
$all = KF::stageTags($app, $c);
$check('each open position: "Title · Stage", coloured', str_contains($all, "Zz Associate $tag · Shortlisted") && str_contains($all, 'st-short') && str_contains($all, "Zz Manager $tag ·"));
$check('a closed position is left out', !str_contains($all, "Zz Old $tag"));
$one = KF::stageTags($app, $c, $r1);
$check('on a list about one position: just that stage, no title', str_contains($one, '>Shortlisted<') && !str_contains($one, 'Zz Associate') && !str_contains($one, 'Zz Manager'));
$check('nobody (id 0): nothing', KF::stageTags($app, 0) === '');
$check('the search box gets it as text', str_contains(KF::stagesText($app, $c), "Zz Associate $tag: Shortlisted"));
$check('helper stage_tags() gives the same', stage_tags($c) === $all);

echo "\nEvery list shows the stage\n";
$views = dirname(__DIR__) . '/views/';
foreach (['dashboard/index.php', 'emails/index.php', 'messages/index.php', 'questionnaire/ask.php', 'roles/matches.php', 'roles/_interviews.php',
          'shortlist/show.php', 'joinings/index.php', 'roles/_found.php'] as $v) {
    $s = (string) file_get_contents($views . $v);
    $check("$v shows stage tags (and fetches them in one go)", str_contains($s, 'stage_tags(') && str_contains($s, 'prime_stages('));
}
foreach (['candidates/index.php', 'roles/show.php', 'roles/_fit.php', 'roles/_ready.php'] as $v) {
    $check("$v shows the stage", str_contains((string) file_get_contents($views . $v), 'stageChip('));
}

echo "\nResponded / Yet to respond, ranked\n";
$apps = [
    ['id' => 1, 'candidate_id' => 11, 'full_name' => 'Asha', 'stage_kind' => 'active', 'eff_score' => 95],
    ['id' => 2, 'candidate_id' => 12, 'full_name' => 'Bala', 'stage_kind' => 'active', 'eff_score' => 60],
    ['id' => 3, 'candidate_id' => 13, 'full_name' => 'Chitra', 'stage_kind' => 'active', 'eff_score' => 70],
    ['id' => 4, 'candidate_id' => 14, 'full_name' => 'Deepa', 'stage_kind' => 'fail', 'eff_score' => 99],
    ['id' => 5, 'candidate_id' => 15, 'full_name' => 'Ezhil', 'stage_kind' => 'active', 'eff_score' => 80],
    ['id' => 6, 'candidate_id' => 16, 'full_name' => 'Fathima', 'stage_kind' => 'active', 'eff_score' => 50],
    ['id' => 7, 'candidate_id' => 17, 'full_name' => 'Gopi', 'stage_kind' => 'hold', 'eff_score' => 40],
    ['id' => 8, 'candidate_id' => 18, 'full_name' => 'Hari', 'stage_kind' => 'active', 'eff_score' => 99],
];
$kf = [
    11 => ['responded' => 'answered'],
    12 => ['responded' => 'answered'],
    13 => ['responded' => null, 'msg_in' => '2026-11-16 10:00:00'],   // wrote back by email
    14 => ['responded' => 'answered'],                                // Not suitable: left out
    15 => ['responded' => 'asked'],
    16 => ['responded' => null, 'msg_out' => '2026-11-16 10:00:00'],  // we wrote, no answer
    17 => ['responded' => 'asked'],                                   // Future requirement: left out
    18 => ['responded' => 'answered'],                                // keyword 99, Claude hasn't assessed
];
$fitRows = [1 => ['status' => 'done', 'score' => 70], 2 => ['status' => 'done', 'score' => 88], 3 => ['status' => 'batched', 'score' => null]];
$L = KF::replyLists($apps, $kf, $fitRows);
$check('responded: answered the form or wrote back', array_column($L['responded'], 'full_name') === ['Bala', 'Asha', 'Hari', 'Chitra']);
$check('Claude-assessed first: a keyword 99 (Hari) ranks below a Claude 70 (Asha); the two scales are never mixed', array_search('Hari', array_column($L['responded'], 'full_name'), true) === 2 && !KF::byClaude($apps[7], $fitRows) && KF::byClaude($apps[0], $fitRows));
$check('ranked by Claude\'s score, not the keyword score (Bala 88 above Asha 70, though Asha has keyword 95)', $L['responded'][0]['full_name'] === 'Bala');
$check('…the keyword score where Claude hasn\'t finished (Chitra 70)', KF::rankScore($apps[2], $fitRows) === 70);
$check('yet to respond: asked or written to, no answer', array_column($L['waiting'], 'full_name') === ['Ezhil', 'Fathima']);
$check('closed stages left out and counted', $L['closed'] === ['responded' => 1, 'waiting' => 1]);
$check('someone never asked is in neither', !in_array('Zz', array_column(array_merge($L['responded'], $L['waiting']), 'full_name'), true));

echo "\nOne table with tabs\n";
$sv = (string) file_get_contents(dirname(__DIR__) . '/views/roles/show.php');
$check('Top candidates is no longer a separate table', !str_contains($sv, 'class="card top-cands"'));
$check('Shortlisted and Found in database are tabs, not stacked tables', str_contains($sv, "tabUrl('short')") && str_contains($sv, "tabUrl('found')")
    && str_contains($sv, "\$who === 'short'") && str_contains($sv, "\$who === 'found'"));
$check('Responded / Yet to respond have their own stage filters', str_contains($sv, 'sub-tabs') && str_contains($sv, "\$_GET['st']"));

// Clean up.
$db->run('DELETE FROM applications WHERE candidate_id = ?', [$c]);
$db->run('DELETE FROM candidates WHERE id = ?', [$c]);
$db->run('DELETE FROM roles WHERE client_id = ?', [$cl]);
$db->run('DELETE FROM clients WHERE id = ?', [$cl]);
echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
