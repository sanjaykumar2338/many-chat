<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Env;

if (Auth::check()) {
    redirect('index.php');
}

$error = flash('error');

if (is_post_request()) {
    try {
        Csrf::validate($_POST['_csrf'] ?? null);

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($username, $password)) {
            flash('success', 'Welcome back.');
            redirect('index.php');
        }

        $error = 'Invalid login details.';
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$isConfigured = Env::get('ADMIN_USERNAME') !== ''
    && (Env::get('ADMIN_PASSWORD_HASH') !== '' || Env::get('ADMIN_PASSWORD') !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instagram Comment DM MVP Login</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-panel">
        <h1>Instagram Comment DM MVP</h1>
        <p class="muted">Sign in to manage keyword rules, settings, and webhook logs.</p>

        <?php if (!$isConfigured): ?>
            <div class="notice error">
                Set <code>ADMIN_USERNAME</code> and either <code>ADMIN_PASSWORD_HASH</code> or <code>ADMIN_PASSWORD</code> in <code>.env</code> before using the admin.
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="notice error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">

            <label>
                <span>Username</span>
                <input type="text" name="username" autocomplete="username" required>
            </label>

            <label>
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>

            <button type="submit">Sign In</button>
        </form>
    </section>
</main>
</body>
</html>
