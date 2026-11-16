<?php
/**
 * WhatsApp messages, database matching and the Monday summary, on throwaway data.
 * Cleans up after itself, even if a check crashes. LOCAL ONLY. Run: php tests/extras-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Digest;
use Eshcol\Ats\Services\Matcher;
use Eshcol\Ats\Services\Questionnaires;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\WhatsApp;

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
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$tag = bin2hex(random_bytes(3));
$routingWas = $app->setting('routing_enabled', '1');
$db->run("UPDATE settings SET v = '0' WHERE k = 'routing_enabled'");
$made = [];
$clientId = $db->insert('clients', ['name' => "ZZ Extras $tag"]);
$tmp = $app->storage("uploads-tmp/zz-x-$tag.txt");
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $tmp, $tag, $maxEmail, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
    }
    $db->run('DELETE FROM email_queue WHERE id > ? AND candidate_id IS NULL', [$maxEmail]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    @unlink($tmp);
    $left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
    echo $left ? "  FAIL clean-up left $left candidate(s)\n" : "  ok   nothing left behind\n";
    echo "\n" . ($ok + ($left ? 0 : 1)) . ' passed, ' . ($bad + ($left ? 1 : 0)) . " failed\n";
});

$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounts Officer $tag", 'location' => 'Pune', 'status' => 'open', 'code' => "ZZX-$tag", 'score_threshold' => 40]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'Tally']);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'GST']);
$imp = new CandidateImporter($app);
$person = function (string $name, ?int $roleId, string $phone = '') use ($imp, $tmp, $uid, $tag, &$made): int {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | " . ($phone ?: '+91 9' . random_int(100000000, 999999999)) . "\nPune\nAccounts officer, 3 years of experience in Tally and GST returns.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    $made[] = (int) $r['candidate_id'];
    return (int) $r['candidate_id'];
};

echo "WhatsApp\n";
$check('Indian mobile → 91 + number', WhatsApp::number(['phone_norm' => '9876543210', 'phone' => '98765 43210']) === '919876543210');
$check('Gulf number written with +971 works', WhatsApp::number(['phone_norm' => null, 'phone' => '+971 50 123 4567']) === '971501234567');
$check('a landline or unclear number is not guessed', WhatsApp::number(['phone_norm' => null, 'phone' => '044 2345 6789']) === null);
$w = $person('Wasim Whatsapp', $role);
$wa = $db->one('SELECT * FROM applications WHERE candidate_id = ? AND role_id = ?', [$w, $role]);
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$w]);
$msgs = (new WhatsApp($app))->messages($c, $wa, 'Benjamin Basker');
$check('messages greet them and name the role and place', str_starts_with($msgs['call']['text'], 'Hello Wasim, this is Benjamin from Eshcol Global') && str_contains($msgs['call']['text'], "the Accounts Officer $tag role in Pune"));
$check('no "answer the questions" message until a questionnaire is open', !isset($msgs['questions']));
$qs = new Questionnaires($app);
$qid = $qs->create((int) $wa['id'], $uid);
$msgs = (new WhatsApp($app))->messages($c, $wa, 'Benjamin Basker');
$check('…then it carries their personal link and the "critical" sentence', isset($msgs['questions']) && str_contains($msgs['questions']['text'], $qs->link($qid)) && str_contains($msgs['questions']['text'], 'critical for us to take a decision'));
$check('a recruiter\'s own first name is used', str_contains((new WhatsApp($app))->messages($c, $wa, 'Revathi S')['hello']['text'], 'this is Revathi from Eshcol Global'));

echo "\nAlready in your database\n";
$fit = $person('Fatima Fitting', null);
$dnc = $person('Dinesh Donotcontact', null);
$gone = $person('Gita Goingaway', null);
$db->run('UPDATE candidates SET do_not_contact = 1 WHERE id = ?', [$dnc]);
$db->run('UPDATE candidates SET removal_requested_at = NOW() WHERE id = ?', [$gone]);
$ids = array_map(fn ($m) => (int) $m['cand']['id'], (new Matcher($app))->forRole($db->one('SELECT * FROM roles WHERE id = ?', [$role]), 50));
$check('a fitting candidate not on the role is found', in_array($fit, $ids, true));
$check('people already on the role are not suggested', !in_array($w, $ids, true));
$check('do-not-contact and "please remove me" are never suggested', !in_array($dnc, $ids, true) && !in_array($gone, $ids, true));

echo "\nMonday summary\n";
[$subject, $html, $text] = (new Digest($app))->build();
$check('subject: "your week"', str_starts_with($subject, 'Eshcol Recruit: your week'));
$check('lists the open role with its numbers', str_contains($html, "Accounts Officer $tag") && str_contains($text, "Accounts Officer $tag"));
$check('shows the best database match for it', str_contains($html, 'Already in your database') && str_contains($html, 'Fatima Fitting'));
$check('"waiting for you" mentions the removal request', str_contains($html, 'asked us not to keep their details'));
$check('links point into the app', str_contains($html, "/roles/$role") && str_contains($text, "/candidates/$fit"));
$check('nothing unescaped from candidate data', !preg_match('/<script/i', $html));
$n = (new Digest($app))->send();
$check('queued for every owner', $n >= 1 && (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'digest' AND id > ?", [$maxEmail]) === $n);
exit(0);
