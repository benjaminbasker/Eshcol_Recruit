<?php
/**
 * "These are older CVs" (14 Nov 2026): an old CV for someone already on file must not replace their newer one;
 * it only fills blanks (never experience). A clearly later-dated CV still becomes the latest. Without the option,
 * uploads behave as before. Also the "share your updated CV" evening top-up. Throwaway data. LOCAL ONLY.
 * Run: php tests/older-cv-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvRequests;
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
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$uid = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$keys = ['routing_enabled', 'cvreq_daily_cap', 'cvreq_topup_from', 'cvreq_reserve', 'mail_daily_cap'];
$saved = array_combine($keys, array_map(fn ($k) => $app->setting($k), $keys));
$set = fn (string $k, string $v) => $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
$set('routing_enabled', '0');
$made = [];
$mails = [];
register_shutdown_function(function () use ($app, $db, &$made, &$mails, $saved, $set, &$ok, &$bad) {
    if ($mails) {
        $db->run('DELETE FROM email_queue WHERE id IN (' . implode(',', $mails) . ')');
    }
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    foreach ($saved as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $set($k, (string) $v);
    }
    echo "\n$ok passed, $bad failed\n";
});
$tmp = $app->storage('uploads-tmp/zz-older-' . getmypid() . '.txt');
$email = "ravi.$tag@candidates.test";
$phone = '+91 9' . random_int(100000000, 999999999);
$up = function (string $body, string $file, bool $older) use ($app, $tmp, $uid, $email, $phone, &$made): array {
    file_put_contents($tmp, "Ravi Kumar\n$email | $phone\n$body\nSkilled in Tally ERP, GST returns, bank reconciliation, MS Excel, accounts payable and month-end closing.");
    $imp = new CandidateImporter($app);
    $imp->older = $older;
    $r = $imp->import($tmp, $file, null, $uid, 'upload');
    @unlink($tmp);
    $made[] = (int) $r['candidate_id'];
    return $r;
};
$cvs = fn (int $cid) => array_map('intval', array_column($db->all('SELECT id FROM cv_files WHERE candidate_id = ? ORDER BY id', [$cid]), 'id'));

echo "Someone already on file with a recent CV\n";
$first = $up("Pune\nSenior Accountant, Apr 2022 – Present\n7 years of experience, Tally, GST, SAP.\nB.Com", 'Ravi_2026.txt', false);
$cid = (int) $first['candidate_id'];
$db->run("UPDATE candidates SET cv_dated = '2026-11-01', linkedin_url = NULL WHERE id = ?", [$cid]);
$before = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$filesBefore = $cvs($cid);

$old = $up("Pune\nlinkedin.com/in/ravi-kumar-$tag\nAccountant, Jan 2017 – Mar 2019\nCV dated 12 March 2019\n2 years of experience, Tally.\nB.Com", 'Ravi_old_Indeed.txt', true);
$after = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
$check('an old CV (2019) for them: matched, not a new person', (int) $old['candidate_id'] === $cid);
$check('reported as "already on file with a newer CV"', $old['outcome'] === 'older_copy');
$check('their newer CV stays their latest (the old file is not added)', $cvs($cid) === $filesBefore);
$check('experience and the CV date are untouched', $after['exp_months'] === $before['exp_months'] && $after['cv_dated'] === '2026-11-01');
$check('a blank detail is filled from the old CV (their LinkedIn)', str_contains((string) $after['linkedin_url'], "ravi-kumar-$tag"));

$undated = $up("Pune\nAccountant\nTally", 'Ravi_undated.txt', true);
$check('an undated old CV: also kept out (when in doubt, keep what we have)', $undated['outcome'] === 'older_copy' && $cvs($cid) === $filesBefore);

echo "\nA clearly newer CV still wins\n";
$db->run("UPDATE candidates SET cv_dated = '2018-01-01' WHERE id = ?", [$cid]);
$newer = $up("Pune\nAccountant, Jan 2017 – Mar 2019\nCV dated 12 March 2019\nTally", 'Ravi_2019.txt', true);
$check('dated later than the one we hold: becomes their latest', $newer['outcome'] === 'updated' && count($cvs($cid)) === count($filesBefore) + 1);

echo "\nWithout the option (as before)\n";
$db->run("UPDATE candidates SET cv_dated = '2026-11-01' WHERE id = ?", [$cid]);
$plain = $up("Pune\nAccountant, Jan 2015 – Mar 2016\nTally", 'Ravi_plain.txt', false);
$check('an ordinary upload adds the CV as their latest, as today', $plain['outcome'] === 'updated' && count($cvs($cid)) === count($filesBefore) + 2);
$fresh = (function () use ($app, $tmp, $uid, $tag, &$made) {
    file_put_contents($tmp, "Sunita Newperson\nsunita.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nChennai\nAccountant, 2016 – 2018\nSkilled in Tally ERP, GST returns, bank reconciliation, MS Excel, accounts payable and month-end closing.\nB.Com");
    $imp = new CandidateImporter($app);
    $imp->older = true;
    $r = $imp->import($tmp, 'Sunita.txt', null, $uid, 'upload');
    @unlink($tmp);
    $made[] = (int) $r['candidate_id'];
    return $r;
})();
$check('with the option, someone new is still added as new', $fresh['outcome'] === 'new');

echo "\nEvening top-up for \"share your updated CV\"\n";
$set('cvreq_daily_cap', '175');
$set('cvreq_topup_from', '17');
$set('cvreq_reserve', '25');
$set('mail_daily_cap', '250');
$svc = new CvRequests($app);
$now = strtotime('today 10:00');
$svc->clock = function () use (&$now) { return $now; };
$used = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE (status = 'sent' AND sent_at >= CURDATE()) OR status IN ('pending','held')");
$mine = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE kind = 'cv_request' AND ((status = 'sent' AND sent_at >= CURDATE()) OR status IN ('pending','held'))");
// Today so far: 30 other emails (fake, ours to delete).
for ($i = 0; $i < 30; $i++) {
    $mails[] = $db->insert('email_queue', ['kind' => 'message', 'to_email' => "x$i.$tag@candidates.test", 'subject' => 'x', 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')]);
}
$check('during the day: the 175 allowance', $svc->room() === 175 - $mine);
$now = strtotime('today 17:30');
$check('from 5 pm: what is left of the 250, less 25 spare', $svc->room() === max(175 - $mine, 250 - 25 - ($used + 30)));
$set('cvreq_topup_from', '0');
$check('top-up switched off: back to 175', $svc->room() === 175 - $mine);
