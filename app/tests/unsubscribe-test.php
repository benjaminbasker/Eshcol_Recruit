<?php
/**
 * One-click unsubscribe (14 Nov 2026): bulk emails to candidates carry List-Unsubscribe; the link can't be made up;
 * pressing it stops all email to that address and cancels what is waiting. Throwaway data. LOCAL ONLY.
 * Run: php tests/unsubscribe-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\EmailHealth;
use Eshcol\Ats\Services\Mailer;
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
$email = "unsub.$tag@candidates.test";
$cid = $db->insert('candidates', ['full_name' => "Uma Unsub $tag", 'email' => $email, 'email_norm' => $email, 'source' => 'upload']);
register_shutdown_function(function () use ($app, $db, $cid, &$ok, &$bad) {
    $db->run('DELETE FROM email_queue WHERE candidate_id = ?', [$cid]);
    (new Retention($app))->delete($cid, 'manual', false);
    echo "\n$ok passed, $bad failed\n";
});
$m = new Mailer($app);

echo "The header\n";
$h = $m->headersFor(['kind' => 'cv_request', 'candidate_id' => $cid, 'meta' => null]);
$check('a bulk email to a candidate carries one-click unsubscribe', str_contains($h['List-Unsubscribe'] ?? '', "/unsub/$cid-") && ($h['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click');
$check('the thank-you and details request do too', isset($m->headersFor(['kind' => 'ack', 'candidate_id' => $cid, 'meta' => null])['List-Unsubscribe'])
    && isset($m->headersFor(['kind' => 'questionnaire', 'candidate_id' => $cid, 'meta' => null])['List-Unsubscribe']));
$check('a personal message or an interview email does not', !isset($m->headersFor(['kind' => 'message', 'candidate_id' => $cid, 'meta' => json_encode(['personal' => true])])['List-Unsubscribe'])
    && !isset($m->headersFor(['kind' => 'interview', 'candidate_id' => $cid, 'meta' => null])['List-Unsubscribe']));

echo "\nThe link\n";
preg_match('#/unsub/([^>]+)>#', $h['List-Unsubscribe'], $mm);
$token = $mm[1];
$check('the real link points to them', EmailHealth::unsubscribeCandidate($app, $token) === $cid);
$other = ($cid + 1) . substr($token, strlen((string) $cid));
$check('changing the number (to unsubscribe someone else) does not work', EmailHealth::unsubscribeCandidate($app, $other) === null);
$check('nonsense does not work', EmailHealth::unsubscribeCandidate($app, 'abc') === null && EmailHealth::unsubscribeCandidate($app, "$cid-" . str_repeat('A', 22)) === null);

echo "\nPressing it\n";
$waiting = $m->enqueue(['kind' => 'cv_request', 'candidate_id' => $cid, 'to_email' => $email, 'subject' => 'x', 'html' => 'x', 'text' => 'x']);
(new EmailHealth($app))->unsubscribe($cid);
$c = $db->one('SELECT email_status FROM candidates WHERE id = ?', [$cid]);
$check('their address is marked unsubscribed', $c['email_status'] === 'unsubscribed');
$check('what was waiting to go to them is cancelled', $db->col('SELECT status FROM email_queue WHERE id = ?', [$waiting]) === 'cancelled');
$check('nothing more can be sent to that address', (new EmailHealth($app))->stopReason($email, $cid) !== null);
$check('recorded as consent withdrawn (unsubscribed)', $db->col('SELECT via FROM consents WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$cid]) === 'unsubscribed');
