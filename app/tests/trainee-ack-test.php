<?php
/**
 * 22 Nov 2026: the Trainee Technicians thank-you email (lilac, Gen Z, LinkedIn side quest) goes only to
 * Trainee Technicians applicants, keeps the public wording rules, and every other email stays the house one.
 * Run: php tests/trainee-ack-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\TraineeTechAck;

$app = App::boot(cli: true);
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$styleWas = $app->setting('ack_trainee_style');
$client = $db->insert('clients', ['name' => "Zz Hydraulics Client $tag"]);
$mk = fn (string $title, string $eng = 'staffing') => $db->insert('roles', ['client_id' => $client, 'engagement' => $eng, 'title' => $title,
    'location' => 'Tiruvallur', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]);
$fresh = $mk("Trainee Technicians (Freshers) ZZ$tag");
$exp = $mk("Trainee Technicians (Experienced) ZZ$tag");
$other = $mk("Machine Operator ZZ$tag");
register_shutdown_function(function () use ($db, $client, $fresh, $exp, $other, $styleWas, &$ok, &$bad) {
    $db->run("DELETE FROM roles WHERE id IN ($fresh, $exp, $other)");
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $styleWas === null ? $db->run("DELETE FROM settings WHERE k = 'ack_trainee_style'") : $db->run("UPDATE settings SET v = ? WHERE k = 'ack_trainee_style'", [$styleWas]);
    echo "\n$ok passed, $bad failed\n";
    exit($bad ? 1 : 0);
});
$ack = new Acknowledger($app);
$cand = ['full_name' => 'PRIYA RAMESH', 'first_name' => '', 'email' => 'priya@candidates.test', 'applied_for' => ''];

echo "Which email\n";
[$s1, $h1, $t1] = $ack->render($cand, [$fresh]);
$check('a Trainee Technicians (Freshers) applicant gets the trainee email', str_contains($h1, 'SIDE QUEST') && str_contains($h1, '#EEEBFB'));
[, $h2] = $ack->render($cand, [$fresh, $exp]);
$check('both trainee positions together: still the trainee email', str_contains($h2, 'SIDE QUEST'));
[$s3, $h3] = $ack->render($cand, [$fresh, $other]);
$check('mixed with another position: the house email', !str_contains($h3, 'SIDE QUEST') && str_contains($h3, '#320A67'));
[, $h4] = $ack->render($cand, [$other]);
$check('any other position: the house email', !str_contains($h4, 'SIDE QUEST'));
[, $h5] = $ack->render($cand + ['x' => 1], [0]);
$check('a CV with no position: the house email', !str_contains($h5, 'SIDE QUEST'));
$db->run("INSERT INTO settings (k, v) VALUES ('ack_trainee_style', '0') ON DUPLICATE KEY UPDATE v = '0'");
[, $h6] = $ack->render($cand, [$fresh]);
$check('setting ack_trainee_style = 0 turns it off', !str_contains($h6, 'SIDE QUEST'));
$db->run("UPDATE settings SET v = '1' WHERE k = 'ack_trainee_style'");

echo "\nWhat it says (no details link: already answered)\n";
$check('greets by first name, title case', str_contains($h1, 'Priya.') && str_contains($t1, "You're in, Priya!"));
$check('public title with (On Contract)', str_contains($h1, 'Trainee Technicians (On Contract)') && str_contains($s1, 'Trainee Technicians (On Contract)'));
$check('internal position names never shown', !str_contains($h1 . $t1 . $s1, 'Freshers') && !str_contains($h1 . $t1, 'ZZ'));
$check('client never named', !str_contains($h1 . $t1, 'Hydraulics Client'));
$check('Level 3 is "you are here" when nothing is left to fill in', str_contains($t1, 'Level 3: We review your profile (YOU ARE HERE)') && !str_contains($h1, 'Complete my application'));
$check('LinkedIn follow card beside the headline and the side quest button', substr_count($h1, 'linkedin.com/company/eshcolglobal') >= 3);
$check('staffing footer wording', str_contains($t1, "on Eshcol Global's payroll"));
$check('"Please remove me" consent line kept', str_contains($h1, 'Please remove me') && str_contains($t1, 'Please remove me'));
$check('job link and resume@, no WhatsApp applications', str_contains($h1, 'jobs/trainee-technicians') && str_contains($h1, 'not on WhatsApp'));

echo "\nWith a details link\n";
[$s7, $h7, $t7] = TraineeTechAck::render('Priya', 'https://recruit.example/q/abc123', 'staffing');
$check('subject says one step left', str_contains($s7, "You're in, Priya: one step left"));
$check('the link is the button', str_contains($h7, 'href="https://recruit.example/q/abc123"') && str_contains($t7, 'Complete my application: https://recruit.example/q/abc123'));
$check('Level 2 is "you are here"', str_contains($t7, 'Level 2: Complete your details (YOU ARE HERE)'));
[, $h8, $t8] = TraineeTechAck::render('', null, 'staffing');
$check('no first name: no stray comma', str_contains($t8, "You're in!") && !str_contains($t8, "You're in, !"));

echo "\nWebsite applications find the position (23 Nov 2026)\n";
$mi = new Eshcol\Ats\Services\MailIntake($app);
$db->run("UPDATE roles SET title = ?, exp_min = 0, exp_max = 0.5 WHERE id = ?", ["Trainee Technicians $tag (Freshers)", $fresh]);
$db->run("UPDATE roles SET title = ?, exp_min = 1, exp_max = 7, status = 'draft' WHERE id = ?", ["Trainee Technicians $tag (Experienced)", $exp]);
$subj = "Job application — Trainee Technicians $tag, Fresh Engineering Graduates (Thiruvallur) — Madankumar Rana";
$check('the website subject finds the Freshers position', $mi->roleFromSubject($subj) === $fresh);
$db->run("UPDATE roles SET status = 'open' WHERE id = ?", [$exp]);
$check('both positions open: the lower experience range takes it', $mi->roleFromSubject($subj) === $fresh);
$check('an exact title still wins', $mi->roleFromSubject("Job application — Trainee Technicians $tag (Experienced) — Ravi") === $exp);
$check('an unrelated subject finds nothing', $mi->roleFromSubject("Job application — Welder $tag — Ravi") === null);
[, $h9] = $ack->render($cand, [$fresh]);
$check('the found position gets the trainee email', str_contains($h9, 'SIDE QUEST'));

echo "\nHouse rules\n";
foreach (['no link' => $h1 . $t1 . $s1, 'link' => $h7 . $t7 . $s7] as $k => $all) {
    $check("$k: no em dashes", !str_contains($all, '—') && !str_contains($all, '&mdash;'));
    $check("$k: no \"paid leave\", no 6 or 7 years", stripos($all, 'paid leave') === false && !preg_match('/\b[67] years\b/', $all));
}
