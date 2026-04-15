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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/login.css">
</head>
<body class="login-page">
<div class="container py-4 py-lg-5">
    <main class="min-vh-100 d-flex align-items-center">
        <div class="row g-4 g-xl-5 align-items-center w-100">
            <section class="col-lg-7 order-2 order-lg-1" aria-labelledby="login-title">
                <div class="login-hero pe-xl-4">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="login-brand-mark">
                            <img
                                class="img-fluid rounded-2"
                                src="../assets/inst-automation-bot-icon-1024.png"
                                alt="Inst Automation Bot app icon">
                        </div>
                        <div>
                            <p class="hero-kicker mb-1">Inst Automation Bot</p>
                            <p class="text-secondary mb-0 small">Instagram comment automation admin</p>
                        </div>
                    </div>

                    <h1 id="login-title" class="display-5 fw-semibold text-dark mb-3">Keep comment replies on cue.</h1>
                    <p class="hero-copy mb-4">Match post prompts, send official private replies, and review delivery history without digging through webhook noise.</p>

                    <div class="feature-list" aria-label="Highlights">
                        <div class="feature-item">
                            <div class="feature-badge">01</div>
                            <div>
                                <h2>Prompt-aware rules</h2>
                                <p>Only answer when the comment matches the keyword the post actually asked for.</p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-badge">02</div>
                            <div>
                                <h2>Meta-first delivery</h2>
                                <p>Send private replies through the official workflow tied to the original comment.</p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-badge">03</div>
                            <div>
                                <h2>Webhook visibility</h2>
                                <p>See recent activity, retries, skips, and delivery failures in one place.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="col-lg-5 order-1 order-lg-2">
                <div class="card border-0 shadow-sm login-card">
                    <div class="card-body p-4 p-lg-5">
                        <p class="text-uppercase small fw-semibold text-primary mb-2">Admin Access</p>
                        <h2 class="h1 mb-2">Sign in</h2>
                        <p class="text-secondary mb-4">Manage rules, sending settings, and recent activity for the connected Instagram account.</p>

                        <?php if (!$isConfigured): ?>
                            <div class="alert alert-danger mb-3" role="alert">
                                Set <code>ADMIN_USERNAME</code> and either <code>ADMIN_PASSWORD_HASH</code> or <code>ADMIN_PASSWORD</code> in <code>.env</code> before using the admin.
                            </div>
                        <?php endif; ?>

                        <?php if ($error !== null): ?>
                            <div class="alert alert-danger mb-3" role="alert"><?= e($error) ?></div>
                        <?php endif; ?>

                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="username">Username</label>
                                <input
                                    class="form-control form-control-lg"
                                    id="username"
                                    type="text"
                                    name="username"
                                    autocomplete="username"
                                    required>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="password">Password</label>
                                <input
                                    class="form-control form-control-lg"
                                    id="password"
                                    type="password"
                                    name="password"
                                    autocomplete="current-password"
                                    required>
                            </div>

                            <button class="btn btn-dark btn-lg w-100" type="submit">Sign In</button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 pt-3 small text-secondary">
        <span>Inst Automation Bot</span>
        <nav class="d-flex flex-wrap gap-3" aria-label="Public links">
            <a href="../privacy-policy.html">Privacy Policy</a>
            <a href="../terms-of-service.html">Terms of Service</a>
        </nav>
    </footer>
</div>
</body>
</html>
