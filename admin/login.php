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
    <title>Inst Automation Bot Login</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-intro" aria-labelledby="login-title">
        <p class="login-kicker">Inst Automation Bot</p>
        <h1 id="login-title">Answer the right comments fast.</h1>
        <p class="login-copy">Keep private replies tied to each post prompt, review every delivery, and catch webhook issues before they turn into missed leads.</p>

        <div class="login-points" aria-label="Highlights">
            <p>Prompt-aware matching for comment triggers</p>
            <p>Private replies sent through Meta’s official workflow</p>
            <p>Webhook logs that make failures easier to track</p>
        </div>

        <img
            class="login-art"
            src="../assets/inst-automation-bot-icon-1024.png"
            alt="Inst Automation Bot app icon">
    </section>

    <section class="login-panel">
        <p class="login-panel-kicker">Admin Access</p>
        <h2>Sign in</h2>
        <p class="muted">Manage rules, sending settings, and recent activity for the connected Instagram account.</p>

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
    <footer class="public-links public-links-login" aria-label="Public links">
        <a href="../privacy-policy.html">Privacy Policy</a>
        <a href="../terms-of-service.html">Terms of Service</a>
    </footer>
</main>
</body>
</html>
