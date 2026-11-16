<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\App;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Db;
use Eshcol\Ats\View;

abstract class Controller
{
    protected Db $db;

    public function __construct(protected App $app)
    {
        $this->db = $app->db;
    }

    protected function view(string $template, array $data = []): never
    {
        View::page($template, $data + ['user' => Auth::user()]);
    }

    /** Reads a nullable number from POST, returning null for blanks. */
    protected function num(string $key): ?float
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        return $v === '' || !is_numeric($v) ? null : (float) $v;
    }

    /**
     * A yearly salary in lakh (LPA) from POST. Accepts "6.5", "6.5 LPA", "₹6,00,000" or "600000":
     * anything over 500 is taken as rupees and turned into lakh. A figure that still makes no sense
     * gets an error in $errors[$key] (the database would otherwise cut it to 9999.99 without a word).
     */
    protected function lpa(string $key, array &$errors): ?float
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        $v = preg_replace('/(₹|\brs\.?|\binr\b|\blpa\b|l\.p\.a\.?|\blakhs?\b|\blacs?\b|per annum|\bp\.?a\.?|[\s,])/iu', '', mb_strtolower($raw));
        if (!is_numeric($v) || (float) $v < 0) {
            $errors[$key] = 'Enter the yearly salary in lakh, for example 6.5.';
            return null;
        }
        $v = (float) $v;
        if ($v > 500) {
            if ($v < 50000) {
                $errors[$key] = 'That does not look like a yearly salary in lakh. Enter it in lakh (for example 6.5) or as the yearly rupee amount, or clear the box.';
                return null;
            }
            $v = $v / 100000; // rupees a year
            if ($v > 500) {
                $errors[$key] = 'That is over ₹5 crore a year. Enter the yearly CTC in lakh, for example 6.5.';
                return null;
            }
        }
        return round($v, 2);
    }

    protected function str(string $key, int $max = 255): ?string
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    protected function findOr404(string $table, int $id): array
    {
        $row = $this->db->one("SELECT * FROM `$table` WHERE id = ?", [$id]);
        if (!$row) {
            \Eshcol\Ats\Http::abort(404);
        }
        return $row;
    }
}
