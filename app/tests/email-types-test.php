<?php
/**
 * Every candidate email, for all three types of position (plan v2, phase 1 acceptance, 15 Nov 2026): the thank-you,
 * the details request, its reminder and final email, the interview invitation, the call letter (virtual, in person,
 * phone) and the interview-day reminder. Our own team never says "our client"; staffing says "on Eshcol's payroll";
 * client recruitment is unchanged; every one carries the LinkedIn button and none mentions Claude.
 * Throwaway data, removed afterwards; nothing is sent (the emails are only queued and read back). LOCAL ONLY.
 * Run: php tests/email-types-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Acknowledger;
use Eshcol\Ats\Services\CandidateImporter;
use Eshcol\Ats\Services\Engagement;
use Eshcol\Ats\Services\Interviews;
use Eshcol\Ats\Services\Questionnaires;
use Eshcol\Ats\Services\Retention;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond, string $why = '') use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . ($cond || $why === '' ? '' : "  [$why]") . "\n";
};
$tag = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$owner = (int) $db->col("SELECT id FROM users WHERE role = 'owner' ORDER BY id LIMIT 1");
$routingWas = $app->setting('routing_enabled');
$db->run("INSERT INTO settings (k, v) VALUES ('routing_enabled', '0') ON DUPLICATE KEY UPDATE v = '0'");
$client = $db->insert('clients', ['name' => "Zz Emails Client $tag", 'meeting_link' => 'https://meet.google.com/abc-defg-hij', 'interview_address' => 'Plot 4, SIPCOT, Tiruvallur']);
$roles = [
    'placement' => $db->insert('roles', ['client_id' => $client, 'engagement' => 'placement', 'title' => "Accountant ZZ$tag", 'location' => 'Pune', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]),
    'staffing' => $db->insert('roles', ['client_id' => $client, 'engagement' => 'staffing', 'contract_months' => 12, 'title' => "Operator ZZ$tag", 'location' => 'Tiruvallur', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]),
    'inhouse' => $db->insert('roles', ['client_id' => Engagement::selfClientId($app), 'engagement' => 'inhouse', 'title' => "Recruiter ZZ$tag", 'location' => 'Chennai', 'status' => 'open', 'accepts_routed' => 0, 'score_threshold' => 0]),
];
$made = [];
$emails = [];
register_shutdown_function(function () use ($app, $db, &$made, &$emails, $client, $roles, $routingWas, &$ok, &$bad) {
    if ($emails) {
        $db->run('DELETE FROM email_queue WHERE id IN (' . implode(',', array_map('intval', $emails)) . ')');
    }
    foreach (array_unique(array_filter($made)) as $id) {
        (new Retention($app))->delete((int) $id, 'manual', false);
    }
    $db->run('DELETE FROM interview_slots WHERE role_id IN (' . implode(',', $roles) . ')');
    $db->run('DELETE FROM roles WHERE id IN (' . implode(',', $roles) . ')');
    $db->run('DELETE FROM clients WHERE id = ?', [$client]);
    $routingWas === null ? $db->run("DELETE FROM settings WHERE k = 'routing_enabled'") : $db->run("UPDATE settings SET v = ? WHERE k = 'routing_enabled'", [$routingWas]);
    echo "\n$ok passed, $bad failed\n";
});
$imp = new CandidateImporter($app);
$qs = new Questionnaires($app);
$iv = new Interviews($app);
$text = fn (int $id) => (string) $db->col('SELECT CONCAT(subject, "\n", text, "\n", html) FROM email_queue WHERE id = ?', [$id]);
$latest = function (int $cid, string $kind) use ($db, &$emails): string {
    $row = $db->one('SELECT id, subject, text, html FROM email_queue WHERE candidate_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$cid, $kind]);
    if ($row) {
        $emails[] = (int) $row['id'];
    }
    return $row ? $row['subject'] . "\n" . $row['text'] . "\n" . $row['html'] : '';
};

foreach ($roles as $type => $roleId) {
    echo "\n" . Engagement::TYPES[$type] . "\n";
    $tmp = $app->storage("uploads-tmp/zz-em-$tag-$type.txt");
    file_put_contents($tmp, "Priya Zzem$type\npriya.$type.$tag@candidates.test | +91 9" . random_int(100000000, 999999999) . "\nChennai\nAccountant, 2021 – Present\nTally, GST.\nB.Com");
    $r = $imp->import($tmp, "p-$type.txt", $roleId, $owner);
    @unlink($tmp);
    $cid = (int) $r['candidate_id'];
    $made[] = $cid;
    $appId = (int) $db->col('SELECT id FROM applications WHERE candidate_id = ? AND role_id = ?', [$cid, $roleId]);
    $cand = $db->one('SELECT * FROM candidates WHERE id = ?', [$cid]);
    $all = [];
    [$subj, $html, $txt] = (new Acknowledger($app))->render($cand, [$roleId]);
    $all['thank-you email'] = "$subj\n$txt\n$html";
    $lead = $qs->create($appId, $owner);
    foreach (['invite' => 'details request', 'reminder' => 'details reminder', 'final' => 'final "still interested?" email'] as $step => $label) {
        $id = $qs->email($lead, $step, $owner);
        $emails[] = $id;
        $all[$label] = $text($id);
    }
    $iv->addSlots($roleId, 1, 'virtual', [date('Y-m-d 11:00:00', strtotime('+5 days'))], 30, 'test');
    $iv->invite($appId, 1, $owner);
    $all['interview invitation'] = $latest($cid, 'interview');
    foreach (['virtual', 'in_person', 'phone'] as $k) {
        [$okB] = $iv->book($appId, 1, $k, date('Y-m-d H:i', strtotime('+3 days')), 30, ['meeting_link' => 'https://meet.google.com/abc-defg-hij', 'address' => 'Plot 4, SIPCOT, Tiruvallur'], $owner);
        $all["call letter ($k)"] = $okB ? $latest($cid, 'interview') : '';
    }
    $db->run("UPDATE interviews SET starts_at = NOW() + INTERVAL 1 HOUR, reminded_at = NULL WHERE application_id = ? AND status = 'booked'", [$appId]);
    $iv->reminders();
    $all['interview-day reminder'] = $latest($cid, 'interview');

    foreach ($all as $label => $body) {
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES);
        $problems = [];
        if ($body === '') {
            $problems[] = 'not made';
        }
        if (stripos($plain, 'claude') !== false) {
            $problems[] = 'mentions Claude';
        }
        if (stripos($body, 'linkedin') === false) {
            $problems[] = 'no LinkedIn button';
        }
        // "other suitable roles at Eshcol or with our clients" is the approved line about future roles; nothing else may say it.
        if ($type === 'inhouse' && preg_match('/our client/i', str_ireplace('at Eshcol or with our clients', '', $plain))) {
            $problems[] = 'says "our client"';
        }
        if ($type === 'inhouse' && !str_contains($plain, 'Eshcol Global')) {
            $problems[] = 'no "Eshcol Global"';
        }
        if ($type === 'staffing' && !str_contains($plain, "Eshcol's payroll") && !str_contains($plain, 'Eshcol&#039;s payroll') && !str_contains($plain, "Eshcol Global's payroll")) {
            $problems[] = 'no "on Eshcol\'s payroll"';
        }
        if ($type === 'placement' && preg_match("/Eshcol's payroll|at Eshcol Global, /", $plain)) {
            $problems[] = 'client recruitment changed';
        }
        $check($label, $problems === [], implode('; ', $problems));
    }
}
