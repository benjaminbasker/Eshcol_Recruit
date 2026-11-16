<?php
/**
 * Engine tests: skills matching, detail extraction, scoring, text extraction.
 * Fictional sample people only. Run: php tests/run.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\FieldExtractor;
use Eshcol\Ats\Services\Scorer;
use Eshcol\Ats\Services\Skills;
use Eshcol\Ats\Services\TextExtractor;

$app = App::boot(cli: true);
$skills = new Skills($app->db);
$fx = new FieldExtractor($skills);
$pass = 0;
$fail = 0;
function check(string $what, mixed $got, mixed $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        echo "  ok   $what\n";
    } else {
        $fail++;
        echo "  FAIL $what: got " . var_export($got, true) . ', wanted ' . var_export($want, true) . "\n";
    }
}

echo "Skills dictionary\n";
check('TallyPrime counts as Tally', array_key_exists('Tally', $skills->find('Worked on TallyPrime daily')), true);
check('Tally ERP 9 counts as Tally', array_key_exists('Tally', $skills->find('Expert in Tally ERP 9 and GST')), true);
check('"sap fico" wins over "sap"', array_keys($skills->find('Implemented SAP FICO module')), ['SAP FICO']);
check('no false hit inside a word ("establish" is not "esi")', array_key_exists('PF & ESI', $skills->find('Helped establish the team')), false);
check('Bangalore maps to Bengaluru', array_keys($skills->find('Based in Bangalore', 'location')), ['Bengaluru']);
check('canonical() of a synonym', $skills->canonical('tallyprime'), 'Tally');
check('locate() finds a synonym of a must-have', $skills->locate('Used Tally.ERP 9 for 3 years', 'Tally') !== null, true);

$cv1 = <<<TXT
PRIYA RAMANATHAN
Accounts Executive
Mobile: +91 98401 23456 | Email: priya.ramanathan.test@gmail.com
Current Location: Chennai

PROFESSIONAL SUMMARY
Accounts professional with 4.5 years of experience in GST compliance, TDS filings and
month-end closing using TallyPrime and advanced Excel.

EXPERIENCE
Senior Accounts Executive, Lakshmi Traders Pvt Ltd        Mar 2022 - Present
- Prepared GSTR-1 and GSTR-3B returns for three entities
- Bank reconciliation and vendor payments (accounts payable)
Accounts Assistant, Sree Ganesh Agencies                    Jun 2019 - Feb 2022

EDUCATION
B.Com, University of Madras, 2016 - 2019

Current CTC: 4.2 LPA | Expected CTC: 5.5 LPA | Notice Period: 30 days
TXT;

echo "\nDetail extraction (stated details)\n";
$r = $fx->extract($cv1, 'Priya_Resume.pdf');
$f = $r['fields'];
check('name', $f['full_name'] ?? null, 'Priya Ramanathan');
check('first name', $f['first_name'] ?? null, 'Priya');
check('email', $f['email'] ?? null, 'priya.ramanathan.test@gmail.com');
check('phone', $f['phone'] ?? null, '9840123456');
check('experience from statement (4.5 yrs = 54 months)', $f['exp_months'] ?? null, 54);
check('experience source', $r['sources']['exp_months'] ?? null, 'cv');
check('current CTC', $f['ctc_current_lpa'] ?? null, 4.2);
check('expected CTC', $f['ctc_expected_lpa'] ?? null, 5.5);
check('notice', $f['notice_days'] ?? null, 30);
check('location', $f['location'] ?? null, 'Chennai');
check('qualification', $f['qualifications'] ?? null, 'B.Com');
check('skills include GST, TDS, Tally', count(array_intersect(['GST', 'TDS', 'Tally'], $r['skills'])), 3);

$cv2 = <<<TXT
Curriculum Vitae
Name: Arjun Menon
arjun.menon.example@yahoo.co.in   0 99620 55555
Piping Designer

Work history
Piping Designer, Gulf Petrochem EPC, Dubai          Jan 2020 to till date
Junior Draftsman, Coastal Engineering, Kochi        Jul 2016 - Dec 2019

Software: AutoCAD, PDMS, SP3D. Prepared piping isometrics and GA drawings from P&IDs.
Diploma in Mechanical Engineering (2013 - 2016)
Notice period: 2 months. Willing to relocate.
TXT;

echo "\nDetail extraction (estimated details)\n";
$r2 = $fx->extract($cv2, 'cv.pdf');
$f2 = $r2['fields'];
check('name after "Name:" label', $f2['full_name'] ?? null, 'Arjun Menon');
check('phone with leading 0', $f2['phone'] ?? null, '9962055555');
$expected = (int) date('Y') * 12 + (int) date('n') - (2016 * 12 + 7);
check('experience estimated from month-year ranges (education years ignored), to the month', abs(($f2['exp_months'] ?? 0) - $expected) <= 1, true);
check('marked as estimated', $r2['sources']['exp_months'] ?? null, 'estimated');
check('notice in months -> days', $f2['notice_days'] ?? null, 60);
check('skills', count(array_intersect(['AutoCAD', 'PDMS / E3D', 'SP3D', 'Isometrics', 'P&ID', 'Oil & Gas / EPC'], $r2['skills'])), 6);

echo "\nCTC is never taken from the wrong label\n";
$only = $fx->extract("Divya Krishnan\nAccounts Officer\nExpected CTC: 5 LPA", 'x.pdf')['fields'];
check('"Expected CTC" alone does not fill current CTC', $only['ctc_current_lpa'] ?? null, null);
check('"Expected CTC" alone fills expected CTC', $only['ctc_expected_lpa'] ?? null, 5.0);
$plain = $fx->extract("Name Here\nCTC: 3.6 LPA", 'x.pdf')['fields'];
check('plain "CTC" counts as current', $plain['ctc_current_lpa'] ?? null, 3.6);
$monthly = $fx->extract("Name Here\nCurrent salary: Rs. 32,000 per month", 'x.pdf')['fields'];
check('monthly salary converts to LPA', $monthly['ctc_current_lpa'] ?? null, 3.84);

echo "\nDetail extraction (sparse CV, name from file name)\n";
$r3 = $fx->extract("Summary\nExperienced in sales and distribution.\nskills: negotiation", 'Rahul_Verma_Updated_Resume_2026.docx');
check('name from file name', $r3['fields']['full_name'] ?? null, 'Rahul Verma');
check('source is filename', $r3['sources']['full_name'] ?? null, 'filename');
check('never picks an Eshcol or portal address', $fx->email('Apply to resume@eshcolglobal.com or candidate.real@gmail.com'), 'candidate.real@gmail.com');
check('ignores noreply', $fx->email('From: noreply@indeed.com'), null);

echo "\nScorecard\n";
$scorer = new Scorer($skills);
$role = ['exp_min' => 3, 'exp_max' => 6, 'location' => 'Chennai', 'notice_max_days' => 45, 'ctc_max_lpa' => 6, 'weights' => null];
$crit = [
    ['kind' => 'must', 'value' => 'GST'], ['kind' => 'must', 'value' => 'TDS'],
    ['kind' => 'must', 'value' => 'Tally'], ['kind' => 'must', 'value' => 'Payroll'],
    ['kind' => 'nice', 'value' => 'Excel'], ['kind' => 'qualification', 'value' => 'B.Com'],
];
$cand = $f + ['qualifications' => 'B.Com'];
$s = $scorer->score($role, $crit, $cand, $cv1);
check('strong candidate scores 80 (3 of 4 must-haves 37.5; 4.5 yrs in a 3-6 band 85% = 12.75; B.Com meets the requirement 5 of 10; the rest full)', $s['score'], 80);
check('Payroll listed as missing', $s['breakdown']['parts']['must']['missing'], ['Payroll']);
check('Excel counted as a nice-to-have', $s['breakdown']['nice'], ['Excel']);
check('evidence snippet marks the matched word', str_contains($s['breakdown']['parts']['must']['matched'][0]['snippet'], '<mark>'), true);

$weak = $scorer->score($role, $crit, ['exp_months' => 24, 'location' => 'Pune', 'notice_days' => 90, 'ctc_expected_lpa' => 9.0], "Sales executive in Pune. Excel.");
check('weak candidate scores low', $weak['score'] < 40, true);
$unknown = $scorer->score($role, $crit, [], $cv1);
check('unknown details are listed, not punished to zero', $unknown['breakdown']['unknown'], ['experience', 'location', 'notice period', 'expected CTC']);

echo "\nExperience grows with time\n";
$twoYearsAgo = date('Y-m-d', strtotime('-2 years'));
$old = ['exp_months' => 36, 'exp_as_of' => $twoYearsAgo];
check('3 yrs recorded two years ago is 5 yrs today', exp_now($old), 60);
check('marked as an estimate', exp_is_estimate($old), true);
check('a figure recorded today is not an estimate', exp_is_estimate(['exp_months' => 36, 'exp_as_of' => date('Y-m-d')]), false);
check('no date means no growth', exp_now(['exp_months' => 36, 'exp_as_of' => null]), 36);
check('unknown experience stays unknown', exp_now(['exp_months' => null]), null);
$grownScore = $scorer->score(['exp_min' => 5, 'exp_max' => 8, 'location' => null, 'notice_max_days' => null, 'ctc_max_lpa' => null, 'weights' => null], [], $old, '');
check('scoring uses today\'s experience (5 yrs: the bottom of a 5-8 band, 70% of 15)', $grownScore['breakdown']['parts']['experience']['points'], 10.5);

echo "\nExperience against the band (10 Nov 2026: one rule for both scores)\n";
$band = ['exp_min' => 5, 'exp_max' => 10, 'exp_allow_below' => 1, 'exp_allow_above' => 5];
$fit = fn (float $y) => round(Eshcol\Ats\Services\RoleRouter::expFit($band, $y)[0], 2);
check('5–10 band: 5 yrs 70%, 7.5 yrs 85%, 10 yrs 100% (rises through the band)', [$fit(5), $fit(7.5), $fit(10)], [0.7, 0.85, 1.0]);
check('below the band, inside the −1 allowance: 4.5 yrs 40%, 4 yrs 30% (Deepa Rani was 90%)', [$fit(4.5), $fit(4)], [0.4, 0.3]);
check('above the band, inside the allowance: 100% (Benjamin); beyond it 30%', [$fit(12), $fit(15), $fit(16)], [1.0, 1.0, 0.3]);
check('below the allowance falls towards 0', $fit(2) < 0.3 && $fit(0) === 0.0, true);

echo "\nBlind assessment (10 Nov 2026)\n";
$cvB = "DEEPA RANI\nEmail: deepa.r@gmail.com | +91 72558 52762 | linkedin.com/in/deepa-rani-1\nDate of Birth: 12/03/1999\nGender - Female\nMarital Status: Single\nFather's Name: Ram Prasad\nReligion: Hindu\n"
    . "Address: 12 Gandhi Nagar, Patna\nLanguages: English, Hindi\nAccounts Executive, Shah & Co  Apr 2019-2022\nFiled GSTR-1 for 30+ clients, CTC 4,50,000. Deepa led the team. Call 987 654 3210 or 0612-2345678.\nAgency work: none";
$bl = Eshcol\Ats\Services\FitAssessor::blind($cvB, ['full_name' => 'Deepa Rani']);
check('name, email, phone, link and landline removed', [str_contains($bl, 'Deepa') || str_contains($bl, 'DEEPA'), str_contains($bl, '@'), str_contains($bl, '72558'), str_contains($bl, 'linkedin'), str_contains($bl, '2345678')], [false, false, false, false, false]);
check('date of birth, gender, marital status, father, religion and address lines removed',
    preg_match('/1999|Female|Single|Ram Prasad|Hindu|Gandhi Nagar/', $bl), 0);
check('the work stays: employer, years, salary, skills, languages; "Agency work" is not taken for "Age"',
    [str_contains($bl, 'Shah & Co  Apr 2019-2022'), str_contains($bl, '4,50,000'), str_contains($bl, 'GSTR-1'), str_contains($bl, 'Languages: English'), str_contains($bl, 'Agency work')], [true, true, true, true, true]);

echo "\nQualification ladder in the free score\n";
$ladder = Eshcol\Ats\Services\FitAssessor::ladderFor(['qual_ladder' => null], ['B.Com']);
$q = fn (string $quals) => $scorer->qualificationFit($ladder, $quals, '')[0];
check('B.Com meets the requirement: 5 of 10', $q('B.Com (Hons.)'), 0.5);
check('M.Com or an MBA on top of a B.Com: 7 of 10', [$q('B.Com, M.Com'), $q('B.Com, MBA (Finance)')], [0.7, 0.7]);
check('CA Inter cleared, CMA Inter or a qualified CA: 10 of 10', [$q('B.Com, CA Inter Group 1 cleared'), $q('CMA Inter, B.Com'), $q('Chartered Accountant (CA), B.Com')], [1.0, 1.0, 1.0]);
check('CA Inter only appeared for, or aspiring CA: one step lower (7)', [$q('B.Com, CA Inter (Appeared)'), $q('Aspiring CA; B.Com')], [0.7, 0.7]);
check('an MBA after a BBM is not a B.Com: below the requirement (EG-10059)', $q('BBM, MBA (Finance)'), 0.0);
check('a B.Com still being studied: below the requirement', $q('B.Com (pursuing)'), 0.0);
check('a role asking one level only (no higher, no professional): meeting it is 10 of 10',
    $scorer->qualificationFit(['base' => 'B.E.', 'higher' => '', 'top' => ''], 'B.E. Mechanical', '')[0], 1.0);
check('the scorecard says it was grown', str_contains($grownScore['breakdown']['parts']['experience']['note'], 'grown to today'), true);

echo "\nText extraction\n";
$tmp = sys_get_temp_dir() . '/eshcol_test_' . getmypid();
@mkdir($tmp);
$docx = "$tmp/sample.docx";
$zip = new ZipArchive();
$zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$paras = '';
foreach (explode("\n", $cv1) as $line) {
    $paras .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1) . '</w:t></w:r></w:p>';
}
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paras . '</w:body></w:document>');
$zip->close();
$te = new TextExtractor();
$d = $te->extract($docx, 'docx');
check('.docx read ok', $d['status'], 'ok');
check('.docx text keeps lines', str_contains($d['text'], "PRIYA RAMANATHAN\nAccounts Executive"), true);
// Audit S6: a small Word file that unpacks to more than the limit is refused, without unpacking it all.
$bomb = "$tmp/bomb.docx";
$zip = new ZipArchive();
$zip->open($bomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', str_repeat('A', TextExtractor::DOCX_XML_MAX + 1024));
$zip->close();
check('audit S6: the test file is small on disk', filesize($bomb) < 200000, true);
$b = $te->extract($bomb, 'docx');
check('audit S6: a Word file that unpacks too large is refused', $b['status'] === 'failed' && $b['text'] === '', true);
@unlink($bomb);

// A minimal real PDF with one line of text, built by hand.
$content = "BT /F1 12 Tf 50 750 Td (Kavitha Subramanian - Document Controller with 6 years of experience in Aconex and EDMS for EPC projects in Chennai and Doha.) Tj ET";
$objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream",
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
];
$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objs as $i => $o) {
    $offsets[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
foreach ($offsets as $off) {
    $pdf .= sprintf("%010d 00000 n \n", $off);
}
$pdf .= "trailer << /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
file_put_contents("$tmp/sample.pdf", $pdf);
$p = $te->extract("$tmp/sample.pdf", 'pdf');
check('.pdf read ok', $p['status'], 'ok');
check('.pdf text found', str_contains($p['text'], 'Document Controller'), true);

file_put_contents("$tmp/scan.pdf", str_replace($content, 'BT ET', $pdf));
check('a PDF with no text is sent for OCR', $te->extract("$tmp/scan.pdf", 'pdf')['status'], 'needs_ocr');
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
