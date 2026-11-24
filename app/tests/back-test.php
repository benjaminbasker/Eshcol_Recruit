<?php
/**
 * "Go back to where I came from" (Benjamin's standing rule, 24 Nov 2026), the rules in \Eshcol\Ats\Back.
 * From My day he pressed Check on a contact change, chose "Use the new details", and landed on Candidates.
 * Pages are checked for real in smoke-test.php; this checks the rules themselves. No data. Run: php tests/back-test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Back as B;

App::boot(cli: true);
$ok = 0;
$bad = 0;
$check = function (string $what, bool $cond) use (&$ok, &$bad): void {
    $cond ? $ok++ : $bad++;
    echo ($cond ? '  ok   ' : '  FAIL ') . "$what\n";
};

echo "Only ever a page of this site\n";
foreach (['/', '/roles/12#app-5', '/candidates?removal=1', '/calendar?week=2026-11-23'] as $p) {
    $check("accepted: $p", B::safe($p) === $p);
}
foreach (['//evil.example', '/\\evil.example', 'https://evil.example/', 'javascript:alert(1)', '/x" onmouseover="y', "/a\nLocation: //e", '', '/portal', '/login', '/u/abc/remove', '/' . str_repeat('a', 500)] as $p) {
    $check('refused: ' . json_encode($p), B::safe($p) === null);
}
$check('anything not text: refused', B::safe(['/']) === null && B::safe(null) === null);

echo "\nAdding the back to a link\n";
$check('a plain link', B::with('/candidates/682', '/') === '/candidates/682?back=%2F');
$check('keeps its own query and #anchor', B::with('/candidates/682?tab=cv#notes', '/roles/3') === '/candidates/682?tab=cv&back=%2Froles%2F3#notes');
$check('not twice', B::with('/candidates/682?back=%2F', '/roles/3') === '/candidates/682?back=%2F');
$check('not on a link to the origin itself', B::with('/roles/3#app-9', '/roles/3?f=1') === '/roles/3#app-9');
$check('not on a link to another site', B::with('https://wa.me/91', '/') === 'https://wa.me/91');

echo "\nAfter an action (the redirect)\n";
$cand = '/candidates/682';
$check('Laxmi Singh: "Use the new details" from My day goes back to My day', B::afterPost($cand, '/', $cand, false) === '/');
$check('…also when the action wanted a spot on the page (#interviews, #notes)', B::afterPost("$cand#interviews", '/', $cand, false) === '/');
$check('opened from Calendar: back to the same Calendar week', B::afterPost($cand, '/calendar?week=2026-11-23', $cand, false) === '/calendar?week=2026-11-23');
$check('opened from a position: back to that position', B::afterPost("$cand#decide-9", '/roles/3', $cand, false) === '/roles/3');
$check('a mistake to fix: stays, keeping the back', B::afterPost("$cand#delete", '/', $cand, true) === "$cand?back=%2F#delete");
$check('a next step on the same page (book round 2): stays, keeping the back', B::afterPost("$cand?round=2&app=9#interviews", '/', $cand, false) === "$cand?round=2&app=9&back=%2F#interviews");
$check('a list it would fall back to (/candidates after a delete): the origin instead', B::afterPost('/candidates', '/', $cand, false) === '/');
$check('an emails list (held tab): the origin', B::afterPost('/emails?tab=held', '/', '/emails/41', false) === '/');
$check('on to another page for a next step (a new shortlist): goes there, carrying the back', B::afterPost('/shortlists/7', '/', '/roles/3', false) === '/shortlists/7?back=%2F');
$check('the next application to review from My day: carries the back', B::afterPost('/candidates/90?review=12&from=dashboard#review-12', '/', $cand, false) === '/candidates/90?review=12&from=dashboard&back=%2F#review-12');
$check('already going to the origin (its row): kept as it is', B::afterPost('/roles/3#app-9', '/roles/3', $cand, false) === '/roles/3#app-9');
$check('a working page (a position opened from My day): an action stays there, keeping the back', B::afterPost('/roles/3#tab-pipeline', '/', '/roles/3', false) === '/roles/3?back=%2F#tab-pipeline');
$check('no origin: unchanged', B::afterPost($cand, null, $cand, false) === $cand);
$check('an off-site redirect (WhatsApp): unchanged', B::afterPost('https://wa.me/91?text=x', '/', $cand, false) === 'https://wa.me/91?text=x');

echo "\nThe Back button names the page\n";
$check('"/" is My day', B::label('/') === 'My day');
$check('/calendar?week=… is Calendar', B::label('/calendar?week=2026-11-23') === 'Calendar');
$check('/emails?tab=held is Emails', B::label('/emails?tab=held') === 'Emails');
$check('/search?q=… is Search results', B::label('/search?q=tally') === 'Search results');
$check('an unknown position: "the position"', B::label('/roles/999999999') === 'the position');

echo "\nLinks and forms on a page (the filter)\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_GET = [];
$html = B::filter('<a href="/candidates/682#their-details">Check</a><a class="back-btn" href="/roles">x</a><a href="/">My day</a><a href="https://x.example/">ext</a><a href="/candidates/5/cv/download">cv</a><form method="post" action="/x"></form>');
$check('My day: a link to a candidate carries back=/', str_contains($html, 'href="/candidates/682?back=%2F#their-details"'));
$check('…not Back buttons, My day itself, other sites or downloads', str_contains($html, 'class="back-btn" href="/roles"') && str_contains($html, 'href="/">My day') && str_contains($html, 'href="https://x.example/"') && str_contains($html, 'href="/candidates/5/cv/download"'));
$check('…and My day (no origin) adds nothing to its forms', !str_contains($html, '_back'));
$_SERVER['REQUEST_URI'] = '/candidates/682?back=%2F';
$_GET = ['back' => '/'];
$html = B::filter('<a href="?tab=cv">CV</a><a href="/roles/3">Accounting Associate</a><form method="post" action="/candidates/682/contact"></form><form method="get" action="/candidates/682"></form>');
$check('a candidate opened from My day: its forms carry _back=/ and _here', str_contains($html, 'name="_back" value="/"') && str_contains($html, 'name="_here" value="/candidates/682?back=%2F"'));
$check('…links within the page keep back=/', str_contains($html, 'href="/candidates/682?tab=cv&amp;back=%2F"'));
$check('…a link on to a position carries this page as its back', str_contains($html, 'href="/roles/3?back=%2Fcandidates%2F682%3Fback%3D%252F"'));
$check('…a page with no Back button of its own gets one, naming My day', str_contains($html, '<a class="back-btn" href="/">‹ Back to My day</a>'));

echo "\nThe section the link was in (24 Nov 2026: \"back to the section which directed to the new page\")\n";
$_SERVER['REQUEST_URI'] = '/';
$_GET = [];
$html = B::filter('<section class="card" id="waiting"><div data-todo-group="x" id="waiting-contact-changes-to-approve"><a href="/candidates/682">Check</a></div></section><section id="today"><a href="/candidates/9#interviews">How did it go?</a></section>');
$check('My day › Contact changes › Check: back=/#waiting-contact-changes-to-approve', str_contains($html, 'href="/candidates/682?back=%2F%23waiting-contact-changes-to-approve"'));
$check('the Today panel: back=/#today', str_contains($html, 'href="/candidates/9?back=%2F%23today#interviews"'));
$check('after the action, back to that section', B::afterPost('/candidates/682', '/#waiting-contact-changes-to-approve', '/candidates/682', false) === '/#waiting-contact-changes-to-approve');
$check('…and the Back button still says My day', B::label('/#today') === 'My day');

echo "\n$ok passed, $bad failed\n";
