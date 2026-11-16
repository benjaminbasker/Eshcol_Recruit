<?php
/**
 * Which CV goes to clients (audit B13, 15 Nov 2026): the newest unless one is chosen; a shortlist keeps the CV it
 * was made with. Throwaway data, removed afterwards. LOCAL ONLY. Run: php tests/preferred-cv-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "ZZ Preferred $tag"]);
$role = $db->insert('roles', ['client_id' => $client, 'title' => "Accountant ZZ$tag", 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $client, $routingWas, &$ok, &$bad) {
    $db->run('DELETE i FROM shortlist_items i JOIN shortlists s ON s.id = i.shortlist_id JOIN roles r ON r.id = s.role_id WHERE r.client_id = ?', [$client]);
    $db->run('DELETE s FROM shortlists s JOIN roles r ON r.id = s.role_id WHERE r.client_id = ?', [$client]);
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$client]);
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$tmp = $app->storage("uploads-tmp/zz-pref-$tag.txt");
$email = "pref.$tag@candidates.test";
$phone = '+91 9' . random_int(100000000, 999999999);
file_put_contents($tmp, "Priya Zzpref\n$email | $phone\nChennai\nAccountant, 2020 – Present\nTally, GST.\nB.Com (first CV)");
$r1 = $imp->import($tmp, 'first.txt', $role, $owner);
$cid = (int) $r1['candidate_id'];
$made[] = $cid;
file_put_contents($tmp, "Priya Zzpref\n$email | $phone\nChennai\nSenior Accountant, 2020 – Present\nTally, GST, TDS.\nB.Com (second CV)");
$r2 = $imp->import($tmp, 'second.txt', $role, $owner);
@unlink($tmp);
$files = array_map('intval', array_column($db->all('SELECT id FROM cv_files WHERE candidate_id = ? ORDER BY id', [$cid]), 'id'));
$appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $role]);
$sl = new Shortlists($app);

$check('the same person, two CVs', (int) $r2['candidate_id'] === $cid && count($files) === 2);
$check('nothing chosen: the newest CV goes to clients', $sl->cvFor($appId) === $files[1]);
$db->update('candidates', ['preferred_cv_id' => $files[0]], $cid);
$check('the first CV chosen: it goes to clients', $sl->cvFor($appId) === $files[0]);
$sid = $sl->create($role, [[$appId, 'ok']], 'Client', "c.$tag@clients.test", '', '', false, $owner);
$item = $db->one('SELECT * FROM shortlist_items WHERE shortlist_id = ?', [$sid]);
$check('a new shortlist is made with the chosen CV', (int) $item['cv_file_id'] === $files[0]);
$db->update('candidates', ['preferred_cv_id' => null], $cid);
$src = $sl->cvSource((int) $item['id']);
$check('changing the choice later doesn\'t change what that shortlist sends', $src !== null && str_ends_with(str_replace('\\', '/', $src[0]),
    str_replace('\\', '/', (string) $db->col('SELECT storage_path FROM cv_files WHERE id = ?', [$files[0]]))));
$foreign = (int) $db->col('SELECT id FROM cv_files WHERE candidate_id <> ? ORDER BY id LIMIT 1', [$cid]);
if ($foreign) {
    $db->update('candidates', ['preferred_cv_id' => $foreign], $cid);
    $check("someone else's CV is never used: back to the newest", $sl->cvFor($appId) === $files[1]);
    $db->update('candidates', ['preferred_cv_id' => null], $cid);
}
