<?php
/**
 * LinkedIn profiles: read from the CV (also from a link behind the word "LinkedIn"), kept on the
 * candidate, offered on the details page, and filled in once for CVs already on file.
 * LOCAL ONLY. Run: php tests/linkedin-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\CvReader;
use Eshcol\Ats\Services\FieldExtractor;
use Eshcol\Ats\Services\Questionnaires;
use Eshcol\Ats\Services\Retention;
use Eshcol\Ats\Services\TextExtractor;

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
foreach (['routing_enabled' => '0', 'ack_enabled' => '0'] as $k => $v) {
    $saved[$k] = $app->setting($k);
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
$made = [];
$tmpFiles = [];
$clientId = $db->insert('clients', ['name' => "ZZ LinkedIn $tag"]);
register_shutdown_function(function () use ($app, $db, &$made, &$tmpFiles, $saved, $clientId, $tag, &$ok, &$bad) {
    $ret = new Retention($app);
    foreach (array_unique($made) as $id) {
        $ret->delete($id, 'manual', false);
    }
    $db->run('DELETE FROM roles WHERE client_id = ?', [$clientId]);
    $db->run('DELETE FROM clients WHERE id = ?', [$clientId]);
    foreach ($saved as $k => $v) {
        $v === null ? $db->run('DELETE FROM settings WHERE k = ?', [$k]) : $db->run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    }
    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    $left = (int) $db->col('SELECT COUNT(*) FROM candidates WHERE email_norm LIKE ?', ["%.$tag@candidates.test"]);
    echo $left ? "  FAIL clean-up left $left candidate(s)\n" : "  ok   nothing left behind\n";
    echo "\n" . ($ok + ($left ? 0 : 1)) . ' passed, ' . ($bad + ($left ? 1 : 0)) . " failed\n";
});

echo "Reading the link\n";
$check('a bare link is tidied to https://www.linkedin.com/in/…', FieldExtractor::linkedin('LinkedIn: linkedin.com/in/priya-k-123 | Pune') === 'https://www.linkedin.com/in/priya-k-123');
$check('the India sub-domain and a trailing slash are fine', FieldExtractor::linkedin('https://in.linkedin.com/in/Rahul.Verma/') === 'https://www.linkedin.com/in/Rahul.Verma');
$check('extra parts after the name are dropped', FieldExtractor::linkedin('www.linkedin.com/in/meena-iyer-4b2a/en?trk=x') === 'https://www.linkedin.com/in/meena-iyer-4b2a');
$check('a company page is not a profile', FieldExtractor::linkedin('https://www.linkedin.com/company/eshcolglobal/') === null);
$check('no link, nothing', FieldExtractor::linkedin('Skills: Tally, GST, LinkedIn Learning certificate') === null && FieldExtractor::linkedin(null) === null);
$check('Claude\'s answer is tidied the same way', (CvReader::toFields(['linkedin_url' => 'linkedin.com/in/anita-rao'])['linkedin_url'] ?? '') === 'https://www.linkedin.com/in/anita-rao'
    && !isset(CvReader::toFields(['linkedin_url' => 'not given'])['linkedin_url']));

echo "\nLinks behind a clickable word\n";
$docx = function (string $name, string $body, string $link) use ($app, &$tmpFiles): string {
    $p = $app->storage("uploads-tmp/zz-li-" . bin2hex(random_bytes(4)) . '.docx');
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
    $paras = implode('', array_map(fn ($l) => '<w:p><w:r><w:t>' . htmlspecialchars($l) . '</w:t></w:r></w:p>', explode("\n", $body)));
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paras
        . '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>LinkedIn</w:t></w:r></w:hyperlink></w:p></w:body></w:document>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . htmlspecialchars($link) . '" TargetMode="External"/></Relationships>');
    $z->close();
    $tmpFiles[] = $p;
    return $p;
};
$body = "Kavya Linkedtest\nkavya.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nPune\nAccounts executive with 3 years of experience in GST, TDS and Tally.\nHandled monthly GST returns, bank reconciliations and MIS reports for SME clients.\nB.Com";
$p = $docx('Kavya', $body, 'https://www.linkedin.com/in/kavya-linkedtest-99/');
$te = new TextExtractor();
$check('a Word hyperlink is found', $te->links($p, 'docx') === ['https://www.linkedin.com/in/kavya-linkedtest-99/']);
$check('…and added to the CV text, so every reader sees it', str_contains($te->extract($p, 'docx')['text'], 'Links in the CV: https://www.linkedin.com/in/kavya-linkedtest-99/'));
$pdf = $app->storage('uploads-tmp/zz-li-' . bin2hex(random_bytes(4)) . '.pdf');
$tmpFiles[] = $pdf;
file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
    . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Annots [4 0 R] >> endobj\n"
    . "4 0 obj << /Type /Annot /Subtype /Link /Rect [0 0 100 20] /A << /S /URI /URI (https://www.linkedin.com/in/pdf-person) >> >> endobj\n"
    . "trailer << /Root 1 0 R >>\n%%EOF");
$check('a PDF link is found', $te->links($pdf, 'pdf') === ['https://www.linkedin.com/in/pdf-person']);

echo "\nOn the candidate\n";
$r = (new CandidateImporter($app))->import($p, 'Kavya_Linkedtest.docx', null, $uid);
$made[] = $kid = (int) $r['candidate_id'];
$k = $db->one('SELECT * FROM candidates WHERE id = ?', [$kid]);
$check('an uploaded CV fills in the LinkedIn profile, marked "from CV"', $k['linkedin_url'] === 'https://www.linkedin.com/in/kavya-linkedtest-99' && (json_decode($k['field_sources'], true)['linkedin_url'] ?? '') === 'cv');

echo "\nCVs already on file (once, free)\n";
$db->run("UPDATE candidates SET linkedin_url = NULL WHERE id = ?", [$kid]);
$n = (new CandidateImporter($app))->backfillLinkedin();
$check("found again from the stored CV ($n filled)", $n >= 1 && $db->col('SELECT linkedin_url FROM candidates WHERE id = ?', [$kid]) === 'https://www.linkedin.com/in/kavya-linkedtest-99');
$db->run("UPDATE candidates SET linkedin_url = 'https://www.linkedin.com/in/kept-by-you' WHERE id = ?", [$kid]);
(new CandidateImporter($app))->backfillLinkedin();
$check('one already set is never replaced', $db->col('SELECT linkedin_url FROM candidates WHERE id = ?', [$kid]) === 'https://www.linkedin.com/in/kept-by-you');

echo "\nOn the details page\n";
$role = $db->insert('roles', ['client_id' => $clientId, 'title' => 'Accounts Officer', 'location' => 'Pune', 'status' => 'open', 'score_threshold' => 0]);
$db->run('UPDATE candidates SET linkedin_url = NULL WHERE id = ?', [$kid]);
$app_ = $db->insert('applications', ['candidate_id' => $kid, 'role_id' => $role, 'stage_id' => (int) $db->col('SELECT id FROM stages ORDER BY sort LIMIT 1')]);
$svc = new Questionnaires($app);
$qs = array_column($svc->questionsFor([$db->one('SELECT * FROM roles WHERE id = ?', [$role])], $db->one('SELECT * FROM candidates WHERE id = ?', [$kid])), null, 'key');
$check('an optional "Your LinkedIn profile" in "Check your details"', isset($qs['linkedin']) && $qs['linkedin']['part'] === 1 && !$qs['linkedin']['required']);
$qid = $svc->create($app_, $uid);
$q = $svc->byToken((string) \Eshcol\Ats\Secrets::open($app, (string) $db->col('SELECT token_enc FROM questionnaires WHERE id = ?', [$qid]), 'questionnaire'));
$base = ['full_name' => 'Kavya Linkedtest', 'location' => 'Pune', 'locality' => 'Baner', 'ctc_current' => '3', 'ctc_min' => '3.5', 'notice' => '4 weeks', 'relocate' => 'Yes', 'permission' => 'Yes', 'keep' => '1'];
$errs = $svc->submit($q, $base + ['linkedin' => 'my profile']);
$check('something that is not a LinkedIn link is pointed out', isset($errs['linkedin']));
$errs = $svc->submit($q, $base + ['linkedin' => 'in.linkedin.com/in/kavya-new']);
$k = $db->one('SELECT * FROM candidates WHERE id = ?', [$kid]);
$check('a link they give is saved, tidied and marked "from the candidate"', $errs === [] && $k['linkedin_url'] === 'https://www.linkedin.com/in/kavya-new' && (json_decode($k['field_sources'], true)['linkedin_url'] ?? '') === 'candidate');
exit(0);
