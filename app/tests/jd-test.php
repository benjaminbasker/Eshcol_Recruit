<?php
declare(strict_types=1);

// Reading a client's JD to fill in a new role. Run: php tests/jd-test.php
require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\JdReader;
use Eshcol\Ats\Services\Skills;

$app = App::boot(cli: true);
$pass = $fail = 0;
$check = function (string $label, bool $ok) use (&$pass, &$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
$r = new JdReader(new Skills($app->db));

echo "Reading JDs\n";
$a = $r->read("Profile\nAdvantEdge is a boutique consulting firm. We are looking for an Accounting Associate to handle book-keeping and compliances (GST, TDS).\nMinimum 2-3 years of work experience.", 'Job Description_Associate.pdf');
$check('title from "looking for an Accounting Associate to…"', $a['title'] === 'Accounting Associate');
$check('experience 2–3 years', $a['exp_min'] == 2 && $a['exp_max'] == 3);
$check('skills from the dictionary become must-haves (GST, TDS)', in_array('GST', $a['skills'], true) && in_array('TDS', $a['skills'], true));
$check('nothing invented: no salary, notice or location in the text', $a['ctc_max_lpa'] === null && $a['notice_max_days'] === null && $a['locations'] === []);

$m = $r->read("The Accounting Manager will be responsible for the client's accounting. Minimum 5-10 years.", 'x.pdf');
$check('title from "The Accounting Manager will be responsible"', $m['title'] === 'Accounting Manager');

$l = $r->read("Job Title: Senior HR Executive\nCTC: 4 - 6 LPA. Notice period: max 30 days. 2 positions in Chennai.", 'jd.docx');
$check('a "Job Title:" line wins', $l['title'] === 'Senior HR Executive');
$check('salary band 4–6 LPA', $l['ctc_min_lpa'] == 4 && $l['ctc_max_lpa'] == 6);
$check('notice 30 days and 2 positions', $l['notice_max_days'] === 30 && $l['positions'] === 2);
$check('location Chennai', $l['locations'] === ['Chennai']);

$u = $r->read('Budget up to 8 lakhs per annum. Notice period of 2 months.', 'Job Description_Payroll Executive.pdf');
$check('"up to 8 lakhs" is the budget', $u['ctc_min_lpa'] === null && $u['ctc_max_lpa'] == 8);
$check('notice in months becomes days', $u['notice_max_days'] === 60);
$check('title falls back to the file name', $u['title'] === 'Payroll Executive');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
