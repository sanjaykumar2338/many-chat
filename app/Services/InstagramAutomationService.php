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
        $appSecret = Env::get('META_APP_SECRET');
        $requireSignature = Env::bool('META_REQUIRE_SIGNATURE', $appSecret !== '');

        if ($appSecret === '' && !$requireSignature) {
            return true;
        }

        if ($signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return !$requireSignature;
        }

        $receivedHash = substr($signatureHeader, 7);
        $expectedHash = hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expectedHash, $receivedHash);
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

        $settings = $this->settingsRepository->get();
        $rules = $this->ruleRepository->allActive();

        foreach ($commentEvents as $commentEvent) {
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

        foreach ($payload['entry'] ?? [] as $entry) {
            $candidates = [];

            if (isset($entry['changes']) && is_array($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
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
                $events[] = [
                    'field' => $candidate['field'],
                    'account_id' => $candidate['account_id'] !== null ? (string) $candidate['account_id'] : null,
                    'event_time' => $candidate['event_time'],
                    'comment_id' => isset($value['id']) ? (string) $value['id'] : null,
                    'media_id' => isset($value['media_id']) ? (string) $value['media_id'] : (isset($value['media']['id']) ? (string) $value['media']['id'] : null),
                    'instagram_user_id' => isset($value['from']['id']) ? (string) $value['from']['id'] : null,
                    'instagram_username' => isset($value['from']['username']) ? (string) $value['from']['username'] : null,
                    'comment_text' => isset($value['text']) ? (string) $value['text'] : null,
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
