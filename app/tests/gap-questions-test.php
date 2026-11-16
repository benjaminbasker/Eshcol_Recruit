<?php
/**
 * Asking candidates about gaps in their CV (audit B7, approved 15 Nov 2026), on a throwaway position and candidate:
 *  - the form asks about requirements the assessment found "not mentioned": at most 3, critical first, with the
 *    approved wording; none while the "Share your updated CV" campaign is waiting on them, or when the assessment is
 *    out of date;
 *  - their answers: "Yes" = related (half), "Some exposure" = a quarter, "No" = stays not mentioned; the score moves;
 *    applying again never stacks, and a new assessment keeps them;
 *  - never on the client's shortlist; shown to you next to the requirement; counted on Responses.
 * Cleans up after itself. LOCAL ONLY. Run: php tests/gap-questions-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\FitAssessor as F;
use Eshcol\Ats\Services\GapQuestions as G;
use Eshcol\Ats\Services\Questionnaires;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\Shortlists;

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

echo "Applying an answer (pure)\n";
$reqs = [['text' => 'Maintained books of accounts', 'critical' => true], ['text' => 'Filed GST returns', 'critical' => false],
    ['text' => 'Handled TDS returns', 'critical' => false], ['text' => 'Prepared MIS reports', 'critical' => false]];
$res = ['requirements' => [
    ['number' => 1, 'verdict' => 'not_mentioned', 'evidence' => 'not in the CV', 'reason' => '', 'recency' => 'current'],
    ['number' => 2, 'verdict' => 'proven', 'evidence' => 'Filed GSTR-1 monthly for 30 clients', 'reason' => '', 'recency' => 'current'],
    ['number' => 3, 'verdict' => 'not_mentioned', 'evidence' => 'not in the CV', 'reason' => '', 'recency' => 'current'],
    ['number' => 4, 'verdict' => 'not_mentioned', 'evidence' => 'not in the CV', 'reason' => '', 'recency' => 'current'],
], 'relevant_experience_months' => 30, 'seniority' => 'right', 'qualification_level' => 'base', 'stability' => 'good', 'fit' => 'good'];
$role = ['exp_min' => 2, 'exp_max' => 3, 'exp_allow_below' => 1, 'exp_allow_above' => 3];
$ladder = ['base' => 'B.Com', 'higher' => '', 'top' => ''];
$h = fn (int $i) => G::hash($reqs[$i]['text']);
$ans = [$h(0) => 'recent', $h(2) => 'some', $h(3) => 'no'];
$applied = G::apply($res, $reqs, $ans);
$check('"Yes, current job" on a critical gap: related, half credit, marked "Candidate says"', $applied['requirements'][0]['verdict'] === 'related'
    && $applied['requirements'][0]['candidate_says'] === 'Candidate says: yes, current or recent job' && $applied['requirements'][0]['verdict_cv'] === 'not_mentioned');
$check('"Some exposure": still not mentioned, a quarter of the credit', $applied['requirements'][2]['verdict'] === 'not_mentioned' && $applied['requirements'][2]['gap_credit'] === 0.25);
$check('"No": stays not mentioned, the answer shown', $applied['requirements'][3]['verdict'] === 'not_mentioned' && $applied['requirements'][3]['gap_answer'] === 'no' && !isset($applied['requirements'][3]['gap_credit']));
$check('a proven requirement is never touched', $applied['requirements'][1] === $res['requirements'][1]);
$before = F::score($res, $reqs, $role, $ladder);
$after = F::score($applied, $reqs, $role, $ladder);
// Must-haves out of 40, weights 2+1+1+1 = 5: before 1/5 = 8; after (2×0.5 + 1 + 0.25) / 5 = 0.45 → 18.
$check('must-haves before: 8 of 40 (only GST proven), and the critical gap knocks it to Weak', $before['parts']['must']['points'] == 8.0 && $before['knockout'] !== null);
$check('must-haves after: 18 of 40, and no knockout (the critical one is now "check on the call")', $after['parts']['must']['points'] == 18.0 && $after['knockout'] === null);
$check('applying twice gives the same result (no stacking)', G::apply($applied, $reqs, $ans) === $applied);
$check('applying with no answers restores what the CV showed', G::apply($applied, $reqs, []) == G::apply($res, $reqs, []));
$check('an answer about a reworded requirement is not used', G::apply($res, [['text' => 'Maintained books of accounts in Tally', 'critical' => true]] + $reqs, [$h(0) => 'recent'])['requirements'][0]['verdict'] === 'not_mentioned');

echo "\nThe form\n";
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "Zz Gaps Client $tag"]);
$roleId = $db->insert('roles', ['client_id' => $client, 'title' => "Accounts Associate ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'accepts_routed' => 0,
    'score_threshold' => 0, 'exp_min' => 2, 'exp_max' => 3, 'exp_allow_below' => 1, 'exp_allow_above' => 3]);
$fa = new F($app);
$fa->saveRequirements($roleId, array_merge($reqs, [['text' => 'Reconciled bank statements', 'critical' => false]]));
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $roleId, $routingWas, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE id = ?', [$roleId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$tmp = $app->storage("uploads-tmp/zz-gap-$tag.txt");
file_put_contents($tmp, "Revathi Zzgap\nrevathi.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nChennai\nAccounts Executive, Sharma & Co, 2023 – Present\nFiled GSTR-1 monthly for 30 clients.\nB.Com, 2022");
$r = (new CandidateImporter($app))->import($tmp, 'r.txt', $roleId, $uid);
@unlink($tmp);
$cid = (int) $r['candidate_id'];
$made[] = $cid;
$appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $roleId]);
$roleRow = $db->one('SELECT * FROM roles WHERE id = ?', [$roleId]);
// The assessment: 1 (critical), 3, 4 and 5 not mentioned; 2 proven.
$res5 = $res;
$res5['requirements'][] = ['number' => 5, 'verdict' => 'not_mentioned', 'evidence' => 'not in the CV', 'reason' => '', 'recency' => 'current'];
$db->run("DELETE FROM fit_assessments WHERE application_id = ?", [$appId]);
$db->insert('fit_assessments', ['application_id' => $appId, 'candidate_id' => $cid, 'role_id' => $roleId, 'status' => 'done', 'role_version' => $fa->roleVersion($roleRow),
    'result' => json_encode($res5), 'score' => 30, 'band' => 'Weak', 'breakdown' => '{}', 'model' => F::MODEL]);
$gq = new G($app);
$gaps = $gq->forRole($cid, $roleId);
$check('four gaps, but only 3 asked, the critical one first', count($gaps) === 3 && $gaps[0]['text'] === 'Maintained books of accounts' && $gaps[0]['critical']
    && array_column($gaps, 'text') === ['Maintained books of accounts', 'Handled TDS returns', 'Prepared MIS reports']);
$qsvc = new Questionnaires($app);
$qid = $qsvc->create($appId, $uid);
$db->run("UPDATE questionnaires SET status = 'sent' WHERE id = ?", [$qid]);
$cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$questions = $qsvc->questionsFor([$roleRow], $cand);
$gapQs = array_values(array_filter($questions, fn ($x) => isset($x['gap'])));
$check('the form asks them: "Have you done this in a job?" with the requirement quoted', count($gapQs) === 3 && $gapQs[0]['prompt'] === 'Have you done this in a job?'
    && $gapQs[0]['hint'] === '“Maintained books of accounts”');
$check('…the four approved answers', $gapQs[0]['options'] === ['Yes, in my current or most recent job', 'Yes, in an earlier job', 'Some exposure (I assisted, or in training or an internship)', 'No, not yet']);
$detail = array_values(array_filter($questions, fn ($x) => $x['key'] === 'gap_detail'));
$check('…then the optional "Where did you do this, and for how long?"', $detail && $detail[0]['required'] === false && $detail[0]['prompt'] === 'Where did you do this, and for how long?');
$check('…before "Anything else"', array_search('gap_detail', array_column($questions, 'key'), true) < array_search('notes', array_column($questions, 'key'), true));
$q = $db->one('SELECT * FROM questionnaires WHERE id = ?', [$qid]);
$tok = $qsvc->link($qid);
ob_start();
$errors = [];
$old = [];
$first = 'Revathi';
$token = 'x';
$roles = [$roleRow];
include dirname(__DIR__) . '/views/questionnaire/form.php';
$html = (string) ob_get_clean();
$check('the page shows the heading and the approved intro', str_contains($html, 'A few questions about your experience') && str_contains($html, 'Your CV may not mention everything you have done. Please tell us about these.'));
$check('…and never says Claude or AI', !preg_match('/\bClaude\b|\bAI\b/', $html));

$db->insert('cv_requests', ['candidate_id' => $cid, 'grp' => 1, 'token_hash' => hash('sha256', "zz$tag"), 'token_enc' => 'x', 'status' => 'sent']);
$check('none while the "Share your updated CV" campaign is waiting on them', $gq->forRole($cid, $roleId) === []);
$db->run('DELETE FROM cv_requests WHERE candidate_id = ?', [$cid]);

echo "\nTheir answers\n";
$post = ['keep' => '1'];
foreach ($questions as $x) {
    $post[$x['key']] = match (true) {
        isset($x['gap']) => ['Maintained books of accounts' => 'Yes, in my current or most recent job', 'Handled TDS returns' => 'Some exposure (I assisted, or in training or an internship)', 'Prepared MIS reports' => 'No, not yet'][$x['gap']['text']],
        $x['key'] === 'gap_detail' => 'Books for 30 clients at Sharma & Co since 2023',
        in_array($x['kind'], ['number'], true) => '3',
        $x['kind'] === 'years' => '3',
        $x['kind'] === 'select' => $x['options'][1],
        in_array($x['kind'], ['choice', 'yesno'], true) => $x['options'][0],
        $x['kind'] === 'email' => $x['prefill'] ?? '',
        $x['kind'] === 'tel' => $x['prefill'] ?? '',
        $x['kind'] === 'url' => '',
        default => $x['prefill'] ?? ($x['required'] ? 'Anna Nagar' : ''),
    };
}
$errs = $qsvc->submit($q, $post);
$check('the form is accepted', $errs === []);
$rows = $db->all('SELECT requirement, answer, detail FROM gap_answers WHERE application_id = ? ORDER BY id', [$appId]);
$check('three answers kept, with where and how long', count($rows) === 3 && array_column($rows, 'answer') === ['recent', 'some', 'no'] && $rows[0]['detail'] === 'Books for 30 clients at Sharma & Co since 2023');
$fit = $fa->forApplication($appId);
$check('the assessment now shows "Candidate says" and the score went up (free, straight away)', $fit['result']['requirements'][0]['verdict'] === 'related'
    && $fit['result']['requirements'][0]['candidate_says'] === 'Candidate says: yes, current or recent job' && (int) $fit['score'] > 30);
$check('the critical gap no longer knocks them to Weak on its own', ($fit['breakdown']['knockout'] ?? null) === null);
$check('the form doesn\'t ask the same gaps again; only the one left', array_column($gq->forRole($cid, $roleId), 'text') === ['Reconciled bank statements']);
$check('"No" on a requirement that isn\'t critical is not flagged', (int) $db->col("SELECT flagged FROM questionnaire_answers WHERE questionnaire_id = ? AND prompt LIKE '%Prepared MIS reports%'", [$qid]) === 0
    && (string) $db->col("SELECT answer FROM questionnaire_answers WHERE questionnaire_id = ? AND prompt LIKE '%Prepared MIS reports%'", [$qid]) === 'No, not yet');
$check('"No" on a critical one would be flagged', true === in_array('No, not yet', $gapQs[0]['flag'], true) && $gapQs[1]['flag'] === []);
$prof = (new Shortlists($app))->profile($appId, false);
$check('never on the client\'s shortlist', !str_contains(json_encode($prof, JSON_UNESCAPED_UNICODE), 'Have you done this') && !str_contains(json_encode($prof, JSON_UNESCAPED_UNICODE), 'Sharma & Co since'));
$reqsNow = $fit['requirements'];
$fd = $fit;
$f = $fd;
$reqs = $reqsNow;
ob_start();
include dirname(__DIR__) . '/views/partials/fit_detail.php';
$view = (string) ob_get_clean();
$check('you see it next to the requirement', str_contains($view, 'Candidate says: yes, current or recent job') && str_contains($view, 'Candidate says: no, not yet'));
$check('Responses counts them', ($s = $gq->summary())['yes'] >= 1 && $s['some'] >= 1 && $s['no'] >= 1 && $s['people'] >= 1);

echo "\nA new assessment keeps their answers\n";
$db->update('fit_assessments', ['result' => json_encode($res5)], (int) $fit['id']);
$again = $gq->rescore($appId);
$fit2 = $fa->forApplication($appId);
$check('re-applied to a fresh answer from the CV: same verdicts, same score', $fit2['result']['requirements'][0]['verdict'] === 'related' && (int) $fit2['score'] === (int) $fit['score']);
$fa->saveRequirements($roleId, [['text' => 'Maintained books of accounts in Tally', 'critical' => true]]);
$check('the position\'s requirements changed: the old assessment is out of date, so no questions until it is redone', $gq->forRole($cid, $roleId) === []);
