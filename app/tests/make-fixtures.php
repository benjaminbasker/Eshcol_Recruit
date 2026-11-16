<?php
/**
 * Builds realistic test CVs for FICTIONAL people in several formats, for trying
 * the app end to end. Run: php tests/make-fixtures.php  -> tests/fixtures/
 */
declare(strict_types=1);

$dir = __DIR__ . '/fixtures';
@mkdir($dir);
array_map('unlink', glob("$dir/*") ?: []);

$people = [
    'Priya_Ramanathan_CV' => "PRIYA RAMANATHAN\nAccounts Executive\nMobile: +91 98401 23456 | Email: priya.ramanathan.test@gmail.com\nCurrent Location: Chennai\n\nPROFESSIONAL SUMMARY\nAccounts professional with 4.5 years of experience in GST compliance, TDS filings and month-end closing using TallyPrime and advanced Excel.\n\nEXPERIENCE\nSenior Accounts Executive, Lakshmi Traders Pvt Ltd   Mar 2022 - Present\n- Prepared GSTR-1 and GSTR-3B returns for three entities\n- Bank reconciliation and vendor payments (accounts payable)\nAccounts Assistant, Sree Ganesh Agencies   Jun 2019 - Feb 2022\n\nEDUCATION\nB.Com, University of Madras, 2016 - 2019\n\nCurrent CTC: 4.2 LPA | Expected CTC: 5.5 LPA | Notice Period: 30 days",
    'Karthik Selvam Resume' => "Karthik Selvam\nkarthik.selvam.sample@outlook.com | 97910 44556\nChennai, Tamil Nadu\n\nSummary\n3 years of experience in accounts and payroll processing. Strong in Tally ERP 9, GST returns and PF/ESI statutory compliance.\n\nWork Experience\nAccounts & Payroll Officer, Velan Logistics   Oct 2023 - Present\nJunior Accountant, Arul Textiles   Nov 2021 - Sep 2023\n\nQualification: B.Com (Computer Applications)\nCurrent CTC: 3.8 LPA. Expected CTC: 6.5 LPA. Notice period: 60 days.",
    'Meena_Iyer' => "Meena Iyer\nmeena.iyer.demo@gmail.com  +91-94430-12345\nCoimbatore\n\nCareer Objective\nAccounts assistant with 2 years of experience seeking a role in a growing firm. Willing to relocate to Chennai.\n\nSkills: Tally, MS Excel, bank reconciliation, invoicing\nEducation: M.Com, Bharathiar University\nNotice period: 15 days",
    'Arjun Menon CV' => "Curriculum Vitae\nName: Arjun Menon\narjun.menon.example@yahoo.co.in   0 99620 55555\nPiping Designer\n\nWork history\nPiping Designer, Gulf Petrochem EPC, Dubai   Jan 2020 to till date\nJunior Draftsman, Coastal Engineering, Kochi   Jul 2016 - Dec 2019\n\nSoftware: AutoCAD, PDMS, SP3D. Prepared piping isometrics and GA drawings from P&IDs.\nDiploma in Mechanical Engineering (2013 - 2016)\nNotice period: 2 months. Willing to relocate.",
    'Rahul_Verma_Updated_Resume_2026' => "Summary\nField sales executive, FMCG distribution, Pune and Mumbai. Met monthly targets for 3 years.\nSkills: negotiation, dealer management, CRM\nB.Sc, Savitribai Phule Pune University",
];

function docx(string $path, string $text): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $p = '';
    foreach (explode("\n", $text) as $line) {
        $p .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1) . '</w:t></w:r></w:p>';
    }
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $p . '</w:body></w:document>');
    $zip->close();
}

function pdf(string $path, string $text): void
{
    $ops = "BT /F1 10 Tf 50 790 Td 13 TL\n";
    foreach (explode("\n", $text) as $line) {
        foreach (str_split($line, 95) ?: [''] as $chunk) {
            $ops .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $chunk) . ") Tj T*\n";
        }
    }
    $ops .= 'ET';
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($ops) . " >>\nstream\n$ops\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $out = "%PDF-1.4\n";
    $off = [];
    foreach ($objs as $i => $o) {
        $off[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $x = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) {
        $out .= sprintf("%010d 00000 n \n", $o);
    }
    $out .= 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    file_put_contents($path, $out);
}

// Formats: a mix of PDF and Word, like real applications.
pdf("$dir/Priya_Ramanathan_CV.pdf", $people['Priya_Ramanathan_CV']);
docx("$dir/Karthik Selvam Resume.docx", $people['Karthik Selvam Resume']);
docx("$dir/Meena_Iyer.docx", $people['Meena_Iyer']);
pdf("$dir/Arjun Menon CV.pdf", $people['Arjun Menon CV']);
file_put_contents("$dir/Rahul_Verma_Updated_Resume_2026.txt", $people['Rahul_Verma_Updated_Resume_2026']);

// Priya sends an updated CV later (same email, different file): should attach to her, not duplicate.
pdf("$dir/Priya R - updated Nov 2026.pdf", $people['Priya_Ramanathan_CV'] . "\n\nUpdate: now also handling TDS returns and payroll for 60 staff.");

// A zip of three, one of which is an exact repeat of Karthik's file.
$zip = new ZipArchive();
$zip->open("$dir/indeed-batch.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFile("$dir/Karthik Selvam Resume.docx", 'applicants/Karthik Selvam Resume.docx');
$zip->addFile("$dir/Meena_Iyer.docx", 'applicants/Meena_Iyer.docx');
$zip->addFromString('applicants/notes.xlsx', 'not a cv');
$zip->close();

// A "scanned" CV: text drawn into a PNG image, so it has no text layer at all.
$scan = "DIVYA KRISHNAN\nAccounts Officer\ndivya.krishnan.sample@gmail.com\n+91 99400 11223\nChennai\n\n5 years of experience in GST,\nTDS and Tally. B.Com.\nNotice period: 30 days\nExpected CTC: 5 LPA";
$font = 'C:/Windows/Fonts/arial.ttf';
$img = imagecreatetruecolor(1240, 1000);
$white = imagecolorallocate($img, 255, 255, 255);
$ink = imagecolorallocate($img, 25, 25, 25);
imagefilledrectangle($img, 0, 0, 1240, 1000, $white);
$y = 80;
foreach (explode("\n", $scan) as $line) {
    imagettftext($img, 30, 0, 80, $y, $ink, $font, $line);
    $y += 70;
}
imagepng($img, "$dir/Divya_Krishnan_scan.png");

foreach (glob("$dir/*") as $f) {
    printf("%-42s %7s bytes\n", basename($f), number_format(filesize($f)));
}
