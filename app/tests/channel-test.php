<?php
/**
 * Where candidates came from, and the extra CV details (work history, education, software,
 * languages, serving notice, PIN code, employer type). LOCAL ONLY. Run: php tests/channel-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\AiReading;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvReader;
use Eshcol\Ats\Services\MailIntake;
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
$tag = bin2hex(random_bytes(3));
$uid = (int) $db->col('SELECT id FROM users ORDER BY id LIMIT 1');
$saved = [];
foreach (['routing_enabled' => '0', 'ack_enabled' => '0', 'fit_enabled' => '0'] as $k => $v) {
    $saved[$k] = $app->setting($k);
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$made = [];
$maxIntake = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM mail_intake');
$clientId = $db->insert('clients', ['name' => "ZZ Channel $tag"]);
$tmp = $app->storage("uploads-tmp/zz-ch-$tag.txt");
register_shutdown_function(function () use ($app, $db, &$made, $saved, $clientId, $tmp, $tag, $maxIntake, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
    }
    $db->run('DELETE FROM mail_intake WHERE id > ?', [$maxIntake]);
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($saved as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    @unlink($tmp);
    $left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
    echo $left ? "  FAIL clean-up left $left candidate(s)\n" : "  ok   nothing left behind\n";
    echo "\n" . ($ok + ($left ? 0 : 1)) . ' passed, ' . ($bad + ($left ? 1 : 0)) . " failed\n";
});

echo "Working out where a CV came from\n";
$check('a job board\'s own email', MailIntake::channelOf('noreply@indeed.com', 'New application') === 'indeed' && MailIntake::channelOf('info@naukri.com', 'x') === 'naukri'
    && MailIntake::channelOf('jobs-noreply@linkedin.com', 'x') === 'linkedin' && MailIntake::channelOf('alerts@monsterindia.foundit.in', 'x') === 'foundit');
$check('our website\'s form', MailIntake::channelOf('no-reply@eshcolglobal.com', 'Job application — Accounts Officer (Pune) — Esha') === 'website');
$check('…but not you forwarding a CV from your own address', MailIntake::channelOf('benjamin@eshcolglobal.com', 'Fwd: CV') === 'email');
$check('a board or "referred" named in the subject', MailIntake::channelOf('priya@gmail.com', 'ADV-0002 Accounting Manager - saw it on LinkedIn') === 'linkedin'
    && MailIntake::channelOf('priya@gmail.com', 'Referred by Sunil for ADV-0001') === 'referral' && MailIntake::channelOf('x@gmail.com', 'From WhatsApp group') === 'whatsapp');
$check('a Naukri file name', MailIntake::channelOf('x@gmail.com', 'My CV', 'Naukri_PriyaKulkarni[5y_0m].pdf') === 'naukri' && CandidateImporter::channelFromFilename('Priya_CV.pdf') === null);
$check('otherwise: emailed straight to resume@', MailIntake::channelOf('priya@gmail.com', 'Application for accounts job') === 'email');

echo "\nOn the candidate and the role\n";
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Officer', 'location' => 'Pune', 'status' => 'open', 'score_threshold' => 0]);
$cv = fn (string $name) => "$name\n" . strtolower(explode(' ', $name)[0]) . ".$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccounts officer with 3 years of experience in GST, TDS and Tally.\nB.Com";
$imp = new CandidateImporter($app);
file_put_contents($tmp, $cv('Neha Channel'));
$r = $imp->import($tmp, 'Neha_Channel.txt', $role, $uid, 'upload', 'linkedin');
$made[] = $neha = (int) $r['candidate_id'];
$check('an upload marked "LinkedIn" records it on the candidate and the role', $db->col('SELECT channel FROM candidates WHERE id = ?', [$neha]) === 'linkedin'
    && $db->col('SELECT channel FROM applications WHERE candidate_id = ? AND role_id = ?', [$neha, $role]) === 'linkedin');
file_put_contents($tmp, $cv('Neha Channel') . "\nUpdated");
$role2 = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Manager', 'location' => 'Pune', 'status' => 'open', 'score_threshold' => 0]);
$imp->import($tmp, 'Neha_Channel_new.txt', $role2, $uid, 'upload', 'naukri');
$check('a later CV from Naukri: the candidate keeps the first source, the new role records Naukri', $db->col('SELECT channel FROM candidates WHERE id = ?', [$neha]) === 'linkedin'
    && $db->col('SELECT channel FROM applications WHERE candidate_id = ? AND role_id = ?', [$neha, $role2]) === 'naukri');
file_put_contents($tmp, $cv('Om Naukrifile'));
$r = $imp->import($tmp, 'Naukri_OmNaukrifile[3y_0m].txt', null, $uid);
$made[] = $om = (int) $r['candidate_id'];
$check('not chosen at upload: a Naukri file name is recognised', $db->col('SELECT channel FROM candidates WHERE id = ?', [$om]) === 'naukri');
$role3 = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Payroll Executive', 'location' => 'Pune', 'status' => 'open', 'score_threshold' => 0]);
$imp->attach($om, $role3, $uid, 'database');
$check('picked from the database for a role: that role says "Our database"', $db->col('SELECT channel FROM applications WHERE candidate_id = ? AND role_id = ?', [$om, $role3]) === 'database');
$check('the labels read well', channel_name('walk_in') === 'Walk-in' && channel_name(null) === 'Not recorded' && channel_name('nonsense') === 'Not recorded');

echo "\nCandidates already on file (once, free)\n";
file_put_contents($tmp, $cv('Pia Oldfile'));
$r = $imp->import($tmp, 'Pia_Oldfile.txt', null, $uid);
$made[] = $pia = (int) $r['candidate_id'];
file_put_contents($tmp, $cv('Raj Oldmail'));
$r = $imp->import($tmp, 'Raj.txt', null, $uid);
$made[] = $raj = (int) $r['candidate_id'];
$db->run('UPDATE candidates SET channel = NULL WHERE id IN (?, ?)', [$pia, $raj]);
$db->insert('mail_intake', ['message_key' => hash('sha256', "k-$tag"), 'from_email' => 'noreply@indeed.com', 'subject' => 'New application', 'outcome' => 'imported', 'candidate_ids' => (string) $pia]);
$n = $imp->backfillChannels();
$check("found from the resume@ email that brought the CV ($n filled)", $db->col('SELECT channel FROM candidates WHERE id = ?', [$pia]) === 'indeed');
$check('unknown stays "Not recorded"', $db->col('SELECT channel FROM candidates WHERE id = ?', [$raj]) === null);

echo "\nMore from the CV (work history, education and the rest)\n";
$raw = ['full_name' => 'Raj Oldmail', 'email' => "raj.$tag@candidates.test", 'phone' => '', 'linkedin_url' => '', 'city' => 'Pune', 'locality' => 'Baner',
    'current_title' => 'Accountant', 'current_company' => 'Shah & Co', 'qualifications' => ['M.Com', 'CA Inter'], 'stated_total_experience_years' => '',
    'jobs' => [['title' => 'Accountant', 'company' => 'Shah & Co', 'start' => '2023-04', 'end' => 'present', 'internship' => false],
               ['title' => 'Articled Assistant', 'company' => 'K & Associates', 'start' => '2020-01', 'end' => '2023-01', 'internship' => true]],
    'notice_period_days' => '30', 'current_ctc_lpa' => '', 'expected_ctc_lpa' => '', 'serving_notice' => 'yes', 'last_working_day' => '2026-12-15',
    'education' => [['degree' => 'CA Inter', 'institute' => 'ICAI', 'year' => '2022', 'status' => 'Group 1 cleared'], ['degree' => 'M.Com', 'institute' => 'Pune University', 'year' => '2021', 'status' => 'completed']],
    'software' => ['Tally Prime', 'SAP FICO', 'Tally Prime'], 'languages' => ['english', 'Marathi'], 'pin_code' => '411 045', 'employer_type' => 'CA / audit firm'];
$f = CvReader::toFields($raw);
$check('Claude\'s answer gives software, languages, employer type, PIN, serving notice and last day', ($f['software'] ?? '') === 'Tally Prime, SAP FICO' && ($f['languages'] ?? '') === 'English, Marathi'
    && ($f['employer_type'] ?? '') === 'CA / audit firm' && ($f['pin_code'] ?? '') === '411045' && ($f['serving_notice'] ?? 0) === 1 && ($f['last_working_day'] ?? '') === '2026-12-15');
$check('nonsense is dropped (an unknown employer type, a bad PIN, a bad date)', !isset(CvReader::toFields(['employer_type' => 'Startup', 'pin_code' => '12345', 'last_working_day' => '15/12/2026'])['employer_type'])
    && !isset(CvReader::toFields(['pin_code' => '12345'])['pin_code']) && !isset(CvReader::toFields(['last_working_day' => '2026-02-30'])['last_working_day']));
$check('the reading asks Claude for them', in_array('education', CvReader::schema()['required'], true) && in_array('employer_type', CvReader::schema()['required'], true));
$fileId = (int) $db->col('SELECT id FROM cv_files WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$raj]);
$readId = $db->insert('cv_ai_reads', ['cv_file_id' => $fileId, 'candidate_id' => $raj, 'mode' => 'review', 'status' => 'done', 'model' => 'test',
    'fields' => json_encode($f), 'raw' => json_encode($raw)]);
$ai = new AiReading($app);
$ai->apply($readId, null);
$c = $db->one('SELECT * FROM candidates WHERE id = ?', [$raj]);
$check('applying the reading saves them on the candidate', $c['software'] === 'Tally Prime, SAP FICO' && $c['employer_type'] === 'CA / audit firm' && $c['pin_code'] === '411045'
    && (int) $c['serving_notice'] === 1 && $c['last_working_day'] === '2026-12-15' && $c['languages'] === 'English, Marathi');
$edu = $ai->education($raj);
$check('education for the profile: degree, institute, year, status', $edu && $edu[0]['degree'] === 'CA Inter' && $edu[0]['status'] === 'Group 1 cleared' && $edu[1]['institute'] === 'Pune University');
$hist = $ai->history($raj);
$check('work history for the profile, newest first, articleship marked', $hist && $hist[0]['current'] && str_contains($hist[0]['label'], 'Shah & Co') && $hist[1]['education']);
$old = $raw;
unset($old['employer_type'], $old['software'], $old['education']);
$readOld = $db->insert('cv_ai_reads', ['cv_file_id' => $db->insert('cv_files', ['candidate_id' => $raj, 'sha256' => hash('sha256', "old-$tag"), 'original_name' => 'old.txt', 'ext' => 'txt', 'storage_path' => 'x', 'size_bytes' => 1, 'text_status' => 'ok']),
    'candidate_id' => $raj, 'mode' => 'review', 'status' => 'done', 'model' => 'test', 'fields' => json_encode(CvReader::toFields($old)), 'raw' => json_encode($old)]);
$ai->apply($readOld, null);
$check('a reading from before these were asked for leaves them alone', $db->col('SELECT software FROM candidates WHERE id = ?', [$raj]) === 'Tally Prime, SAP FICO');

echo "\nA shorter qualification line is not offered\n";
$db->run("UPDATE candidates SET qualifications = 'B.Com, CA Inter (Appeared), CA Foundation', field_sources = JSON_SET(COALESCE(field_sources, '{}'), '$.qualifications', 'ai') WHERE id = ?", [$raj]);
$short = $raw;
$short['qualifications'] = ['B.Com', 'CA Foundation'];
$readShort = $db->insert('cv_ai_reads', ['cv_file_id' => $db->insert('cv_files', ['candidate_id' => $raj, 'sha256' => hash('sha256', "short-$tag"), 'original_name' => 's.txt', 'ext' => 'txt', 'storage_path' => 'x', 'size_bytes' => 1, 'text_status' => 'ok']),
    'candidate_id' => $raj, 'mode' => 'review', 'status' => 'done', 'model' => 'test', 'fields' => json_encode(CvReader::toFields($short)), 'raw' => json_encode($short)]);
$check('"CA Inter (Appeared)" is never dropped by a re-read', !isset($ai->proposals($readShort)['qualifications']));
$more = $raw;
$more['qualifications'] = ['B.Com', 'CA Inter (Appeared)', 'CA Foundation', 'M.Com (pursuing)'];
$readMore = $db->insert('cv_ai_reads', ['cv_file_id' => $db->insert('cv_files', ['candidate_id' => $raj, 'sha256' => hash('sha256', "more-$tag"), 'original_name' => 'm.txt', 'ext' => 'txt', 'storage_path' => 'x', 'size_bytes' => 1, 'text_status' => 'ok']),
    'candidate_id' => $raj, 'mode' => 'review', 'status' => 'done', 'model' => 'test', 'fields' => json_encode(CvReader::toFields($more)), 'raw' => json_encode($more)]);
$check('…but one that adds something still is', isset($ai->proposals($readMore)['qualifications']));
exit(0);
