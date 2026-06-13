<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SqliteWebhookLogRepository
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $directory = dirname($dbPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ebay_webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notification_id TEXT NULL,
    topic TEXT NULL,
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        $this->pdo->exec($sql);
    }

    public function save(string $payload, ?string $notificationId = null, ?string $topic = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ebay_webhook_events (notification_id, topic, payload_json) VALUES (:notification_id, :topic, :payload_json)'
        );
        $statement->execute([
            ':notification_id' => $notificationId,
            ':topic' => $topic,
            ':payload_json' => $payload,
        ]);
    }
}
