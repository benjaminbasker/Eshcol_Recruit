<?php
/**
 * Audit F17 (16 Nov 2026): one place for "may we contact them / put them forward" and one for the "same field" rule.
 * Pure checks, plus: no hand-written copy of either rule is left in the code. Run: php tests/contact-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\Services\Contact as C;
use Eshcol\Ats\Services\RoleRouter;

$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};

echo "Who we may contact\n";
$fine = ['do_not_contact' => 0, 'removal_requested_at' => null, 'unreliable_at' => null];
$check('an ordinary candidate: contact and put forward', !C::silenced($fine) && C::canPutForward($fine));
$check('do-not-contact: silenced', C::silenced(['do_not_contact' => 1] + $fine) && !C::canPutForward(['do_not_contact' => 1] + $fine));
$check('asked us not to keep their details: silenced, and never put forward', C::silenced(['removal_requested_at' => '2026-11-15 10:00:00'] + $fine)
    && !C::canPutForward(['removal_requested_at' => '2026-11-15 10:00:00'] + $fine));
$check('marked unreliable: may be contacted, but not put forward', !C::silenced(['unreliable_at' => '2026-11-15'] + $fine) && !C::canPutForward(['unreliable_at' => '2026-11-15'] + $fine));
$check('a row missing the columns counts as fine (not silenced)', !C::silenced([]));
$check('SQL: contact', C::sql('c') === 'c.do_not_contact = 0 AND c.removal_requested_at IS NULL');
$check('SQL: put forward adds unreliable', C::putForwardSql('x') === 'x.do_not_contact = 0 AND x.removal_requested_at IS NULL AND x.unreliable_at IS NULL');

echo "\nThe same-field rule\n";
$check('half the must-haves is enough (2 of 4)', RoleRouter::sameField(2, 4));
$check('less than half is not (1 of 3)', !RoleRouter::sameField(1, 3));
$check('2 of 3 is', RoleRouter::sameField(2, 3));
$check('a position with no must-haves rules nobody out', RoleRouter::sameField(0, 0));

echo "\nNo copies left\n";
$left = [];
foreach (array_merge(glob(dirname(__DIR__) . '/src/*/*.php'), glob(dirname(__DIR__) . '/src/*.php')) as $f) {
    if (str_ends_with($f, 'Contact.php')) {
        continue;
    }
    $s = (string) file_get_contents($f);
    if (preg_match("/do_not_contact = 0|\\['do_not_contact'\\] \\|\\||do_not_contact OR removal/", $s) || (preg_match('/\* 2 (>=|<) /', $s) && !str_ends_with($f, 'RoleRouter.php'))) {
        $left[] = basename($f);
    }
}
$check('no hand-written do-not-contact or same-field check outside the shared helpers' . ($left ? ' (' . implode(', ', $left) . ')' : ''), $left === []);

echo "\nMessaged, awaiting reply (16 Nov)\n";
$KF = \Eshcol\Ats\Services\KeyFacts::class;
$check('never written to: no tag', $KF::contact([]) === null && $KF::contact(['msg_in' => '2026-11-10 10:00:00']) === null);
$check('we emailed, nothing back: "Messaged 16 Nov · awaiting reply"', $KF::contact(['msg_out' => '2026-11-16 10:00:00', 'msg_out_via' => 'email']) === ['wait', 'Messaged 16 Nov · awaiting reply']);
$check('WhatsApp opened from their page: "WhatsApp\'d …"', $KF::contact(['msg_out' => '2026-11-16 10:00:00', 'msg_out_via' => 'whatsapp'])[1] === "WhatsApp'd 16 Nov · awaiting reply");
$check('their older reply doesn\'t count: still awaiting', $KF::contact(['msg_out' => '2026-11-16 10:00:00', 'msg_out_via' => 'email', 'msg_in' => '2026-11-12 09:00:00'])[0] === 'wait');
$check('they wrote back after: "Replied 17 Nov"', $KF::contact(['msg_out' => '2026-11-16 10:00:00', 'msg_in' => '2026-11-17 09:00:00']) === ['replied', 'Replied 17 Nov']);
$check('an unread reply: "New reply"', $KF::contact(['msg_out' => '2026-11-16 10:00:00', 'msg_in' => '2026-11-17 09:00:00', 'msg_unread' => 1])[0] === 'new');
$check('the tag is in the key-details line', str_contains($KF::line(['msg_out' => '2026-11-16 10:00:00', 'msg_out_via' => 'email', 'exp_months' => 24]), 'k-msg-wait'));

echo "\nThe Call button dials from the computer (16 Nov)\n";
$check('a 10-digit mobile gets +91', tel_link('98765 43210') === 'tel:+919876543210');
$check('written with +91 or 91 in front: the same', tel_link('+91-98765-43210') === 'tel:+919876543210' && tel_link('919876543210') === 'tel:+919876543210');
$check('a leading 0 is dropped', tel_link('09876543210') === 'tel:+919876543210');
$check('another country\'s number written with + is kept', tel_link('+971 50 123 4567') === 'tel:+971501234567');
$check('an Indian landline with its 0 code (044) gets +91 too', tel_link('044-2345 6789') === 'tel:+914423456789');
$check('anything else is passed on as its digits', tel_link('2345 6789') === 'tel:23456789');
echo "\n$ok passed, $bad failed\n";
