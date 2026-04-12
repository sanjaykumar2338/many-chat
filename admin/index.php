<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Repositories\EventRepository;
use App\Repositories\RuleRepository;
use App\Repositories\SettingsRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Env;

Auth::requireLogin();

$databaseError = null;
$editRule = null;
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['received', 'matched', 'sent', 'failed', 'skipped', 'no_match', 'bot_disabled', 'dry_run', 'invalid_payload'];

try {
    $pdo = Database::connection();
    $settingsRepository = new SettingsRepository($pdo);
    $ruleRepository = new RuleRepository($pdo);
    $eventRepository = new EventRepository($pdo);

    if (is_post_request()) {
        Csrf::validate($_POST['_csrf'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_settings') {
            $settingsRepository->save([
                'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
                'default_reply_enabled' => isset($_POST['default_reply_enabled']) ? 1 : 0,
                'default_reply_text' => trim((string) ($_POST['default_reply_text'] ?? '')),
                'test_mode' => isset($_POST['test_mode']) ? 1 : 0,
            ]);

            flash('success', 'Settings updated.');
            redirect('index.php');
        }

        if ($action === 'save_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $keyword = trim((string) ($_POST['keyword'] ?? ''));
            $matchType = (string) ($_POST['match_type'] ?? 'exact');
            $responseType = (string) ($_POST['response_type'] ?? 'text');
            $responseBody = trim((string) ($_POST['response_body'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($keyword === '' || $responseBody === '') {
                throw new RuntimeException('Keyword and response body are required.');
            }

            if (!in_array($matchType, ['exact', 'contains'], true)) {
                throw new RuntimeException('Invalid match type.');
            }

            if (!in_array($responseType, ['text', 'link', 'pdf_link'], true)) {
                throw new RuntimeException('Invalid response type.');
            }

            $payload = [
                'keyword' => function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword),
                'match_type' => $matchType,
                'response_type' => $responseType,
                'response_body' => $responseBody,
                'is_active' => $isActive,
            ];

            if ($ruleId > 0) {
                $ruleRepository->update($ruleId, $payload);
                flash('success', 'Rule updated.');
            } else {
                $ruleRepository->create($payload);
                flash('success', 'Rule created.');
            }

            redirect('index.php');
        }

        if ($action === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);

            if ($ruleId <= 0) {
                throw new RuntimeException('Rule ID is missing.');
            }

            $ruleRepository->delete($ruleId);
            flash('success', 'Rule deleted.');
            redirect('index.php');
        }
    }

    if (isset($_GET['edit'])) {
        $editRule = $ruleRepository->find((int) $_GET['edit']);
    }

    $settings = $settingsRepository->get();
    $rules = $ruleRepository->all();
    $events = $eventRepository->recent(in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : null, 100);
} catch (Throwable $throwable) {
    $databaseError = $throwable->getMessage();
    $settings = [
        'is_enabled' => 1,
        'default_reply_enabled' => 0,
        'default_reply_text' => '',
        'test_mode' => 0,
    ];
    $rules = [];
    $events = [];
}

$successMessage = flash('success');
$errorMessage = flash('error');

function status_class(string $status): string
{
    return match ($status) {
        'sent' => 'success',
        'failed', 'invalid_payload' => 'error',
        'dry_run', 'matched' => 'warning',
        'skipped' => 'warning',
        default => 'muted',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instagram Comment DM MVP Admin</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<header class="topbar">
    <div>
        <h1>Instagram Comment to DM MVP</h1>
        <p class="muted">Meta webhooks + private replies for Randy’s Instagram account.</p>
    </div>
    <nav class="topbar-actions">
        <span class="pill <?= (bool) $settings['is_enabled'] ? 'success' : 'muted' ?>">
            <?= (bool) $settings['is_enabled'] ? 'Bot enabled' : 'Bot disabled' ?>
        </span>
        <span class="pill <?= (bool) $settings['test_mode'] ? 'warning' : 'muted' ?>">
            <?= (bool) $settings['test_mode'] ? 'Test mode on' : 'Live sends on' ?>
        </span>
        <a class="secondary-link" href="logout.php">Log out</a>
    </nav>
</header>

<main class="page">
    <?php if ($successMessage !== null): ?>
        <div class="notice success"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="notice error"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($databaseError !== null): ?>
        <div class="notice error"><?= e($databaseError) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Meta Setup Snapshot</h2>
        <div class="meta-grid">
            <div>
                <strong>Webhook URL</strong>
                <div><?= e(Env::get('META_WEBHOOK_CALLBACK_URL', 'Set META_WEBHOOK_CALLBACK_URL in .env')) ?></div>
            </div>
            <div>
                <strong>Graph Host</strong>
                <div><?= e(Env::get('META_GRAPH_HOST', 'https://graph.instagram.com')) ?></div>
            </div>
            <div>
                <strong>API Version</strong>
                <div><?= e(Env::get('META_API_VERSION', 'v25.0')) ?></div>
            </div>
            <div>
                <strong>App Mode</strong>
                <div><?= e(Env::get('META_APP_MODE', 'development')) ?></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Bot Settings</h2>
        <form method="post" class="stack">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_settings">

            <label class="checkbox">
                <input type="checkbox" name="is_enabled" value="1" <?= checked((bool) $settings['is_enabled']) ?>>
                <span>Enable comment automation</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="test_mode" value="1" <?= checked((bool) $settings['test_mode']) ?>>
                <span>Test mode / dry run</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="default_reply_enabled" value="1" <?= checked((bool) $settings['default_reply_enabled']) ?>>
                <span>Default no-match reply</span>
            </label>

            <label>
                <span>Default reply text</span>
                <textarea name="default_reply_text" rows="3" placeholder="Only used when Default no-match reply is enabled."><?= e((string) $settings['default_reply_text']) ?></textarea>
            </label>

            <button type="submit">Save Settings</button>
        </form>
    </section>

    <section class="grid-two">
        <div class="panel">
            <h2><?= $editRule !== null ? 'Edit Rule' : 'Create Rule' ?></h2>
            <form method="post" class="stack">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="rule_id" value="<?= e((string) ($editRule['id'] ?? '')) ?>">

                <label>
                    <span>Keyword</span>
                    <input type="text" name="keyword" placeholder="place" value="<?= e((string) ($editRule['keyword'] ?? '')) ?>" required>
                </label>

                <label>
                    <span>Match type</span>
                    <select name="match_type">
                        <option value="exact" <?= selected('exact', (string) ($editRule['match_type'] ?? 'exact')) ?>>Exact match</option>
                        <option value="contains" <?= selected('contains', (string) ($editRule['match_type'] ?? 'exact')) ?>>Contains match</option>
                    </select>
                </label>

                <label>
                    <span>Response type</span>
                    <select name="response_type">
                        <option value="text" <?= selected('text', (string) ($editRule['response_type'] ?? 'text')) ?>>Text</option>
                        <option value="link" <?= selected('link', (string) ($editRule['response_type'] ?? 'text')) ?>>Link</option>
                        <option value="pdf_link" <?= selected('pdf_link', (string) ($editRule['response_type'] ?? 'text')) ?>>PDF link</option>
                    </select>
                </label>

                <label>
                    <span>Response body</span>
                    <textarea name="response_body" rows="4" placeholder="https://example.com/guide.pdf" required><?= e((string) ($editRule['response_body'] ?? '')) ?></textarea>
                </label>

                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1" <?= checked((bool) ($editRule['is_active'] ?? true)) ?>>
                    <span>Rule is active</span>
                </label>

                <div class="button-row">
                    <button type="submit"><?= $editRule !== null ? 'Update Rule' : 'Create Rule' ?></button>
                    <?php if ($editRule !== null): ?>
                        <a class="secondary-link" href="index.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Rules</h2>
            <?php if ($rules === []): ?>
                <p class="muted">No rules yet. Create the first keyword trigger on the left.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Match</th>
                            <th>Response</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td><strong><?= e((string) $rule['keyword']) ?></strong></td>
                                <td><?= e((string) $rule['match_type']) ?></td>
                                <td><?= e(truncate_text((string) $rule['response_body'], 48)) ?></td>
                                <td>
                                    <span class="pill <?= (bool) $rule['is_active'] ? 'success' : 'muted' ?>">
                                        <?= (bool) $rule['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a class="secondary-link" href="?edit=<?= e((string) $rule['id']) ?>">Edit</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this rule?');">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                        <input type="hidden" name="action" value="delete_rule">
                                        <input type="hidden" name="rule_id" value="<?= e((string) $rule['id']) ?>">
                                        <button type="submit" class="danger-link">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="section-head">
            <div>
                <h2>Event Log</h2>
                <p class="muted">Recent comment deliveries, matches, send attempts, and failures.</p>
            </div>

            <form method="get" class="filter-form">
                <label>
                    <span>Status</span>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $allowedStatus): ?>
                            <option value="<?= e($allowedStatus) ?>" <?= selected($allowedStatus, $statusFilter) ?>>
                                <?= e($allowedStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Created</th>
                    <th>Comment</th>
                    <th>Prompt</th>
                    <th>User</th>
                    <th>Rule</th>
                    <th>Status</th>
                    <th>Duplicates</th>
                    <th>Payloads</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($events === []): ?>
                    <tr>
                        <td colspan="8" class="empty-state">No events logged yet.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= e((string) $event['created_at']) ?></td>
                        <td>
                            <div><strong><?= e((string) ($event['comment_id'] ?? 'n/a')) ?></strong></div>
                            <div class="muted"><?= e(truncate_text((string) $event['comment_text'], 72)) ?></div>
                            <div class="muted">normalized: <?= e((string) ($event['normalized_comment_text'] ?: '-')) ?></div>
                        </td>
                        <td>
                            <div><strong><?= e((string) ($event['prompt_keyword'] ?: '-')) ?></strong></div>
                            <div class="muted"><?= e(truncate_text((string) $event['caption_text'], 72)) ?></div>
                        </td>
                        <td>
                            <div><?= e((string) ($event['instagram_username'] ?: $event['instagram_user_id'] ?: 'unknown')) ?></div>
                        </td>
                        <td><?= e((string) ($event['matched_keyword'] ?: '-')) ?></td>
                        <td>
                            <span class="pill <?= status_class((string) $event['status']) ?>">
                                <?= e((string) $event['status']) ?>
                            </span>
                            <?php if ((string) $event['skip_reason'] !== ''): ?>
                                <div class="muted"><?= e((string) $event['skip_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) max(0, ((int) $event['delivery_count']) - 1)) ?></td>
                        <td>
                            <?php if (
                                (string) $event['reply_payload'] !== ''
                                || (string) $event['api_response'] !== ''
                                || (string) $event['webhook_payload'] !== ''
                                || (string) $event['caption_text'] !== ''
                            ): ?>
                                <details>
                                    <summary>View</summary>
                                    <?php if ((string) $event['caption_text'] !== ''): ?>
                                        <div class="details-block">
                                            <strong>Caption text</strong>
                                            <pre><?= e((string) $event['caption_text']) ?></pre>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((string) $event['reply_payload'] !== ''): ?>
                                        <div class="details-block">
                                            <strong>Reply payload</strong>
                                            <pre><?= e((string) $event['reply_payload']) ?></pre>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((string) $event['api_response'] !== ''): ?>
                                        <div class="details-block">
                                            <strong>API response / note</strong>
                                            <pre><?= e((string) $event['api_response']) ?></pre>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((string) $event['webhook_payload'] !== ''): ?>
                                        <div class="details-block">
                                            <strong>Webhook payload</strong>
                                            <pre><?= e((string) $event['webhook_payload']) ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </details>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
