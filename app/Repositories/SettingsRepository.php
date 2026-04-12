<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(): array
    {
        $statement = $this->pdo->query('SELECT * FROM instagram_bot_settings ORDER BY id ASC LIMIT 1');
        $settings = $statement->fetch();

        if ($settings !== false) {
            return $settings;
        }

        $this->pdo->exec(
            "INSERT INTO instagram_bot_settings (id, is_enabled, default_reply_enabled, default_reply_text, test_mode, updated_at)
             VALUES (1, 1, 0, '', 0, UTC_TIMESTAMP())"
        );

        $statement = $this->pdo->query('SELECT * FROM instagram_bot_settings ORDER BY id ASC LIMIT 1');

        return $statement->fetch() ?: [];
    }

    public function save(array $data): void
    {
        $settings = $this->get();

        $statement = $this->pdo->prepare(
            'UPDATE instagram_bot_settings
             SET is_enabled = :is_enabled,
                 default_reply_enabled = :default_reply_enabled,
                 default_reply_text = :default_reply_text,
                 test_mode = :test_mode,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $settings['id'],
            'is_enabled' => $data['is_enabled'],
            'default_reply_enabled' => $data['default_reply_enabled'],
            'default_reply_text' => $data['default_reply_text'],
            'test_mode' => $data['test_mode'],
        ]);
    }
}
