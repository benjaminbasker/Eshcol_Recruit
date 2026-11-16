<?php
declare(strict_types=1);

// With PHP's built-in server, let real files (CSS, JS) through untouched.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#(^|/)\.#', $path)) {
        http_response_code(403); // hidden files, as Apache does on the live server
        exit;
    }
    $file = __DIR__ . $path;
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;
use Eshcol\Ats\Http;
use Eshcol\Ats\Router;

$app = App::boot();

set_exception_handler(function (Throwable $e) use ($app): void {
    $id = substr(bin2hex(random_bytes(4)), 0, 8);
    @file_put_contents($app->storage('logs/app.log'), sprintf("[%s] %s %s: %s in %s:%d\n%s\n\n",
        date('c'), $id, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()), FILE_APPEND);
    http_response_code(500);
    $msg = $app->isLocal() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : "Something went wrong. Reference $id.";
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:system-ui;padding:40px;background:#F6F3EC;color:#320A67"><h1 style="font-family:Georgia">Something went wrong</h1><p>' . htmlspecialchars($msg) . '</p>';
});

Http::securityHeaders();
Http::startSession($app);

$router = new Router();
require dirname(__DIR__) . '/routes.php';
$router->dispatch($app);
