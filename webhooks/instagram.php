<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Repositories\EventRepository;
use App\Repositories\RuleRepository;
use App\Repositories\SettingsRepository;
use App\Services\InstagramAutomationService;
use App\Services\MetaInstagramClient;
use App\Support\Database;
use App\Support\Env;
use App\Support\Logger;

header('Content-Type: application/json');

try {
    $pdo = Database::connection();
    $service = new InstagramAutomationService(
        new SettingsRepository($pdo),
        new RuleRepository($pdo),
        new EventRepository($pdo),
        MetaInstagramClient::fromEnv()
    );

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? null;
        $verifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? null;
        $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? null;

        if ($mode === 'subscribe' && is_string($verifyToken) && hash_equals(Env::get('META_VERIFY_TOKEN'), $verifyToken)) {
            header('Content-Type: text/plain');
            echo (string) $challenge;
            exit;
        }

        Logger::warning('Instagram webhook verification failed.', [
            'mode' => $mode,
            'provided_token' => $verifyToken,
        ]);

        http_response_code(403);
        echo json_encode(['error' => 'Invalid verification token.']);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $rawBody = is_string($rawBody) ? $rawBody : '';
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

    if (!$service->validateSignature($rawBody, is_string($signatureHeader) ? $signatureHeader : null)) {
        Logger::warning('Instagram webhook signature validation failed.');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid webhook signature.']);
        exit;
    }

    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        Logger::warning('Instagram webhook payload was not valid JSON.', ['raw_body' => $rawBody]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload.']);
        exit;
    }

    $result = $service->processWebhookPayload($payload, $rawBody);
    echo json_encode(['status' => 'ok'] + $result);
} catch (Throwable $throwable) {
    Logger::error('Instagram webhook endpoint crashed.', ['message' => $throwable->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Webhook processing failed.', 'message' => $throwable->getMessage()]);
}
