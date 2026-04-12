<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RuleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT *
             FROM instagram_keyword_rules
             ORDER BY is_active DESC, match_type ASC, keyword ASC, id DESC'
        );

        return $statement->fetchAll();
    }

    public function allActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT *
             FROM instagram_keyword_rules
             WHERE is_active = 1
             ORDER BY CASE WHEN match_type = "exact" THEN 0 ELSE 1 END ASC,
                      CHAR_LENGTH(keyword) DESC,
                      keyword ASC,
                      id ASC'
        );

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM instagram_keyword_rules WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $rule = $statement->fetch();

        return $rule === false ? null : $rule;
    }

    public function create(array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO instagram_keyword_rules
                (keyword, match_type, response_type, response_body, is_active, created_at, updated_at)
             VALUES
                (:keyword, :match_type, :response_type, :response_body, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        $statement->execute($data);
    }

    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE instagram_keyword_rules
             SET keyword = :keyword,
                 match_type = :match_type,
                 response_type = :response_type,
                 response_body = :response_body,
                 is_active = :is_active,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );

        $statement->execute($data + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM instagram_keyword_rules WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
