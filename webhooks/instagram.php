<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$verifyToken = \App\Support\Env::get('META_VERIFY_TOKEN');

/*
|--------------------------------------------------------------------------
| META WEBHOOK VERIFICATION
|--------------------------------------------------------------------------
|
| When Meta verifies the webhook it sends:
| hub.mode
| hub.verify_token
| hub.challenge
|
| If the token matches we must return hub.challenge
|
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? null;
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? null;

    if (
        $mode === 'subscribe'
        && $verifyToken !== ''
        && is_string($token)
        && hash_equals($verifyToken, $token)
    ) {
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo 'Invalid verification token';
    exit;
}

use App\Repositories\EventRepository;
use App\Repositories\RuleRepository;
use App\Repositories\SettingsRepository;
use App\Services\InstagramAutomationService;
use App\Services\MetaInstagramClient;
use App\Support\Database;
use App\Support\Env;
use App\Support\Logger;

function instagramWebhookRequestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            ksort($headers);
            return $headers;
        }
    }

    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$headerName] = $value;
    }

    ksort($headers);

    return $headers;
}

function instagramWebhookShowsLocalErrors(): bool
{
    $appEnv = strtolower(trim(Env::get('APP_ENV', 'production')));

    return in_array($appEnv, ['local', 'development', 'dev', 'test', 'testing'], true)
        || Env::bool('APP_DEBUG', false);
}

function instagramWebhookSecretPrefix(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    return substr($value, 0, 6);
}

header('Content-Type: application/json');

$rawBody = '';
$decodedPayload = null;
$requestHeaders = [];
$signatureValidation = null;

Logger::info('Instagram webhook env diagnostics.', [
    'env_file_path' => Env::loadedFilePath(),
    'meta_access_token_present' => Env::get('META_ACCESS_TOKEN') !== '',
    'meta_access_token_prefix' => instagramWebhookSecretPrefix(Env::get('META_ACCESS_TOKEN')),
    'instagram_access_token_present' => Env::get('INSTAGRAM_ACCESS_TOKEN') !== '',
    'instagram_access_token_prefix' => instagramWebhookSecretPrefix(Env::get('INSTAGRAM_ACCESS_TOKEN')),
    'meta_app_secret_present' => Env::get('META_APP_SECRET') !== '',
    'instagram_app_secret_present' => Env::get('INSTAGRAM_APP_SECRET') !== '',
    'meta_verify_token_present' => Env::get('META_VERIFY_TOKEN') !== '',
]);

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $rawBody = is_string($rawBody) ? $rawBody : '';
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
    $requestHeaders = instagramWebhookRequestHeaders();
    Logger::info('REAL_WEBHOOK_HIT', [
        'request_method' => $method,
        'raw_body_length' => strlen($rawBody),
        'raw_body_preview' => substr($rawBody, 0, 120),
    ]);
    Logger::info('Instagram webhook raw request received.', [
        'request_method' => $method,
        'request_headers' => $requestHeaders,
        'raw_body_length' => strlen($rawBody),
        'raw_body' => $rawBody,
    ]);
    $pdo = Database::connection();
    $service = new InstagramAutomationService(
        new SettingsRepository($pdo),
        new RuleRepository($pdo),
        new EventRepository($pdo),
        MetaInstagramClient::fromEnv()
    );
    $signatureValidation = $service->inspectSignatureValidation(
        $rawBody,
        is_string($signatureHeader) ? $signatureHeader : null
    );

    if (!$signatureValidation['valid']) {
        $logContext = $signatureValidation['diagnostics'] + [
            'request_headers' => $requestHeaders,
            'rejection_reason' => $signatureValidation['reason'],
            'message' => $signatureValidation['message'],
        ];

        if ((int) $signatureValidation['status_code'] >= 500) {
            Logger::error('Instagram webhook signature validation could not run.', $logContext);
        } else {
            Logger::warning('Instagram webhook signature validation failed.', $logContext);
        }

        http_response_code((int) $signatureValidation['status_code']);

        $error = (int) $signatureValidation['status_code'] >= 500
            ? 'Webhook signature validation is misconfigured.'
            : 'Invalid webhook signature.';
        $response = ['error' => $error];

        if (instagramWebhookShowsLocalErrors()) {
            $response['reason'] = $signatureValidation['reason'];
            $response['message'] = $signatureValidation['message'];
        }

        echo json_encode($response);
        exit;
    }

    if (in_array($signatureValidation['reason'], ['unsigned_test_bypass', 'local_test_bypass_missing_app_secret'], true)) {
        Logger::warning('Instagram webhook accepted a local test request without strict signature enforcement.', $signatureValidation['diagnostics'] + [
            'request_headers' => $requestHeaders,
        ]);
    }

    $decodedPayload = json_decode($rawBody, true);
    Logger::info('Instagram webhook decoded payload received.', [
        'decoded_payload' => is_array($decodedPayload) ? $decodedPayload : null,
        'json_last_error' => json_last_error_msg(),
    ]);

    if (!is_array($decodedPayload)) {
        Logger::warning('Instagram webhook payload was not valid JSON.', ['raw_body' => $rawBody]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload.']);
        exit;
    }

    $result = $service->processWebhookPayload($decodedPayload, $rawBody);
    echo json_encode(['status' => 'ok'] + $result);
} catch (Throwable $throwable) {
    Logger::error('Instagram webhook endpoint crashed.', [
        'exception_class' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'trace' => $throwable->getTraceAsString(),
        'raw_body' => $rawBody,
        'decoded_payload' => $decodedPayload,
        'request_headers' => $requestHeaders,
        'signature_validation' => $signatureValidation,
    ]);
    http_response_code(500);
    $response = ['error' => 'Webhook processing failed.'];

    if (instagramWebhookShowsLocalErrors()) {
        $response['exception'] = get_class($throwable);
        $response['message'] = $throwable->getMessage();
    }

    echo json_encode($response);
}
