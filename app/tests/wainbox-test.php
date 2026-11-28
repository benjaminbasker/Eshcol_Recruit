<?php
/**
 * The WABridge webhook (update 083, 28 Nov 2026): the secret address, Meta-shaped and flat payloads, matching a
 * reply to a candidate by mobile, STOP turning WhatsApp off, delivery reports, and anything unreadable still kept.
 * LOCAL ONLY: throwaway "ZZ" records. Run: php tests/wainbox-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Services\Migrator;
use Eshcol\Ats\Services\WaInbox;

$app = App::boot(cli: true);
if (!$app->isLocal()) {
    exit("Local only.\n");
}
(new Migrator($app))->run();
$db = $app->db;
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};
$tag = bin2hex(random_bytes(3));
$digits = '98' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$cand = $db->insert('candidates', ['full_name' => "Zz Wainbox $tag", 'email' => "zz.wainbox.$tag@example.test", 'phone' => '+91 ' . $digits, 'phone_norm' => $digits, 'source' => 'manual']);
$qid = $db->insert('whatsapp_queue', ['candidate_id' => $cand, 'to_number' => '91' . $digits, 'template' => 'call_request', 'status' => 'sent', 'message_id' => "zzmsg$tag"]);
$I = new WaInbox($app);
$start = (int) $db->col('SELECT COALESCE(MAX(id), 0) FROM whatsapp_inbox');
$rows = fn () => $db->all('SELECT * FROM whatsapp_inbox WHERE id > ? ORDER BY id', [$start]);

try {
    echo "The address\n";
    $url = $I->webhookUrl();
    $tok = substr($url, strrpos($url, '/') + 1);
    $check('ends in a long secret', strlen($tok) >= 32 && str_contains($url, '/hooks/wabridge/'));
    $check('the same secret each time', $I->webhookUrl() === $url);
    $check('the right secret is accepted', $I->validToken($tok));
    $check('a wrong or short one is refused', !$I->validToken($tok . 'x') && !$I->validToken('') && !$I->validToken('abc'));

    echo "Meta-shaped reply (button tap)\n";
    $I->record(json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '91' . $digits, 'id' => 'wamid.zz1', 'type' => 'button', 'button' => ['text' => 'Yes, please call me']]]]]]]]]));
    $r = $rows();
    $check('stored as a button tap from the candidate', count($r) === 1 && $r[0]['kind'] === 'button' && $r[0]['body'] === 'Yes, please call me' && (int) $r[0]['candidate_id'] === $cand);
    $check('with the raw payload kept', str_contains((string) $r[0]['payload'], 'wamid.zz1'));

    echo "Flat reply\n";
    $I->record(json_encode(['data' => ['mobile' => $digits, 'message' => 'I will be there']]));
    $r = $rows();
    $check('stored as text, matched by the last 10 digits', end($r)['kind'] === 'text' && end($r)['body'] === 'I will be there' && (int) end($r)['candidate_id'] === $cand);

    echo "Delivery report\n";
    $I->record(json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => "zzmsg$tag", 'status' => 'read', 'recipient_id' => '91' . $digits]]]]]]]]));
    $check('updates the sent message', $db->col('SELECT dlr_status FROM whatsapp_queue WHERE id = ?', [$qid]) === 'read');
    $check('and stays out of the replies list', !array_filter($I->recent(50), fn ($x) => $x['kind'] === 'status' && (int) $x['candidate_id'] === $cand));

    echo "STOP\n";
    $I->record(json_encode(['from' => '+91 ' . $digits, 'text' => 'Stop']));
    $check('turns WhatsApp off for them', (int) $db->col('SELECT whatsapp_off FROM candidates WHERE id = ?', [$cand]) === 1);
    $check('with a note', (bool) $db->col("SELECT 1 FROM notes WHERE entity_type = 'candidate' AND entity_id = ? AND body LIKE '%STOP%'", [$cand]));

    echo "Anything else\n";
    $n = $I->record('not json at all {');
    $r = $rows();
    $check('an unreadable call is still kept', $n === 1 && end($r)['kind'] === 'unknown' && end($r)['payload'] === 'not json at all {');
    $I->record(json_encode(['from' => '9000000001', 'text' => 'Hello']));
    $r = $rows();
    $check('an unknown number is stored without a candidate', end($r)['candidate_id'] === null && end($r)['from_number'] === '9000000001');
    $check('and shown masked', ($I->recent(1)[0]['from_masked'] ?? '') === '90xxxxx001');
} finally {
    $db->run('DELETE FROM whatsapp_inbox WHERE id > ?', [$start]);
    $db->run('DELETE FROM whatsapp_queue WHERE id = ?', [$qid]);
    $db->run("DELETE FROM notes WHERE entity_type = 'candidate' AND entity_id = ?", [$cand]);
    $db->run('DELETE FROM candidates WHERE id = ?', [$cand]);
}

echo "\n$ok passed, $bad failed\n";
exit($bad ? 1 : 0);
