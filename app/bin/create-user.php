<?php
/**
 * Creates a user from the command line.
 *   php bin/create-user.php --dev-owner
 *       Local testing only: creates the owner account from dev_login in config.local.php.
 *   php bin/create-user.php "Full Name" email@example.com owner|recruiter
 *       Generates a strong temporary password and prints it once.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Eshcol\Ats\App;

$app = App::boot(cli: true);

if (($argv[1] ?? '') === '--dev-owner') {
    if (!$app->isLocal()) {
        exit("--dev-owner is only allowed when app.env is local.\n");
    }
    $name = 'Benjamin Basker';
    $email = $app->config['dev_login']['email'];
    $pass = $app->config['dev_login']['pass'];
    $role = 'owner';
    $show = false;
} else {
    [$name, $email, $role] = [$argv[1] ?? '', mb_strtolower($argv[2] ?? ''), $argv[3] ?? 'recruiter'];
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['owner', 'recruiter'], true)) {
        exit("Usage: php bin/create-user.php \"Full Name\" email owner|recruiter\n");
    }
    $pass = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
    $show = true;
}

if ($app->db->col('SELECT id FROM users WHERE email = ?', [$email])) {
    exit("A user with that email already exists.\n");
}
$app->db->insert('users', [
    'name' => $name,
    'email' => $email,
    'password_hash' => password_hash($pass, PASSWORD_ARGON2ID),
    'role' => $role,
]);
echo "Created $role: $name <$email>\n";
echo $show ? "Temporary password (shown once): $pass\n" : "Password is the dev_login password in config.local.php.\n";
