<?php
declare(strict_types=1);

namespace Eshcol\Ats\Controllers;

use Eshcol\Ats\Audit;
use Eshcol\Ats\Auth;
use Eshcol\Ats\Http;
use Eshcol\Ats\Services\Migrator;
use Eshcol\Ats\View;

/**
 * First-run setup on the live server, no command line needed: builds the database
 * and creates the owner account. Needs the one-time setup code from the private
 * config file, and switches itself off once an account exists.
 */
final class SetupController extends Controller
{
    public function show(): never
    {
        $this->guard();
        echo View::render('auth/setup-install', ['error' => null, 'old' => []], 'layout/bare');
        exit;
    }

    public function install(): never
    {
        $this->guard();
        $old = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => mb_strtolower(trim((string) ($_POST['email'] ?? '')))];
        $token = (string) ($this->app->config['app']['setup_token'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        $error = match (true) {
            strlen($token) < 16 || !hash_equals($token, trim((string) ($_POST['token'] ?? ''))) => 'That setup code is not right. It is in the config file, under setup_token.',
            $old['name'] === '' => 'Enter your name.',
            !filter_var($old['email'], FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
            !\Eshcol\Ats\Services\Mailer::deliverable(mb_strtolower($old['email'])) => 'That email address\'s domain can\'t receive email. Check it for a typo.',
            ($p = Auth::validPassword($pass)) !== null => $p,
            $pass !== (string) ($_POST['confirm'] ?? '') => 'The two passwords do not match.',
            default => null,
        };
        if ($error) {
            echo View::render('auth/setup-install', ['error' => $error, 'old' => $old], 'layout/bare');
            exit;
        }
        $applied = (new Migrator($this->app))->run();
        $id = $this->db->insert('users', ['name' => $old['name'], 'email' => $old['email'], 'role' => 'owner', 'password_hash' => password_hash($pass, PASSWORD_ARGON2ID)]);
        Audit::log('installed', 'user', $id, ['migrations' => count($applied)]);
        Http::flash('ok', 'Eshcol Recruit is set up. Sign in to switch on two-step sign-in with your phone.');
        Http::redirect('/login');
    }

    /** Setup is only reachable while no account exists. */
    private function guard(): void
    {
        try {
            $this->db->pdo();
        } catch (\Throwable) {
            echo View::render('auth/setup-install', ['error' => 'The app cannot reach its database. Check the database name, user and password in eshcol-ats-config.php, and that the user has been added to the database in cPanel with All Privileges.', 'old' => []], 'layout/bare');
            exit;
        }
        $m = new Migrator($this->app);
        if ($m->isInstalled() && (int) $this->db->col('SELECT COUNT(*) FROM users') > 0) {
            Http::abort(404);
        }
    }
}
