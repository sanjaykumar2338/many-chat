<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createReceived(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO instagram_comment_events
                (
                    comment_id,
                    media_id,
                    instagram_user_id,
                    instagram_username,
                    comment_text,
                    normalized_comment_text,
                    caption_text,
                    prompt_keyword,
                    skip_reason,
                    status,
                    webhook_payload,
                    delivery_count,
                    last_delivery_at,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :comment_id,
                    :media_id,
                    :instagram_user_id,
                    :instagram_username,
                    :comment_text,
                    :normalized_comment_text,
                    :caption_text,
                    :prompt_keyword,
                    :skip_reason,
                    :status,
                    :webhook_payload,
                    1,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )'
        );

        $statement->execute([
            'comment_id' => $data['comment_id'],
            'media_id' => $data['media_id'],
            'instagram_user_id' => $data['instagram_user_id'],
            'instagram_username' => $data['instagram_username'],
            'comment_text' => $data['comment_text'],
            'normalized_comment_text' => $data['normalized_comment_text'],
            'caption_text' => $data['caption_text'] ?? null,
            'prompt_keyword' => $data['prompt_keyword'] ?? null,
            'skip_reason' => $data['skip_reason'] ?? null,
            'status' => $data['status'] ?? 'received',
            'webhook_payload' => $data['webhook_payload'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createInvalidEvent(array $data): int
    {
        return $this->createReceived([
            'comment_id' => null,
            'media_id' => null,
            'instagram_user_id' => null,
            'instagram_username' => null,
            'comment_text' => $data['comment_text'] ?? null,
            'normalized_comment_text' => null,
            'caption_text' => null,
            'prompt_keyword' => null,
            'skip_reason' => null,
            'status' => $data['status'],
            'webhook_payload' => $data['webhook_payload'],
        ]);
    }

    public function isDuplicateException(PDOException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }

    public function findByCommentId(string $commentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM instagram_comment_events WHERE comment_id = :comment_id LIMIT 1');
        $statement->execute(['comment_id' => $commentId]);
        $event = $statement->fetch();

        return $event === false ? null : $event;
    }

    public function bumpDuplicateDelivery(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE instagram_comment_events
             SET delivery_count = delivery_count + 1,
                 last_duplicate_at = UTC_TIMESTAMP(),
                 last_delivery_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);
    }

    public function markBotDisabled(int $id): void
    {
        $this->updateStatus($id, 'bot_disabled', null, null, 'Bot was disabled when the webhook was processed.', null, true);
    }

    public function markNoMatch(int $id, ?string $apiResponse = null): void
    {
        $this->updateStatus(
            $id,
            'no_match',
            null,
            null,
            $apiResponse ?? 'No active keyword rule matched the prompted keyword.',
            null,
            true
        );
    }

    public function storeCaptionContext(int $id, ?string $captionText, ?string $promptKeyword): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE instagram_comment_events
             SET caption_text = :caption_text,
                 prompt_keyword = :prompt_keyword,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );

        $statement->bindValue(':caption_text', $captionText, $captionText === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':prompt_keyword', $promptKeyword, $promptKeyword === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function markSkipped(int $id, string $skipReason, ?string $apiResponse = null): void
    {
        $this->updateStatus(
            $id,
            'skipped',
            null,
            null,
            $apiResponse,
            null,
            true,
            $skipReason
        );
    }

    public function markMatched(int $id, ?int $ruleId, ?string $matchedKeyword, string $replyPayload): void
    {
        $this->updateStatus($id, 'matched', $ruleId, $matchedKeyword, null, $replyPayload);
    }

    public function markDryRun(int $id, ?int $ruleId, ?string $matchedKeyword, string $replyPayload): void
    {
        $this->updateStatus($id, 'dry_run', $ruleId, $matchedKeyword, 'Test mode enabled. API call skipped.', $replyPayload, true);
    }

    public function markSent(int $id, ?int $ruleId, ?string $matchedKeyword, string $replyPayload, string $apiResponse): void
    {
        $this->updateStatus($id, 'sent', $ruleId, $matchedKeyword, $apiResponse, $replyPayload, true);
    }

    public function markFailed(int $id, ?int $ruleId, ?string $matchedKeyword, string $replyPayload, string $apiResponse): void
    {
        $this->updateStatus($id, 'failed', $ruleId, $matchedKeyword, $apiResponse, $replyPayload);
    }

    public function recent(?string $status = null, int $limit = 100): array
    {
        $sql = 'SELECT e.*, r.keyword AS rule_keyword
                FROM instagram_comment_events e
                LEFT JOIN instagram_keyword_rules r ON r.id = e.matched_rule_id';
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE e.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY e.created_at DESC, e.id DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function updateStatus(
        int $id,
        string $status,
        ?int $ruleId,
        ?string $matchedKeyword,
        ?string $apiResponse = null,
        ?string $replyPayload = null,
        bool $markProcessed = false,
        ?string $skipReason = null
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE instagram_comment_events
             SET matched_rule_id = :matched_rule_id,
                 matched_keyword = :matched_keyword,
                 skip_reason = :skip_reason,
                 status = :status,
                 reply_payload = :reply_payload,
                 api_response = :api_response,
                 processed_at = CASE WHEN :mark_processed = 1 THEN UTC_TIMESTAMP() ELSE processed_at END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );

        $statement->bindValue(':matched_rule_id', $ruleId, $ruleId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':matched_keyword', $matchedKeyword, $matchedKeyword === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':skip_reason', $skipReason, $skipReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':reply_payload', $replyPayload, $replyPayload === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':api_response', $apiResponse, $apiResponse === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':mark_processed', $markProcessed ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }
}
