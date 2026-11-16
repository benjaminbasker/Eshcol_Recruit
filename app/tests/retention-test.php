<?php
/**
 * Retention and deletion, end to end, on a throwaway fictional candidate:
 * due list, keep, delete with "asked to be removed", files really gone, nothing
 * personal left behind, and a re-application flagged. Cleans up after itself.
 * LOCAL ONLY. Run: php tests/retention-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
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

$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
// ".test" is a reserved domain that can never be real; the app rightly ignores example.com placeholders.
$email = 'zz.retention.' . bin2hex(random_bytes(3)) . '@candidates.test';
$cv = "Zara Testwoman\n$email | +91 90000 12345\nChennai\nAccounts executive with 3 years of experience in GST and Tally.\nB.Com";
$tmp = $app->storage('uploads-tmp/zz-' . getmypid() . '.txt');
file_put_contents($tmp, $cv);

// Routing off for this test, so the CV isn't sorted onto someone else's open role.
$routingWas = $app->setting('routing_enabled', '1');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$imp = new CandidateImporter($app);
$ret = new Retention($app);
$r = $imp->import($tmp, 'Zara_Testwoman.txt', null, $uid);
$cid = (int) $r['candidate_id'];
$file = $db->one('SELECT storage_path FROM cv_files WHERE candidate_id = ?', [$cid]);
$path = $app->storage($file['storage_path']);
$check('throwaway candidate imported', $r['outcome'] === 'new' && $cid > 0);
$check('stored CV file exists', is_file($path));
$check('keeping date set to ' . $ret->months() . ' months from now', $db->col('SELECT purge_after FROM candidates WHERE id = ?', [$cid]) === date('Y-m-d', strtotime('+' . $ret->months() . ' months')));

echo "\nDue list\n";
$db->run('UPDATE candidates SET purge_after = CURDATE() - INTERVAL 1 DAY WHERE id = ?', [$cid]);
$check('appears on the due list once their time is up', in_array($cid, array_column($ret->due(), 'id'), true));

// A throwaway open role, so the test doesn't depend on what else is in the database.
$clientId = $db->insert('clients', ['name' => 'ZZ Retention Test']);
$roleId = $db->insert('roles', ['client_id' => $clientId, 'title' => 'ZZ Retention Test Role', 'status' => 'open']);
$imp->attach($cid, $roleId, $uid, 'database');
$check('NOT due while in an active stage on an open role', !in_array($cid, array_column($ret->due(), 'id'), true));
$db->run('DELETE FROM applications WHERE candidate_id = ?', [$cid]);
$db->run('UPDATE candidates SET purge_after = CURDATE() - INTERVAL 1 DAY WHERE id = ?', [$cid]);

$ret->extend($cid, 'They asked to stay on file');
$check('"Keep" takes them off the due list', !in_array($cid, array_column($ret->due(), 'id'), true));
$check('and records the reason', $db->col('SELECT retention_note FROM candidates WHERE id = ?', [$cid]) === 'They asked to stay on file');

echo "\nDeletion after \"Please remove me\"\n";
// Audit R4: as if their CV had been copied off-site and the off-site store can't be reached right now.
$f = $db->one('SELECT sha256, ext FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
$db->run('UPDATE cv_files SET backed_up_at = NOW() WHERE candidate_id = ?', [$cid]);
$key = \Eshcol\Ats\Services\Backup::cvKey($f['sha256'], $f['ext']);
$noRemoteHere = \Eshcol\Ats\Services\Backup::remote($app) === null;
$ret->delete($cid, 'requested', true);
if ($noRemoteHere) {
    $check('audit R4: the off-site copy is written down to delete (no personal data, just the file key)', (bool) $db->col('SELECT 1 FROM remote_deletions WHERE remote_key = ?', [$key]));
    $down = new class { public function delete(string $k): void { throw new RuntimeException('R2 unreachable'); } };
    $bk = new \Eshcol\Ats\Services\Backup($app);
    $check('...still listed while the store is down, with the reason', $bk->retryDeletions([$key], $down) === 0
        && $db->one('SELECT tries, last_error FROM remote_deletions WHERE remote_key = ?', [$key]) === ['tries' => 1, 'last_error' => 'R2 unreachable']);
    $up = new class { public array $gone = []; public function delete(string $k): void { $this->gone[] = $k; } };
    $bk->retryDeletions([$key], $up);
    $check('...and crossed off once the store confirms the delete', $up->gone === [$key] && !$db->col('SELECT 1 FROM remote_deletions WHERE remote_key = ?', [$key]));
}
$check('candidate row is gone', !$db->col('SELECT id FROM candidates WHERE id = ?', [$cid]));
$check('their CV file is gone from storage', !is_file($path));
$check('their CV text is gone from the database', !$db->col('SELECT COUNT(*) FROM cv_files WHERE candidate_id = ?', [$cid]));
$check('no skills or applications left behind', !$db->col('SELECT COUNT(*) FROM candidate_skills WHERE candidate_id = ?', [$cid]) && !$db->col('SELECT COUNT(*) FROM applications WHERE candidate_id = ?', [$cid]));
$auditDetails = implode(' ', $db->pdo()->query("SELECT COALESCE(detail,'') FROM audit_log WHERE entity_type = 'candidate' AND entity_id = $cid")->fetchAll(PDO::FETCH_COLUMN));
$check('activity log holds no name, email or file name for them', !str_contains($auditDetails, 'Zara') && !str_contains($auditDetails, $email));
$supp = (int) $db->col('SELECT COUNT(*) FROM suppression');
$check('a "do not contact" code was kept (2: email and phone)', $supp >= 2);
$check('the code is not their email in readable form', !$db->col('SELECT COUNT(*) FROM suppression WHERE hash LIKE ?', ['%candidates%']));

echo "\nThey apply again\n";
file_put_contents($tmp, $cv . "\nUpdated CV.");
$again = $imp->import($tmp, 'Zara_Testwoman_v2.txt', null, $uid);
$check('import succeeds (applying again is their choice)', $again['outcome'] === 'new');
$check('you are told they once asked to be removed', str_contains($again['message'], 'once asked to be removed'));
$check('and the candidate record carries the note', str_contains((string) $db->col('SELECT keep_note FROM candidates WHERE id = ?', [$again['candidate_id']]), 'Previously asked to be removed'));

// Clean up: remove the second copy and this test's do-not-contact codes.
$ret->delete((int) $again['candidate_id'], 'manual', false);
$db->run("DELETE FROM suppression WHERE created_at >= NOW() - INTERVAL 5 MINUTE");
$db->run('DELETE FROM roles WHERE id = ?', [$roleId]);
$db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
$db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
@unlink($tmp);
echo "\nCleaned up.\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
