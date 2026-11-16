<?php
/**
 * The bigger dictionary (10 Nov 2026): O*NET titles and software, matching in chunks, the
 * "New words" list learnt from Claude's readings, and Claude's other wordings for a role's
 * must-haves. Fake Claude replies: no key, no network, no cost. LOCAL ONLY.
 * Usage: php tests/dictionary-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\NewTerms;
use Eshcol\Ats\Services\Skills;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

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
$tag = 'zz' . strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop'); // letters only: words with 3+ digits in a row are not collected
$clientId = $db->insert('clients', ['name' => "ZZ Dict $tag"]);
register_shutdown_function(function () use ($db, $tag, $clientId, &$ok, &$bad) {
    $db->run('DELETE FROM skills_dictionary WHERE term LIKE ? OR canonical LIKE ?', ["%$tag%", "%$tag%"]);
    $db->run('DELETE FROM dictionary_suggestions WHERE term LIKE ? OR canonical LIKE ?', ["%$tag%", "%$tag%"]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    echo "\n$ok passed, $bad failed\n";
});

echo "O*NET in the dictionary\n";
$n = (int) $db->col("SELECT COUNT(*) FROM skills_dictionary WHERE source = 'onet'");
$check("O*NET job titles and software are in ($n)", $n > 5000);
$skills = new Skills($db);
$found = array_keys($skills->find('Worked as Mechanical Draftsman, then Document Control Specialist and Piping Designer. Tools: SmartPlant 3D, Microsoft Power BI, AutoCAD Civil 3D.', 'designation'));
$check('job titles from O*NET and the Indian wordings are recognised [' . implode(', ', $found) . ']',
    in_array('Draftsman', $found, true) && in_array('Document Control Specialist', $found, true) && in_array('Piping Designer', $found, true));
$sk = array_keys($skills->find('Tools: SmartPlant 3D, Microsoft Power BI, AutoCAD Civil 3D, SAP MM.', 'skill'));
$check('software from O*NET and SmartPlant are recognised [' . implode(', ', $sk) . ']', in_array('SP3D', $sk, true) && in_array('Microsoft Power BI', $sk, true) && in_array('SAP MM', $sk, true));
$check('the dictionary is matched in chunks (it no longer fits one pattern), the longest wording winning',
    array_keys($skills->find('Expert in SAP FICO and SAP.', 'skill')) === ['SAP FICO', 'SAP']);
$check('knows(): a known word, and an unknown one', $skills->knows('SmartPlant') && !$skills->knows("Zorblex $tag"));
$t0 = microtime(true);
new Skills($db);
$check('loaded once per request: a second copy takes under 20 ms [' . round((microtime(true) - $t0) * 1000, 1) . ' ms]', microtime(true) - $t0 < 0.02);

echo "\nNew words learnt from CVs\n";
$check('tidy(): parentheses and stray punctuation go; e-mails, long codes and long sentences are not words',
    NewTerms::tidy(' Accounts & Audit Assistant (Contract) ,') === 'Accounts & Audit Assistant' && NewTerms::tidy('a@b.com') === null
    && NewTerms::tidy('PO 123456') === null && NewTerms::tidy('one two three four five six seven') === null);
$svc = new NewTerms($app);
$raw = ['jobs' => [['title' => "Glorp Executive $tag", 'internship' => false], ['title' => 'Senior Accountant', 'internship' => false], ['title' => "Intern Thing $tag", 'internship' => true]],
    'software' => ["Fizzware $tag", 'Tally Prime']];
$svc->collect(1, $raw);
$rows = $db->all('SELECT * FROM dictionary_suggestions WHERE term LIKE ? ORDER BY term', ["%$tag%"]);
$check('unknown job titles and software are collected; known ones and internships are not', array_column($rows, 'label') === ["Fizzware $tag", "Glorp Executive $tag"]
    && array_column($rows, 'category') === ['skill', 'designation']);
$svc->collect(2, $raw);
$check('a second CV with the same words counts them again (2 CVs)', (int) $db->col('SELECT seen FROM dictionary_suggestions WHERE term = ?', [mb_strtolower("Glorp Executive $tag")]) === 2);
$gid = (int) $db->col('SELECT id FROM dictionary_suggestions WHERE term = ?', [mb_strtolower("Glorp Executive $tag")]);
$fid = (int) $db->col('SELECT id FROM dictionary_suggestions WHERE term = ?', [mb_strtolower("Fizzware $tag")]);
$as = $svc->accept($gid, 'Accounts Executive');
$check('added as another wording of an existing title: it joins "Accounts Executive" [' . $as . ']', $as === 'Accounts Executive'
    && $db->col('SELECT canonical FROM skills_dictionary WHERE term = ?', [mb_strtolower("Glorp Executive $tag")]) === 'Accounts Executive');
$check('…and Skills recognises it from then on', ($sk2 = new Skills($db))->canonical("Glorp Executive $tag", 'designation') === 'Accounts Executive');
$check('ignored words are not suggested again', $svc->ignore([$fid]) === 1 && !in_array($fid, array_map('intval', array_column($svc->pending('cv'), 'id')), true));
$svc->collect(3, $raw);
$check('…even when another CV mentions them', $db->col('SELECT status FROM dictionary_suggestions WHERE id = ?', [$fid]) === 'ignored');

echo "\nOther wordings for a role's must-haves (Claude)\n";
$roleId = $db->insert('roles', ['client_id' => $clientId, 'title' => "Document Controller $tag", 'status' => 'open', 'score_threshold' => 0]);
$db->insert('role_criteria', ['role_id' => $roleId, 'kind' => 'must', 'value' => "Doc Control $tag", 'sort' => 0]);
$reply = ['keywords' => [['keyword' => "Doc Control $tag", 'wordings' => ["EDMS $tag", "Transmittals $tag", "Doc Control $tag", 'AutoCAD']]]];
$stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'm', 'type' => 'message', 'role' => 'assistant',
    'model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
    'usage' => ['input_tokens' => 400, 'output_tokens' => 120]]))]));
$hist = [];
$stack->push(Middleware::history($hist));
$client = new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]);
$got = (new NewTerms($app, $client))->suggestForRole($roleId);
$body = json_decode((string) $hist[0]['request']->getBody(), true);
$check('Claude is asked about the must-haves, with the wordings already known', str_contains($body['messages'][0]['content'] ?? '', "Doc Control $tag"));
$pend = $svc->pending('role', $roleId);
$check("new wordings wait on the role, filed under the must-have; the keyword itself and known words are left out [$got]",
    $got === 2 && array_column($pend, 'label') === ["EDMS $tag", "Transmittals $tag"] && array_unique(array_column($pend, 'canonical')) === ["Doc Control $tag"]);
$svc->accept((int) $pend[0]['id']);
$svc->ignore([(int) $pend[1]['id']]);
$s3 = new Skills($db);
$check('an accepted wording makes the free check find the must-have in a CV that writes it differently',
    $s3->locate("Maintained the EDMS $tag for the project", "Doc Control $tag") !== null && $s3->locate("Handled transmittals $tag", "Doc Control $tag") === null);
