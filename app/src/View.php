<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/** Renders PHP templates from /views. Every value printed into HTML goes through e(). */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout/app'): string
    {
        $file = App::get()->root . '/views/' . $template . '.php';
        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        })($file, $data);

        if ($layout === null) {
            return $content;
        }
        return self::render($layout, $data + ['content' => $content], null);
    }

    public static function page(string $template, array $data = []): never
    {
        echo self::render($template, $data);
        exit;
    }
}
