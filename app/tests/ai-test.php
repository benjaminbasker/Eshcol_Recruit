<?php
/**
 * CV report (free) and "Analyse with AI" (Claude), with a fake Claude reply: no API key,
 * no network, no cost. LOCAL ONLY. Usage: php tests/ai-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvAnalyst;
use Eshcol\Ats\Services\CvReport;
use Eshcol\Ats\Services\Retention;
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
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$routingWas = $app->setting('routing_enabled', '1');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$limitWas = $app->setting('ai_monthly_limit');
// Other open roles (local demo data) are paused for the test and restored after.
$paused = array_map('intval', array_column($db->all("SELECT id FROM roles WHERE status = 'open'"), 'id'));
if ($paused) { $db->run("UPDATE roles SET status = 'on_hold' WHERE id IN (" . implode(',', $paused) . ')'); }
$clientId = $db->insert('clients', ['name' => "ZZ AI $tag"]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $limitWas, $paused, &$ok, &$bad) {
    if ($paused) { $db->run("UPDATE roles SET status = 'open' WHERE id IN (" . implode(',', $paused) . ')'); }
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    $limitWas === null ? $db->run("DELETE FROM settings WHERE k = 'ai_monthly_limit'") : $db->run("UPDATE settings SET v = ? WHERE k = 'ai_monthly_limit'", [$limitWas]);
    echo "\n$ok passed, $bad failed\n";
});

$role = function (string $title, float $min, float $max, float $ctc, int $notice, array $must) use ($db, $clientId) {
    $id = $db->insert('roles', ['client_id' => $clientId, 'title' => $title, 'location' => 'Pune', 'status' => 'open', 'exp_min' => $min, 'exp_max' => $max,
        'ctc_max_lpa' => $ctc, 'notice_max_days' => $notice, 'score_threshold' => 50, 'code' => 'ZZA-' . random_int(1000, 9999)]);
    foreach ($must as $i => $m) {
        $db->insert('role_criteria', ['role_id' => $id, 'kind' => 'must', 'value' => $m, 'sort' => $i]);
    }
    return $id;
};
$assoc = $role("Accounting Associate $tag", 2, 3, 4.0, 30, ['GST', 'TDS', 'MIS Reporting', 'Statutory Audit']);
$mgr = $role("Accounting Manager $tag", 5, 10, 9.0, 60, ['GST', 'Team Management']);
$cv = "Sneha Kulkarni\nsneha.$tag@candidates.test | +91 98220 " . random_int(10000, 99999) . " | Hadapsar, Pune\n"
    . "Accounts Executive with 4 years of experience. Notice period: 30 days. Current CTC: 3.6 LPA. Expected CTC: 4.5 LPA.\n"
    . "Accounts Executive | Shah & Associates, Chartered Accountants, Pune\nMar 2023 – Present\n"
    . "- GSTR-1, GSTR-3B and TDS returns for SME clients; monthly MIS reports\n"
    . "Accounts Assistant, Vardhan Traders, Pune        Aug 2020 - Sep 2022\n- Receivables and bank reconciliation\n"
    . "B.Com, Savitribai Phule Pune University   2017 - 2020\nCA Intermediate (Group 1), ICAI  2024 - 2027\n"
    . "Short stints: Data entry, Quick Books Co.  Jan 2019 - Apr 2019\nTyping, Office Mart  May 2019 - Sep 2019\n";
$tmp = $app->storage("uploads-tmp/zz-ai-$tag.txt");
file_put_contents($tmp, $cv);
$r = (new CandidateImporter($app))->import($tmp, 'Sneha_Kulkarni.txt', $assoc, $uid);
@unlink($tmp);
$cid = (int) $r['candidate_id'];
$made[] = $cid;

echo "Free report\n";
$rep = (new CvReport($app))->build($cid);
// Only this test's roles (other open roles may exist locally).
$rep['roles'] = array_values(array_filter($rep['roles'], fn ($x) => in_array($x['id'], [$assoc, $mgr], true)));
$check('a summary paragraph of about ten lines', str_word_count($rep['summary']) >= 80 && str_contains($rep['summary'], 'Sneha Kulkarni'));
$check('roles are ranked best first', $rep['roles'][0]['id'] === $assoc && $rep['roles'][0]['score'] >= $rep['roles'][1]['score']);
$check('…with must-haves met and missing', in_array('GST', $rep['roles'][0]['matched'], true) && in_array('Statutory Audit', $rep['roles'][0]['missing'], true));
$check('…and marks the role they are already on', $rep['roles'][0]['on_role'] && !$rep['roles'][1]['on_role']);
$kinds = array_column($rep['flags'], 'kind');
$check('flags the gap between jobs (Sep 2022 to Mar 2023)', in_array('gap', $kinds, true) && str_contains(implode(' ', array_column($rep['flags'], 'text')), 'Sep 2022 to Mar 2023'));
$check('flags a course still running (possible student)', in_array('student', $kinds, true));
$check('flags short stints', in_array('short', $kinds, true));
$check('flags salary above the budget', in_array('ctc', $kinds, true));
$check('a degree is a course, not a job', (bool) array_filter($rep['history'], fn ($h) => str_contains($h['label'], 'B.Com') && $h['education']));
$check('a job at an institute is still a job, a degree is a course', !CvReport::isCourse('Grants & Finance Officer | Indian Institute of Science Education and Research')
    && CvReport::isCourse('MBA Finance, Symbiosis Institute') && !CvReport::isCourse('Lecturer, Fergusson College'));
$fx = new Eshcol\Ats\Services\FieldExtractor(new Eshcol\Ats\Services\Skills($db));
$nameOf = fn (string $file, string $text = "Accounts executive\nGST and TDS returns.") => $fx->extract($text, $file)['fields']['full_name'] ?? null;
$check('names from Naukri-style file names; a job title is never a name', $nameOf('ResumeBaliramShinde.pdf') === 'Baliram Shinde'
    && $nameOf('Naukri_RahulSharma[5y_0m].pdf') === 'Rahul Sharma' && $nameOf('Priya_Ramanathan_CV.pdf') === 'Priya Ramanathan' && $nameOf('resume.pdf') === null);
$qs = implode(' ', $rep['questions']);
$check('asks about the missing must-have, the gap and the salary', str_contains($qs, 'Statutory Audit') && str_contains($qs, 'Sep 2022') && str_contains($qs, 'flexibility'));

echo "\nAnalyse with AI (fake Claude)\n";
$reply = ['summary' => str_repeat('Sneha has four years in a CA practice. ', 12), 'strengths' => ['CA practice', 'Independent GST filing', 'MIS'],
    'concerns' => ['CTC over budget', 'Gap in 2022–23'], 'questions' => ['Which labour-law filings have you done?', 'Are you attempting CA Group 2?', 'Is 4.5 L firm?'],
    'roles' => [['code' => 'ZZA-1', 'fit' => 'strong', 'reason' => 'Matches the model.']], 'recommendation' => 'Shortlist for Associate.'];
$msg = fn (array $content, string $stop = 'end_turn') => new Response(200, ['Content-Type' => 'application/json'], json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content,
    'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 4200, 'output_tokens' => 900],
]));
$history = [];
$stack = HandlerStack::create(new MockHandler([
    $msg([['type' => 'text', 'text' => json_encode($reply)]]),
    $msg([], 'refusal'),
]));
$stack->push(Middleware::history($history));
$client = new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack]), 'maxRetries' => 0]);
$analyst = new CvAnalyst($app, $client);
$row = $analyst->analyse($cid, $uid);
$req = $history[0]['request'];
$body = json_decode((string) $req->getBody(), true);
$check('asks Claude Sonnet 5.5', ($body['model'] ?? '') === 'claude-sonnet-5-5');
$check('with the refusal fallback switched on', ($body['fallbacks'] ?? '') === 'default' && str_contains($req->getHeaderLine('anthropic-beta'), 'server-side-fallback-2026-07-01'));
$check('for a structured answer at medium effort', ($body['output_config']['effort'] ?? '') === 'medium' && ($body['output_config']['format']['type'] ?? '') === 'json_schema');
$sent = $body['messages'][0]['content'] ?? '';
$check('sends the CV text and the open roles', str_contains($sent, 'Shah & Associates') && str_contains($sent, "Accounting Associate $tag") && str_contains($sent, '<cv>'));
$check('tells Claude never to use age, gender, religion, caste…', str_contains(json_encode($body['system'] ?? ''), 'religion, caste'));
$check('saves the analysis with its cost', $row['report']['recommendation'] === 'Shortlist for Associate.' && abs((float) $row['cost_usd'] - (4200 / 1e6 * 2 + 900 / 1e6 * 10)) < 0.0001);
$check('shown again without paying twice', ($analyst->latest($cid)['report']['summary'] ?? '') === $reply['summary']);
try { $analyst->analyse($cid, $uid); $msgText = 'accepted'; } catch (RuntimeException $e) { $msgText = $e->getMessage(); }
$check('a refusal is explained [' . $msgText . ']', str_contains($msgText, 'declined'));
echo "\nPDF CVs and filling in missing details\n";
$pdfTmp = $app->storage("uploads-tmp/zz-ai-pdf-$tag.pdf");
copy(__DIR__ . '/fixtures/Priya_Ramanathan_CV.pdf', $pdfTmp);
$rp = (new CandidateImporter($app))->import($pdfTmp, "Zz_Pdf_$tag.pdf", $assoc, $uid);
@unlink($pdfTmp);
$pid = (int) $rp['candidate_id'];
$made[] = $pid;
// As if the app had read nothing useful: no name, no email, no position; the phone stays.
$db->run("UPDATE candidates SET full_name = NULL, first_name = NULL, email = NULL, email_norm = NULL, current_title = NULL WHERE id = ?", [$pid]);
$phoneBefore = (string) $db->col('SELECT phone FROM candidates WHERE id = ?', [$pid]);
$taken = "someone.else.$tag@candidates.test";
$other = (new CandidateImporter($app))->import((function () use ($app, $tag, $taken) { $p = $app->storage("uploads-tmp/zz-o-$tag.txt"); file_put_contents($p, "Other Person\n$taken | +91 9" . random_int(100000000, 999999999) . "\nAccounts executive with 3 years of experience in GST and TDS returns, bank reconciliation and MIS reports for clients.\nB.Com"); return $p; })(), 'Other.txt', null, $uid);
$made[] = (int) $other['candidate_id'];
$withCand = fn (array $cand) => $msg([['type' => 'text', 'text' => json_encode($reply + ['candidate' => $cand])]]);
$history2 = [];
$stack2 = HandlerStack::create(new MockHandler([
    $withCand(['full_name' => 'PRIYA RAMANATHAN', 'email' => "priya.r.$tag@candidates.test", 'phone' => '+91 90000 00000', 'current_title' => 'Accounts Executive',
        'current_company' => '', 'location' => 'Chennai', 'total_experience_years' => '4.5']),
]));
$stack2->push(Middleware::history($history2));
$client2 = new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack2]), 'maxRetries' => 0]);
$row2 = (new CvAnalyst($app, $client2))->analyse($pid, $uid);
$sent2 = json_decode((string) $history2[0]['request']->getBody(), true)['messages'][0]['content'] ?? null;
$check('a PDF CV is sent as the file itself, so Claude reads the page', is_array($sent2) && ($sent2[0]['type'] ?? '') === 'document'
    && ($sent2[0]['source']['media_type'] ?? '') === 'application/pdf' && strlen((string) ($sent2[0]['source']['data'] ?? '')) > 1000);
$check('…with the instructions after it, and no extracted text', ($sent2[1]['type'] ?? '') === 'text' && str_contains($sent2[1]['text'], 'CV is attached') && !str_contains($sent2[1]['text'], '<cv>'));
$p = $db->one('SELECT * FROM candidates WHERE id = ?', [$pid]);
$srcs = json_decode((string) $p['field_sources'], true);
$check('missing name, email and position are filled in, marked "from AI"', $p['full_name'] === 'Priya Ramanathan' && $p['email'] === "priya.r.$tag@candidates.test"
    && $p['current_title'] === 'Accounts Executive' && ($srcs['full_name'] ?? '') === 'ai' && ($srcs['email'] ?? '') === 'ai');
$check('…the email can be searched and matched like any other', $p['email_norm'] === "priya.r.$tag@candidates.test");
$check('a detail the app already had is never overwritten', (string) $p['phone'] === $phoneBefore && !in_array('phone', $row2['report']['filled'], true));
$check('the report says what was filled in', in_array('name', $row2['report']['filled'], true) && ($row2['report']['read_from'] ?? '') === 'pdf');
$db->run('UPDATE candidates SET email = NULL, email_norm = NULL WHERE id = ?', [$pid]);
$stack3 = HandlerStack::create(new MockHandler([$withCand(['full_name' => '', 'email' => $taken, 'phone' => '', 'current_title' => '', 'current_company' => '', 'location' => '', 'total_experience_years' => ''])]));
$client3 = new Anthropic\Client(apiKey: 'sk-ant-test-not-a-real-key-000000000000', requestOptions: ['transporter' => new GuzzleHttp\Client(['handler' => $stack3]), 'maxRetries' => 0]);
(new CvAnalyst($app, $client3))->analyse($pid, $uid);
$check('an email that belongs to someone else on file is never copied', !$db->col('SELECT email FROM candidates WHERE id = ?', [$pid]));

$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$cid]);
try { $analyst->analyse($cid, $uid); $msgText = 'accepted'; } catch (RuntimeException $e) { $msgText = $e->getMessage(); }
$check('never sent for someone marked do not contact', str_contains($msgText, 'do not contact') && count($history) === 2);
$db->run('UPDATE candidates SET do_not_contact = 0 WHERE id = ?', [$cid]);
$db->run("INSERT INTO settings (k, v) VALUES ('ai_monthly_limit', '1') ON DUPLICATE KEY UPDATE v = '1'");
try { $analyst->analyse($cid, $uid); $msgText = 'accepted'; } catch (RuntimeException $e) { $msgText = $e->getMessage(); }
$check('the monthly limit stops it', str_contains($msgText, 'limit of 1') && count($history) === 2);
(new Retention($app))->delete($cid, 'manual', false);
$made = array_values(array_diff($made, [$cid])); // the others ("Other Person", the pool one) are still removed at the end
$check('deleting the candidate deletes their AI analysis', !(int) $db->col('SELECT COUNT(*) FROM cv_ai_reports WHERE candidate_id = ?', [$cid]));
exit(0);
