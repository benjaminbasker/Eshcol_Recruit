<?php
/**
 * Names (14 Nov 2026): CV headings taken for names ("Good Communication"), caught by comparing with the file
 * name; Claude Haiku may now fix those (never a confirmed name, never email or phone); the "Names to check"
 * list changes nothing until approved; the campaign email never greets a doubtful name.
 * Throwaway data. LOCAL ONLY. Run: php tests/name-check-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvRequests;
use Eshcol\Ats\Services\NameCheck as N;
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
$was = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$made = [];
register_shutdown_function(function () use ($app, $db, &$made, $was, &$ok, &$bad) {
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $was === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$was]);
    echo "\n$ok passed, $bad failed\n";
});

echo "The rule, on real examples from the 900 old CVs\n";
$check('headings, titles and addresses are doubtful', N::doubtful('Good Communication', 'PUSHPAK_DAHE_2026-11-12.pdf')
    && N::doubtful('Employment History', 'Niraj_Joshi_2026-11-12.pdf') && N::doubtful('Vallabhnagar Pimpri Pune', 'Anjali_Shirke_2026-11-12.pdf')
    && N::doubtful('Account Coordinator', 'Junaid_Attar_2026-11-12.pdf') && N::doubtful('Ccuurrrriiccuulluumm Vviittaaee', 'Prasad_Survase_2026-11-12.pdf'));
$check('even when the file name says nothing ("Resume.pdf"), a heading is doubtful', N::doubtful('Personal Details', 'Resume.pdf') && N::doubtful('', 'Resume.pdf'));
$check('real names are fine, including spelling variants and initials', !N::doubtful('Pushpak Dahe', 'PUSHPAK_DAHE_2026-11-12.pdf')
    && !N::doubtful('S.sathiya Balan', 'S__Sathiyabalan_2026-11-12.pdf') && !N::doubtful('S. Sundaramurthy', 'S__Sundaramoorthi_2026-11-12.pdf')
    && !N::doubtful('Dineshyan P', 'DINESH_YAN_P_2026-11-12.pdf') && !N::doubtful('Abhishek G. Dhoble', 'Curriculum_G_2026-11-12.pdf')
    && !N::doubtful('Meena Iyer', 'Resume.pdf') && !N::doubtful('Shubhangi Devram Tarote (Bhonde)', 'Shubhangi_Tarote_2026-11-12.pdf'));

$imp = new CandidateImporter($app);
$tmp = $app->storage('uploads-tmp/zz-names-' . getmypid() . '.txt');
$make = function (string $file, string $wrong, string $claude, string $source = 'cv') use ($db, $imp, $tmp, $uid, $tag, &$made): array {
    file_put_contents($tmp, "Curriculum Vitae\nperson.$tag." . random_int(1000, 9999) . "@candidates.test | +91 9" . random_int(100000000, 999999999)
        . "\nPune\nAccountant, 2021 – Present\nSkilled in Tally ERP, GST returns, bank reconciliation, MS Excel and month-end closing.\nB.Com");
    $r = $imp->import($tmp, $file, null, $uid, 'upload');
    @unlink($tmp);
    $cid = (int) $r['candidate_id'];
    $made[] = $cid;
    $db->run('UPDATE candidates SET full_name = ?, first_name = ?, field_sources = ? WHERE id = ?', [$wrong, explode(' ', $wrong)[0], json_encode(['full_name' => $source]), $cid]);
    $db->run('DELETE FROM cv_ai_reads WHERE candidate_id = ?', [$cid]);
    $read = $db->insert('cv_ai_reads', ['cv_file_id' => (int) $r['cv_file_id'], 'candidate_id' => $cid, 'mode' => 'auto', 'status' => 'done',
        'model' => 'claude-haiku-5-5', 'fields' => json_encode(['full_name' => $claude])]);
    return [$cid, $read];
};
$ai = new AiReading($app);
$name = fn (int $cid) => (string) $db->col('SELECT full_name FROM candidates WHERE id = ?', [$cid]);

echo "\nThe \"Names to check\" list\n";
[$pushpak] = $make("PUSHPAK_DAHE{$tag}_2026-11-12.pdf.txt", 'Good Communication', "Pushpak Dahe$tag");
[$confirmed] = $make("NIRAJ_JOSHI{$tag}_2026-11-12.txt", 'Employment History', "Niraj Joshi$tag", 'you');
[$noBetter] = $make("ANJALI_SHIRKE{$tag}_2026-11-12.txt", 'Vallabhnagar Pimpri Pune', 'Pimpri Pune');
$list = $ai->nameChecks();
$fixIds = array_column($list['fixes'], 'candidate_id');
$unsureIds = array_column($list['unsure'], 'candidate_id');
$check('"Good Communication" is listed with Claude\'s "Pushpak Dahe"', in_array($pushpak, $fixIds, true)
    && $list['fixes'][array_search($pushpak, $fixIds, true)]['suggested'] === "Pushpak Dahe$tag");
$check('a name you confirmed is never listed, however odd', !in_array($confirmed, $fixIds, true) && !in_array($confirmed, $unsureIds, true));
$check('doubtful with no believable suggestion: listed separately, to correct by hand', in_array($noBetter, $unsureIds, true) && !in_array($noBetter, $fixIds, true));
$check('nothing has changed yet', $name($pushpak) === 'Good Communication');
$check('approving corrects it (and the first name)', $ai->applyNames([$pushpak], $uid) === 1 && $name($pushpak) === "Pushpak Dahe$tag"
    && $db->col('SELECT first_name FROM candidates WHERE id = ?', [$pushpak]) === 'Pushpak');
$check('an unticked one is left alone', $ai->applyNames([], $uid) === 0 && $name($noBetter) === 'Vallabhnagar Pimpri Pune');

echo "\nFrom now on, Claude Haiku fixes these itself\n";
[$suvhomay, $read] = $make("Suvhomay_Panja{$tag}_2026-11-12.txt", 'Financial Statement Analysis', "Suvhomay Panja$tag");
$emailBefore = $db->col('SELECT email FROM candidates WHERE id = ?', [$suvhomay]);
$db->run('UPDATE cv_ai_reads SET fields = ? WHERE id = ?', [json_encode(['full_name' => "Suvhomay Panja$tag", 'email' => "other.$tag@elsewhere.test"]), $read]);
$ai->apply($read, null);
$check('a doubtful name from the free reader is replaced by Haiku\'s', $name($suvhomay) === "Suvhomay Panja$tag");
$check('Haiku still never replaces the email', $db->col('SELECT email FROM candidates WHERE id = ?', [$suvhomay]) === $emailBefore);
[$fine, $read2] = $make("Rahul_Kumar{$tag}_2026-11-12.txt", "Rahul Kumar$tag", 'Rahul K Sharma');
$ai->apply($read2, null);
$check('a believable name is never replaced by Haiku', $name($fine) === "Rahul Kumar$tag");

echo "\nThe campaign email\n";
$svc = new CvRequests($app);
$r = ['grp' => 2, 'token_enc' => \Eshcol\Ats\Secrets::seal($app, str_repeat('a', 32), 'cv_request')];
[, $html] = $svc->render($db->one('SELECT * FROM candidates WHERE id = ?', [$noBetter]), $r, 'first');
$check('a doubtful name is not used: "Thank you." (not "Thank you, Vallabhnagar.")', str_contains($html, 'Thank you.') && !str_contains($html, 'Vallabhnagar'));
[, $html2] = $svc->render($db->one('SELECT * FROM candidates WHERE id = ?', [$pushpak]), $r, 'first');
$check('a corrected name is used', str_contains($html2, 'Thank you, Pushpak.'));
