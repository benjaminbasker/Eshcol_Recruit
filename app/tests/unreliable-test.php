<?php
/**
 * Candidates marked "Unreliable": a no-show at interview marks them; they are left out of
 * database search, shortlists, screening and interview invitations; the mark can be cleared;
 * after deletion a new CV from the same email or phone is marked again.
 * Throwaway data; nothing is emailed. LOCAL ONLY. Run: php tests/unreliable-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Interviews;
use Eshcol\Ats\Services\Matcher;
use Eshcol\Ats\Services\Reliability;
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
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$tag = bin2hex(random_bytes(3));
$routingWas = $app->setting('routing_enabled', '1');
$db->run("UPDATE settings SET v = '0' WHERE k = 'routing_enabled'");
$made = [];
$hashes = [];
$clientId = $db->insert('clients', ['name' => "ZZ Unreliable $tag", 'interview_address' => 'Balewadi, Pune']);
$tmp = $app->storage("uploads-tmp/zz-un-$tag.txt");
$maxEmail = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM email_queue');
$maxSupp = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM suppression');
register_shutdown_function(function () use ($app, $db, &$made, $clientId, $routingWas, $tmp, $maxEmail, $maxSupp, &$ok, &$bad) {
    foreach (array_unique($made) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM suppression WHERE id > ?', [$maxSupp]);
    $db->run('DELETE FROM email_queue WHERE id > ?', [$maxEmail]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    $db->run('UPDATE settings SET v = ? WHERE k = \'routing_enabled\'', [$routingWas]);
    @unlink($tmp);
    echo "\n$ok passed, $bad failed\n";
});
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => "Accounts Associate $tag", 'location' => 'Pune', 'status' => 'open', 'code' => 'ZZU-' . random_int(1000, 9999), 'score_threshold' => 0]);
$db->insert('role_criteria', ['role_id' => $role, 'kind' => 'must', 'value' => 'GST']);
$imp = new CandidateImporter($app);
$phone = '98' . random_int(10000000, 99999999);
$person = function (string $name, ?int $roleId, ?string $ph = null) use ($imp, $tmp, $uid, $tag, &$made, &$phone): array {
    $email = strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test";
    file_put_contents($tmp, "$name\n$email | +91 " . ($ph ?? '97' . random_int(10000000, 99999999)) . "\nPune\nAccounts executive, 3 years of experience in Tally and GST.\nB.Com");
    $r = $imp->import($tmp, str_replace(' ', '_', $name) . '.txt', $roleId, $uid);
    $made[] = (int) $r['candidate_id'];
    return $r;
};

echo "A no-show at interview\n";
$r = $person('Rahul Nikam', $role, $phone);
$cid = (int) $r['candidate_id'];
$app_ = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $role]);
$svc = new Interviews($app);
[$booked, , $iv] = $svc->book($app_, 1, 'in_person', date('Y-m-d\T10:00', strtotime('+2 days')), 30, [], $uid);
$check('an interview is booked', (bool) $booked);
$svc->outcome((int) $iv, 'no_show', '', $uid);
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('"Did not turn up" marks them unreliable, saying which interview', $c['unreliable_reason'] === 'no_show' && str_contains((string) $c['unreliable_note'], 'Round 1'));
$check('…the interview shows as a no-show', $db->col('SELECT status FROM interviews WHERE id = ?', [$iv]) === 'no_show');
$check('…and they move to Withdrew', (string) $db->col('SELECT s.name FROM applications a JOIN stages s ON s.id = a.stage_id WHERE a.id = ?', [$app_]) === 'Withdrew');
[$inv, $msg] = $svc->invite($app_, 2, $uid);
$check('they cannot be invited to another interview', !$inv && str_contains($msg, 'unreliable'));

echo "\nLeft out where we reach out\n";
$r2 = $person('Sneha Joshi', null);
$cid2 = (int) $r2['candidate_id'];
$found = fn () => array_map(fn ($m) => (int) $m['cand']['id'], (new Matcher($app))->forRole($db->one('SELECT * FROM roles WHERE id = ?', [$role]), 200));
$check('before: the database search finds Sneha', in_array($cid2, $found(), true));
(new Reliability($app))->mark($cid2, 'no_response', 'no reply to 3 calls and 2 emails', $uid);
$check('after marking "Stopped responding": the search leaves her out', !in_array($cid2, $found(), true));
$check('the description reads well', str_starts_with(Reliability::describe($db->one('SELECT * FROM candidates WHERE id = ?', [$cid2])), 'Stopped responding: no reply to 3 calls'));
(new Reliability($app))->clear($cid2, $uid);
$check('clearing the mark brings her back', in_array($cid2, $found(), true) && $db->col('SELECT unreliable_at FROM candidates WHERE id = ?', [$cid2]) === null);

// 24 Nov 2026 (live error f0337112): "Stopped responding" with the note left empty gave "Something went wrong".
// The page sends an empty note as nothing at all; marking must accept that.
(new Reliability($app))->mark($cid2, 'no_response', null, $uid);
$c5 = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid2]);
$check('marking with the note left empty works (24 Nov live error)', $c5['unreliable_reason'] === 'no_response' && $c5['unreliable_note'] === null && $c5['unreliable_at'] !== null);
$check('…and reads "Stopped responding · date"', str_starts_with(Reliability::describe($c5), 'Stopped responding · '));
(new Reliability($app))->clear($cid2, $uid);

echo "\nThey apply again\n";
$r3 = $person('Rahul Nikam', $role, $phone);
$check('a new CV from them is saved, with a note that they are marked', (int) $r3['candidate_id'] === $cid && str_contains($r3['message'], 'unreliable'));
(new Retention($app))->delete($cid, 'expired');
$r4 = $person('Rahul Nikam', $role, $phone);
$c4 = $db->one('SELECT * FROM candidates WHERE id = ?', [(int) $r4['candidate_id']]);
$check('after their record is deleted, a later CV from the same email or phone is marked again', $c4['unreliable_reason'] === 'no_show' && str_contains((string) $c4['unreliable_note'], 'marked before'));
$check('…and is not mistaken for someone who asked to be removed', empty($c4['keep_note']));
exit(0);
