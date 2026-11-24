<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * "Go back to where I came from" (Benjamin's standing rule, 24 Nov 2026): a page opened from another page
 * (My day, a position, Calendar, Emails, Messages, a search, the Today panel, a list) returns to that page
 * after any save or action, and its Back button names that page and links to it. Never a fixed list.
 *
 * Done once, here, instead of form by form:
 *  - Links: every internal link in a page's content carries ?back=<this page> (View does it as it renders).
 *    The header menu is not touched (a menu click is a fresh start), nor Back buttons themselves.
 *  - Forms: on a page that has a back, every POST form gets hidden _back (the origin) and _here (this page);
 *    GET forms (filters) keep ?back so filtering doesn't lose it.
 *  - Redirects: Http::redirect() runs every POST redirect through afterPost(). An action that would reload the
 *    page it was done on goes back to the origin instead; one that stays for a next step keeps the back.
 *  - The Back button: bar() names the origin ("‹ Back to My day", "‹ Back to Accounting Associate").
 * back is only ever a same-site path ("/roles/12#app-5"), so it can never send anyone to another site.
 */
final class Back
{
    public const MAX = 400;

    /** Pages where you work through many actions in a row: an action there stays on the page (keeping its back). */
    private const WORKING = '#^/(roles/\d+|calendar|emails$|candidates$|messages$|clients/\d+/?$|settings|cv-requests|details-requests|joinings|placements|reports)#';

    /** Pages a back may never point to (sign-in, the client portal, candidates' own links, downloads). */
    private const NEVER = '#^/(login|logout|setup|portal|unsubscribe|health)(/|$|\?)|^/(u|i|q|s|unsub)/#';

    /** A same-site path we can send someone back to, or null. Blocks "//host", "/\host", schemes, control characters. */
    public static function safe(mixed $p): ?string
    {
        if (!is_string($p)) {
            return null;
        }
        $p = trim($p);
        if ($p === '' || strlen($p) > self::MAX || !preg_match('#^/(?![/\\\\])[^\s\\\\<>"\'`\x00-\x1f\x7f]*$#', $p) || preg_match(self::NEVER, $p)) {
            return null;
        }
        return $p;
    }

    /** Where this page was opened from: ?back=, else (a candidate opened from a position) ?role=. */
    public static function current(): ?string
    {
        if ($b = self::safe($_GET['back'] ?? null)) {
            return $b;
        }
        if (self::isPost() && ($b = self::safe($_POST['_back'] ?? null))) {
            return $b; // a form shown again after a POST (e.g. to fix a mistake) keeps its origin
        }
        $role = (int) ($_GET['role'] ?? 0);
        return $role > 0 && preg_match('#^/candidates/\d+$#', Http::path()) ? "/roles/$role" : null;
    }

    /** The URL of this page, to hand on as the next page's back (shortened if a chain of backs grows too long). */
    public static function here(): string
    {
        if (self::isPost() && ($h = self::safe($_POST['_here'] ?? null))) {
            return $h;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = self::safe($uri) ?? Http::path();
        if (strlen($uri) > 250) {
            $uri = self::withoutBack($uri);
        }
        return strlen($uri) > 250 ? Http::path() : $uri;
    }

    private static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** $url with ?back=$back added (before any #fragment). Not added when already there, or when $url is the origin itself. */
    public static function with(string $url, ?string $back): string
    {
        if ($back === null || !str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[?&]back=/', $url)) {
            return $url;
        }
        if (self::pathOf($url) === self::pathOf($back)) {
            return $url;
        }
        [$main, $frag] = array_pad(explode('#', $url, 2), 2, null);
        return $main . (str_contains($main, '?') ? '&' : '?') . 'back=' . rawurlencode($back) . ($frag !== null ? "#$frag" : '');
    }

    public static function pathOf(string $url): string
    {
        return rtrim((string) parse_url('http://x' . strtok($url, '#'), PHP_URL_PATH), '/') ?: '/';
    }

    private static function withoutBack(string $url): string
    {
        [$main, $frag] = array_pad(explode('#', $url, 2), 2, null);
        $q = (string) parse_url('http://x' . $main, PHP_URL_QUERY);
        parse_str($q, $args);
        unset($args['back']);
        return self::pathOf($main) . ($args ? '?' . http_build_query($args) : '') . ($frag !== null ? "#$frag" : '');
    }

    /**
     * Where a POST should land. $to is where the action itself wanted to go.
     *  - Not from a page with an origin, or off-site: unchanged.
     *  - Back to the page it was done on (a "detail" page such as a candidate, shortlist or email): the origin,
     *    unless it failed (an error to fix there) or needs a next step there (?round=, ?review=...): then stay, keeping the back.
     *  - To a general list ("/candidates", "/", "/emails?tab=held"): the origin.
     *  - On to another page for a next step: that page, carrying the origin.
     */
    public static function afterPost(string $to, ?string $back, ?string $here, bool $failed): string
    {
        if ($back === null || !str_starts_with($to, '/') || str_starts_with($to, '//')) {
            return $to;
        }
        $tp = self::pathOf($to);
        if ($tp === self::pathOf($back)) {
            return $to; // already going to the origin (with its own #row, say)
        }
        $hp = $here !== null ? self::pathOf($here) : null;
        if ($hp !== null && $tp === $hp) {
            $nextStep = (bool) preg_match('/\?(?!back=)./', strtok($to, '#') . '');
            if ($failed || $nextStep || preg_match(self::WORKING, $hp)) {
                return self::with($to, $back);
            }
            return $back;
        }
        if (!preg_match('#^/[a-z-]+/\d+#', $tp)) {
            return $back; // a general list or My day: the origin is where they came from
        }
        return self::with($to, $back);
    }

    /** What to call a page on its Back button: "My day", the position's title, the candidate's name... */
    public static function label(string $back): string
    {
        $p = self::pathOf($back);
        $db = App::get()->db;
        $name = function (string $sql, int $id, string $else) use ($db): string {
            try {
                $v = trim((string) $db->col($sql, [$id]));
            } catch (\Throwable) {
                $v = '';
            }
            return $v !== '' ? $v : $else;
        };
        return match (true) {
            $p === '/' => 'My day',
            (bool) preg_match('#^/roles/(\d+)#', $p, $m) => $name('SELECT title FROM roles WHERE id = ?', (int) $m[1], 'the position'),
            (bool) preg_match('#^/candidates/(\d+)#', $p, $m) => $name('SELECT full_name FROM candidates WHERE id = ?', (int) $m[1], 'the candidate'),
            (bool) preg_match('#^/clients/(\d+)#', $p, $m) => $name('SELECT name FROM clients WHERE id = ?', (int) $m[1], 'the client'),
            (bool) preg_match('#^/shortlists/\d+#', $p) => 'the shortlist',
            (bool) preg_match('#^/emails/\d+#', $p) => 'the email',
            default => [
                '/calendar' => 'Calendar', '/emails' => 'Emails', '/messages' => 'Messages', '/search' => 'Search results',
                '/candidates' => 'Candidates', '/roles' => 'Positions', '/clients' => 'Clients', '/joinings' => 'Joinings',
                '/placements' => 'Placements', '/reports' => 'Reports', '/cv-requests' => 'CV requests', '/details-requests' => 'Details requests',
                '/settings' => 'Settings', '/upload' => 'Upload',
            ][$p] ?? (str_starts_with($p, '/settings') ? 'Settings' : 'the previous page'),
        };
    }

    /** The Back button: to the origin when there is one, else $href/$label (the page's usual parent). */
    public static function bar(string $href, string $label, string $extra = ''): string
    {
        $b = self::current();
        $a = $b !== null
            ? '<a class="back-btn" href="' . htmlspecialchars($b) . '">‹ Back to ' . htmlspecialchars(self::label($b)) . '</a>'
            : '<a class="back-btn" href="' . htmlspecialchars($href) . '">‹ ' . htmlspecialchars($label) . '</a>';
        return '<div class="back-bar">' . $a . $extra . '</div>';
    }

    /**
     * Applied to a page's content as it renders (staff pages only; not the header menu).
     * Links get ?back=<this page>; same-page links keep this page's own back; POST forms get _back/_here.
     * A page with an origin but no Back button of its own gets one at the top.
     */
    public static function filter(string $html, bool $addBar = true, ?string $hereOverride = null): string
    {
        $here = $hereOverride ?? self::here();
        $herePath = self::pathOf($here);
        $own = self::current();
        // The section a link sits in (the nearest section, card, row... with an id before it) goes on its back as a
        // #fragment, so coming back lands on that section, not the top (24 Nov 2026: "back to the section which
        // directed to the new page"). The exact scroll spot, kept by app.js, wins when there is one.
        $section = null;
        $base = strtok($here, '#');
        $html = (string) preg_replace_callback('#<(?:section|div|tr|li|details|article|table|tbody|h2|h3)\b[^>]*?\bid="([A-Za-z][\w-]*)"[^>]*>|<a\b([^>]*?)\bhref="([^"]*)"([^>]*)>#i', function ($mm) use (&$section, $base, $here, $herePath, $own) {
            if (($mm[1] ?? '') !== '') {
                $section = $mm[1];
                return $mm[0];
            }
            $m = [$mm[0], $mm[2], $mm[3], $mm[4]];
            $here = $section !== null ? "$base#$section" : $here;
            $attrs = $m[1] . ' ' . $m[3];
            $href = html_entity_decode($m[2], ENT_QUOTES);
            if (preg_match('/\bback-btn\b|\bdata-no-back\b|\bdownload\b/', $attrs) || str_contains($href, 'back=')) {
                return $m[0];
            }
            if (str_starts_with($href, '?')) {
                $href = $herePath . $href;
            }
            if (!str_starts_with($href, '/') || str_starts_with($href, '//') || self::safe($href) === null) {
                return $m[0];
            }
            if (self::pathOf($href) === '/') {
                return $m[0]; // My day is home: no back needed
            }
            $same = self::pathOf($href) === $herePath;
            if (!$same && preg_match('#\.(csv|pdf|docx?|xlsx?|zip|png|jpe?g)$|/(download|data|logo|file|cv)(/|$)|/documents/\d+$#', self::pathOf($href))) {
                return $m[0]; // files: nothing to come back from
            }
            $new = $same ? self::with($href, $own) : self::with($href, $here);
            return $new === $href && !str_starts_with($m[2], '?') ? $m[0] : '<a' . $m[1] . 'href="' . htmlspecialchars($new) . '"' . $m[3] . '>';
        }, $html);
        if ($own !== null) {
            $hidden = '<input type="hidden" name="_back" value="' . htmlspecialchars($own) . '"><input type="hidden" name="_here" value="' . htmlspecialchars($here) . '">';
            $get = '<input type="hidden" name="back" value="' . htmlspecialchars($own) . '">';
            $html = (string) preg_replace_callback('#<form\b[^>]*>#i', function ($m) use ($hidden, $get, $here, $herePath) {
                $isPost = (bool) preg_match('/\bmethod="post"/i', $m[0]);
                if (!$isPost && preg_match('/\baction="(?!\/)[^"]|\baction="\/\//i', $m[0])) {
                    return $m[0];
                }
                if (!$isPost && preg_match('/\baction="(\/[^"]*)"/i', $m[0], $act) && self::pathOf(html_entity_decode($act[1])) !== $herePath) {
                    return $m[0] . '<input type="hidden" name="back" value="' . htmlspecialchars($here) . '">'; // a search to another page: back to this one
                }
                return $m[0] . ($isPost ? $hidden : $get);
            }, $html);
            if ($addBar && !str_contains($html, 'class="back-bar"')) {
                $html = '<div class="back-bar"><a class="back-btn" href="' . htmlspecialchars($own) . '">‹ Back to ' . htmlspecialchars(self::label($own)) . '</a></div>' . $html;
            }
        }
        return $html;
    }

    /** True on staff pages that should be filtered: signed in, a GET, not the client portal or candidates' links. */
    public static function applies(): bool
    {
        return Auth::user() !== null && self::safe(Http::path()) !== null && !preg_match(self::NEVER, Http::path());
    }
}
