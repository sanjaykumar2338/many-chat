<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EventRepository;
use App\Repositories\RuleRepository;
use App\Repositories\SettingsRepository;
use App\Support\CaptionPromptParser;
use App\Support\Env;
use App\Support\Logger;
use PDOException;
use RuntimeException;

final class InstagramAutomationService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly RuleRepository $ruleRepository,
        private readonly EventRepository $eventRepository,
        private readonly MetaInstagramClient $metaClient
    ) {
    }

    public function validateSignature(string $rawBody, ?string $signatureHeader): bool
    {
        return $this->inspectSignatureValidation($rawBody, $signatureHeader)['valid'];
    }

    public function inspectSignatureValidation(string $rawBody, ?string $signatureHeader): array
    {
        $signatureHeader = is_string($signatureHeader) ? trim($signatureHeader) : null;
        $signatureHeader = $signatureHeader === '' ? null : $signatureHeader;

        $instagramAppSecret = trim(Env::get('INSTAGRAM_APP_SECRET'));
        $legacyAppSecret = trim(Env::get('META_APP_SECRET'));
        $appSecret = $instagramAppSecret !== '' ? $instagramAppSecret : $legacyAppSecret;
        $appSecretSource = $instagramAppSecret !== ''
            ? 'INSTAGRAM_APP_SECRET'
            : ($legacyAppSecret !== '' ? 'META_APP_SECRET' : '');

        $requireSignature = Env::bool('META_REQUIRE_SIGNATURE', $appSecret !== '');
        $allowUnsignedTests = Env::bool('INSTAGRAM_ALLOW_UNSIGNED_TESTS', false);
        $isLocalMode = $this->isLocalTestingMode();
        $hasSignatureHeader = $signatureHeader !== null;
        $appSecretPresent = $appSecret !== '';

        $diagnostics = [
            'has_signature_header' => $hasSignatureHeader,
            'signature_header' => $signatureHeader,
            'raw_body_present' => $rawBody !== '',
            'raw_body_length' => strlen($rawBody),
            'app_secret_present' => $appSecretPresent,
            'app_secret_source' => $appSecretSource,
            'meta_require_signature' => $requireSignature,
            'allow_unsigned_tests' => $allowUnsignedTests,
            'is_local_mode' => $isLocalMode,
            'computed_signature' => null,
            'validation_result' => false,
        ];

        if (!$appSecretPresent) {
            if ($allowUnsignedTests && $isLocalMode) {
                $diagnostics['validation_result'] = true;

                return [
                    'valid' => true,
                    'status_code' => 200,
                    'reason' => 'local_test_bypass_missing_app_secret',
                    'message' => 'Accepted webhook request in local/test mode because INSTAGRAM_ALLOW_UNSIGNED_TESTS is enabled and no app secret is configured.',
                    'diagnostics' => $diagnostics,
                ];
            }

            if (!$requireSignature) {
                $diagnostics['validation_result'] = true;

                return [
                    'valid' => true,
                    'status_code' => 200,
                    'reason' => 'signature_not_required',
                    'message' => 'Webhook signature validation is disabled by configuration.',
                    'diagnostics' => $diagnostics,
                ];
            }

            return [
                'valid' => false,
                'status_code' => 500,
                'reason' => 'missing_app_secret',
                'message' => 'Webhook signature validation requires INSTAGRAM_APP_SECRET or META_APP_SECRET, but neither value is configured.',
                'diagnostics' => $diagnostics,
            ];
        }

        if (!$hasSignatureHeader) {
            if ($allowUnsignedTests && $isLocalMode) {
                $diagnostics['validation_result'] = true;

                return [
                    'valid' => true,
                    'status_code' => 200,
                    'reason' => 'unsigned_test_bypass',
                    'message' => 'Accepted unsigned webhook request in local/test mode because INSTAGRAM_ALLOW_UNSIGNED_TESTS is enabled.',
                    'diagnostics' => $diagnostics,
                ];
            }

            if (!$requireSignature) {
                $diagnostics['validation_result'] = true;

                return [
                    'valid' => true,
                    'status_code' => 200,
                    'reason' => 'signature_not_required',
                    'message' => 'Webhook signature validation is disabled by configuration.',
                    'diagnostics' => $diagnostics,
                ];
            }

            return [
                'valid' => false,
                'status_code' => 401,
                'reason' => 'missing_signature_header',
                'message' => 'X-Hub-Signature-256 was missing from the webhook request.',
                'diagnostics' => $diagnostics,
            ];
        }

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return [
                'valid' => false,
                'status_code' => 401,
                'reason' => 'invalid_signature_header_format',
                'message' => 'X-Hub-Signature-256 did not use the expected sha256= format.',
                'diagnostics' => $diagnostics,
            ];
        }

        $expectedHash = hash_hmac('sha256', $rawBody, $appSecret);
        $diagnostics['computed_signature'] = 'sha256=' . $expectedHash;
        $diagnostics['validation_result'] = hash_equals($expectedHash, substr($signatureHeader, 7));

        if (!$diagnostics['validation_result']) {
            return [
                'valid' => false,
                'status_code' => 401,
                'reason' => 'signature_mismatch',
                'message' => 'The webhook signature did not match the computed HMAC for the configured app secret.',
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'valid' => true,
            'status_code' => 200,
            'reason' => 'signature_valid',
            'message' => 'Webhook signature matched the configured app secret.',
            'diagnostics' => $diagnostics,
        ];
    }

    public function processWebhookPayload(array $payload, string $rawBody): array
    {
        $results = [
            'processed' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'sent' => 0,
            'failed' => 0,
            'ignored' => 0,
            'invalid' => 0,
        ];

        if (($payload['object'] ?? '') !== 'instagram') {
            $results['ignored']++;
            return $results;
        }

        $commentEvents = $this->extractCommentEvents($payload);

        if ($commentEvents === []) {
            $results['ignored']++;
            return $results;
        }

        $processableEvents = [];
        $sampleEvents = [];
        $incompleteEvents = [];

        foreach ($commentEvents as $commentEvent) {
            if (($commentEvent['is_sample_test'] ?? false) === true) {
                $sampleEvents[] = $commentEvent;
                continue;
            }

            if (($commentEvent['is_automatable'] ?? false) === true) {
                $processableEvents[] = $commentEvent;
                continue;
            }

            $incompleteEvents[] = $commentEvent;
        }

        foreach ($sampleEvents as $commentEvent) {
            $normalizedText = CaptionPromptParser::normalize($commentEvent['comment_text']);

            try {
                $eventId = $this->eventRepository->createReceived([
                    'comment_id' => $commentEvent['comment_id'],
                    'media_id' => $commentEvent['media_id'],
                    'instagram_user_id' => $commentEvent['instagram_user_id'],
                    'instagram_username' => $commentEvent['instagram_username'],
                    'comment_text' => $commentEvent['comment_text'],
                    'normalized_comment_text' => $normalizedText !== '' ? $normalizedText : null,
                    'skip_reason' => 'meta_dashboard_sample',
                    'status' => 'sample_test',
                    'webhook_payload' => json_encode($commentEvent['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                $this->eventRepository->markSampleTest(
                    $eventId,
                    'Meta dashboard sample/test payload detected. Caption lookup and DM send skipped.'
                );
            } catch (PDOException $exception) {
                if (!$this->eventRepository->isDuplicateException($exception)) {
                    throw $exception;
                }

                if ($commentEvent['comment_id'] !== null) {
                    $existingEvent = $this->eventRepository->findByCommentId($commentEvent['comment_id']);

                    if ($existingEvent !== null) {
                        $this->eventRepository->bumpDuplicateDelivery((int) $existingEvent['id']);
                        $this->eventRepository->markSampleTest(
                            (int) $existingEvent['id'],
                            'Meta dashboard sample/test payload detected after duplicate delivery. Caption lookup and DM send skipped.'
                        );
                    }
                }

                $results['duplicates']++;
                continue;
            }
        }

        if ($sampleEvents !== []) {
            Logger::info('Instagram Meta dashboard sample/test webhook received.', [
                'event_count' => count($sampleEvents),
                'sample_events' => array_map(
                    fn (array $event): array => $this->eventSummary($event),
                    $sampleEvents
                ),
                'raw_body_length' => strlen($rawBody),
            ]);
            $results['ignored'] += count($sampleEvents);
        }

        if ($processableEvents === [] && $sampleEvents !== [] && $incompleteEvents === []) {
            $results['message'] = 'Sample webhook received';

            return $results;
        }

        if ($processableEvents === [] && $incompleteEvents !== []) {
            Logger::info('Instagram sample or incomplete comment webhook received.', [
                'event_count' => count($incompleteEvents),
                'incomplete_events' => array_map(
                    fn (array $event): array => $this->eventSummary($event),
                    $incompleteEvents
                ),
                'raw_body_length' => strlen($rawBody),
            ]);
            $results['ignored'] += count($incompleteEvents);
            $results['message'] = $sampleEvents !== [] ? 'Sample webhook received' : 'Webhook received with incomplete comment data';

            return $results;
        }

        if ($incompleteEvents !== []) {
            Logger::warning('Instagram webhook contained incomplete comment events that were skipped.', [
                'event_count' => count($incompleteEvents),
                'incomplete_events' => array_map(
                    fn (array $event): array => $this->eventSummary($event),
                    $incompleteEvents
                ),
            ]);
            $results['ignored'] += count($incompleteEvents);
        }

        $settings = $this->settingsRepository->get();
        $rules = $this->ruleRepository->allActive();

        foreach ($processableEvents as $commentEvent) {
            $results['processed']++;

            $commentId = $commentEvent['comment_id'];
            $normalizedText = CaptionPromptParser::normalize($commentEvent['comment_text']);

            if ($commentId === null || $normalizedText === '') {
                $this->eventRepository->createInvalidEvent([
                    'status' => 'invalid_payload',
                    'comment_text' => $commentEvent['comment_text'],
                    'webhook_payload' => json_encode(
                        ['raw_body' => $rawBody, 'comment_event' => $commentEvent],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ]);
                $results['invalid']++;
                continue;
            }

            try {
                $eventId = $this->eventRepository->createReceived([
                    'comment_id' => $commentId,
                    'media_id' => $commentEvent['media_id'],
                    'instagram_user_id' => $commentEvent['instagram_user_id'],
                    'instagram_username' => $commentEvent['instagram_username'],
                    'comment_text' => $commentEvent['comment_text'],
                    'normalized_comment_text' => $normalizedText,
                    'webhook_payload' => json_encode($commentEvent['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            } catch (PDOException $exception) {
                if (!$this->eventRepository->isDuplicateException($exception)) {
                    throw $exception;
                }

                $existingEvent = $this->eventRepository->findByCommentId($commentId);

                if ($existingEvent !== null) {
                    $this->eventRepository->bumpDuplicateDelivery((int) $existingEvent['id']);
                }

                $results['duplicates']++;
                continue;
            }

            if (!(bool) $settings['is_enabled']) {
                $this->eventRepository->markBotDisabled($eventId);
                $results['ignored']++;
                continue;
            }

            if (($commentEvent['media_id'] ?? '') === '') {
                $this->eventRepository->markFailed(
                    $eventId,
                    null,
                    null,
                    '',
                    'Unsupported comment context. No media ID was present in the webhook payload.'
                );
                $results['failed']++;
                continue;
            }

            try {
                $mediaDetails = $this->metaClient->fetchMediaDetails((string) $commentEvent['media_id']);
            } catch (RuntimeException $exception) {
                $this->eventRepository->markFailed(
                    $eventId,
                    null,
                    null,
                    '',
                    'Failed to fetch parent media caption. ' . $exception->getMessage()
                );
                Logger::error('Failed to fetch Instagram media details.', [
                    'media_id' => $commentEvent['media_id'],
                    'comment_id' => $commentId,
                    'message' => $exception->getMessage(),
                ]);
                $results['failed']++;
                continue;
            }

            $captionText = trim((string) ($mediaDetails['caption'] ?? ''));
            $promptKeyword = CaptionPromptParser::extractPromptKeyword($captionText);
            $this->eventRepository->storeCaptionContext(
                $eventId,
                $captionText !== '' ? $captionText : null,
                $promptKeyword
            );

            if ($promptKeyword === null) {
                $this->eventRepository->markSkipped(
                    $eventId,
                    'no_prompt_in_caption',
                    'No supported "Comment KEYWORD" prompt was found in the parent media caption.'
                );
                $results['skipped']++;
                continue;
            }

            if ($normalizedText !== $promptKeyword) {
                $this->eventRepository->markSkipped(
                    $eventId,
                    'comment_keyword_mismatch',
                    'The comment text did not match the keyword explicitly prompted in the parent media caption.'
                );
                $results['skipped']++;
                continue;
            }

            $rule = $this->findMatchingRule($promptKeyword, $rules);
            $matchedRuleId = $rule['id'] ?? null;
            $matchedKeyword = $rule['keyword'] ?? null;

            if ($rule === null && (bool) $settings['default_reply_enabled'] && trim((string) $settings['default_reply_text']) !== '') {
                $rule = [
                    'id' => null,
                    'keyword' => '[default]',
                    'response_body' => trim((string) $settings['default_reply_text']),
                ];
                $matchedKeyword = '[default]';
            }

            if ($rule === null) {
                $this->eventRepository->markNoMatch(
                    $eventId,
                    'The caption prompt matched the comment, but no active rule was configured for that keyword.'
                );
                $results['ignored']++;
                continue;
            }

            $replyBody = trim((string) $rule['response_body']);
            $replyPayload = json_encode([
                'recipient' => ['comment_id' => $commentId],
                'message' => ['text' => $replyBody],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->eventRepository->markMatched($eventId, $matchedRuleId === null ? null : (int) $matchedRuleId, $matchedKeyword, $replyPayload);

            if ((bool) $settings['test_mode']) {
                $this->eventRepository->markDryRun($eventId, $matchedRuleId === null ? null : (int) $matchedRuleId, $matchedKeyword, $replyPayload);
                continue;
            }

            try {
                $response = $this->metaClient->sendPrivateReply($commentId, $replyBody);
                $this->eventRepository->markSent(
                    $eventId,
                    $matchedRuleId === null ? null : (int) $matchedRuleId,
                    $matchedKeyword,
                    $replyPayload,
                    json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                $results['sent']++;
            } catch (RuntimeException $exception) {
                $this->eventRepository->markFailed(
                    $eventId,
                    $matchedRuleId === null ? null : (int) $matchedRuleId,
                    $matchedKeyword,
                    $replyPayload,
                    $exception->getMessage()
                );
                Logger::error('Failed to send Instagram private reply.', [
                    'comment_id' => $commentId,
                    'message' => $exception->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }

    private function extractCommentEvents(array $payload): array
    {
        $events = [];
        $entries = $payload['entry'] ?? [];

        if (!is_array($entries)) {
            return $events;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $candidates = [];

            if (isset($entry['changes']) && is_array($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (!is_array($change)) {
                        continue;
                    }

                    $candidates[] = [
                        'field' => $change['field'] ?? null,
                        'value' => $change['value'] ?? [],
                        'account_id' => $entry['id'] ?? null,
                        'event_time' => $entry['time'] ?? null,
                    ];
                }
            }

            if (isset($entry['field']) || isset($entry['value'])) {
                $candidates[] = [
                    'field' => $entry['field'] ?? null,
                    'value' => $entry['value'] ?? [],
                    'account_id' => $entry['id'] ?? null,
                    'event_time' => $entry['time'] ?? null,
                ];
            }

            foreach ($candidates as $candidate) {
                if (!in_array($candidate['field'], ['comments', 'live_comments'], true)) {
                    continue;
                }

                $value = is_array($candidate['value']) ? $candidate['value'] : [];
                $from = isset($value['from']) && is_array($value['from']) ? $value['from'] : [];
                $media = isset($value['media']) && is_array($value['media']) ? $value['media'] : [];
                $commentId = isset($value['id']) && trim((string) $value['id']) !== '' ? (string) $value['id'] : null;
                $mediaId = isset($value['media_id']) && trim((string) $value['media_id']) !== ''
                    ? (string) $value['media_id']
                    : (isset($media['id']) && trim((string) $media['id']) !== '' ? (string) $media['id'] : null);
                $commentText = isset($value['text']) && trim((string) $value['text']) !== '' ? (string) $value['text'] : null;
                $sampleSignals = $this->sampleTestSignals(
                    $candidate['account_id'] !== null ? (string) $candidate['account_id'] : null,
                    isset($from['username']) ? (string) $from['username'] : null,
                    $commentText,
                    $mediaId
                );
                $missingRequiredFields = [];

                if ($commentId === null) {
                    $missingRequiredFields[] = 'value.id';
                }

                if ($mediaId === null) {
                    $missingRequiredFields[] = 'value.media.id';
                }

                if ($commentText === null) {
                    $missingRequiredFields[] = 'value.text';
                }

                $events[] = [
                    'field' => $candidate['field'],
                    'account_id' => $candidate['account_id'] !== null ? (string) $candidate['account_id'] : null,
                    'event_time' => $candidate['event_time'],
                    'comment_id' => $commentId,
                    'media_id' => $mediaId,
                    'instagram_user_id' => isset($from['id']) && trim((string) $from['id']) !== '' ? (string) $from['id'] : null,
                    'instagram_username' => isset($from['username']) && trim((string) $from['username']) !== '' ? (string) $from['username'] : null,
                    'comment_text' => $commentText,
                    'sample_test_signals' => $sampleSignals,
                    'is_sample_test' => $this->isSampleTestEvent($sampleSignals),
                    'missing_required_fields' => $missingRequiredFields,
                    'is_automatable' => $missingRequiredFields === [],
                    'raw' => [
                        'field' => $candidate['field'],
                        'account_id' => $candidate['account_id'],
                        'event_time' => $candidate['event_time'],
                        'value' => $value,
                    ],
                ];
            }
        }

        return $events;
    }

    private function eventSummary(array $event): array
    {
        return [
            'field' => $event['field'] ?? null,
            'comment_id' => $event['comment_id'] ?? null,
            'media_id' => $event['media_id'] ?? null,
            'instagram_user_id' => $event['instagram_user_id'] ?? null,
            'instagram_username' => $event['instagram_username'] ?? null,
            'comment_text' => $event['comment_text'] ?? null,
            'missing_required_fields' => $event['missing_required_fields'] ?? [],
            'sample_test_signals' => $event['sample_test_signals'] ?? [],
        ];
    }

    private function isLocalTestingMode(): bool
    {
        $appEnv = strtolower(trim(Env::get('APP_ENV', 'production')));

        return in_array($appEnv, ['local', 'development', 'dev', 'test', 'testing'], true)
            || Env::bool('APP_DEBUG', false);
    }

    private function sampleTestSignals(
        ?string $accountId,
        ?string $instagramUsername,
        ?string $commentText,
        ?string $mediaId
    ): array {
        $signals = [];

        if (trim((string) $accountId) === '0') {
            $signals[] = 'account_id_zero';
        }

        if (CaptionPromptParser::normalize($instagramUsername) === 'test') {
            $signals[] = 'username_test';
        }

        if (in_array(CaptionPromptParser::normalize($commentText), ['this is an example', 'this is an example.'], true)) {
            $signals[] = 'example_comment_text';
        }

        if ($this->looksLikeSampleMediaId($mediaId)) {
            $signals[] = 'sample_media_id';
        }

        return $signals;
    }

    private function isSampleTestEvent(array $signals): bool
    {
        if (in_array('account_id_zero', $signals, true)) {
            return true;
        }

        return count($signals) >= 2;
    }

    private function looksLikeSampleMediaId(?string $mediaId): bool
    {
        $mediaId = trim((string) $mediaId);

        if ($mediaId === '') {
            return false;
        }

        return in_array($mediaId, ['0', '123', '123123123', '1231231234'], true);
    }

    private function findMatchingRule(string $normalizedText, array $rules): ?array
    {
        $exactMatches = [];
        $containsMatches = [];

        foreach ($rules as $rule) {
            $keyword = CaptionPromptParser::normalize((string) $rule['keyword']);

            if ($keyword === '') {
                continue;
            }

            if ($rule['match_type'] === 'exact' && $normalizedText === $keyword) {
                $exactMatches[] = $rule;
                continue;
            }

            if ($rule['match_type'] === 'contains' && str_contains($normalizedText, $keyword)) {
                $containsMatches[] = $rule;
            }
        }

        if ($exactMatches !== []) {
            return $exactMatches[0];
        }

        if ($containsMatches !== []) {
            return $containsMatches[0];
        }

        return null;
    }
}
