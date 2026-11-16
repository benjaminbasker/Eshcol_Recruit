<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/**
 * Maps "METHOD /path/{id}" to a controller method. Every POST is CSRF-checked,
 * and every route needs a signed-in user unless it is marked public.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, bool $public = false): void
    {
        $this->routes[] = ['GET', $path, $handler, $public];
    }

    public function post(string $path, array $handler, bool $public = false): void
    {
        $this->routes[] = ['POST', $path, $handler, $public];
    }

    public function dispatch(App $app): void
    {
        $method = Http::method();
        $path = Http::path();
        foreach ($this->routes as [$m, $pattern, $handler, $public]) {
            if ($m !== $method) {
                continue;
            }
            // {token} is a long random link code; every other {name} is a number.
            $regex = '#^' . preg_replace(['#\{token\}#', '#\{(\w+)\}#'], ['([A-Za-z0-9_-]{20,64})', '(\d+)'], $pattern) . '$#';
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            // Candidate-facing pages wait quietly while an update is half-applied.
            if ($public && (str_starts_with($path, '/q/') || str_starts_with($path, '/s/') || str_starts_with($path, '/i/') || str_starts_with($path, '/portal') || str_starts_with($path, '/u/')) && (new \Eshcol\Ats\Services\Migrator($app))->pending()) {
                http_response_code(503);
                header('Retry-After: 600');
                echo View::render('questionnaire/message', ['heading' => 'Back in a few minutes', 'body' => 'This page is being updated. Please try your link again a little later; it will still work.'], 'layout/public');
                return;
            }
            // Public forms (candidate links, client portal, interview booking, sign-in): at most 30 submissions per
            // 10 minutes from one address (B16, 14 Nov 2026). Far above any real person; stops anyone hammering them.
            // Brevo's webhook and one-click unsubscribe are left out (the mail providers post those).
            if ($public && $method === 'POST' && !str_starts_with($path, '/hooks/') && !str_starts_with($path, '/unsub/')
                && !Http::throttle('post:' . ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . strtok(ltrim($path, '/'), '/'), 30, 600)) {
                http_response_code(429);
                header('Retry-After: 600');
                echo View::render('questionnaire/message', ['heading' => 'Too many tries', 'body' => 'Please wait a few minutes and try again.'], 'layout/public');
                return;
            }
            // Brevo's webhook carries its own long secret in the address instead of a form token (B1, 14 Nov 2026).
            if ($method === 'POST' && !str_starts_with($path, '/hooks/') && !str_starts_with($path, '/unsub/')) { // one-click unsubscribe is posted by the mail provider
                Http::verifyCsrf();
            }
            if (!$public) {
                $user = Auth::require();
                // New code may need database changes that aren't applied yet. Until they are,
                // every page shows the "Apply updates" screen, so nothing runs against an old database.
                if (!in_array($path, ['/settings/migrate', '/logout'], true)) {
                    $pending = (new \Eshcol\Ats\Services\Migrator($app))->pending();
                    if ($pending) {
                        http_response_code(503);
                        echo View::render('updates', ['pending' => $pending, 'owner' => $user['role'] === 'owner'], 'layout/bare');
                        return;
                    }
                }
            }
            [$class, $fn] = $handler;
            $args = array_map(fn ($v) => ctype_digit($v) ? (int) $v : $v, array_slice($match, 1));
            // A contractor reaches only their own positions and candidates (B6, 14 Nov 2026).
            if (!$public && !Access::allowsRoute(Auth::user() ?? [], $class, $fn, $args)) {
                Http::abort(403, 'This page is not part of the positions you work on.');
            }
            (new $class($app))->$fn(...$args);
            return;
        }
        Http::abort(404);
    }
}
