<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SqliteTokenRepository
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
CREATE TABLE IF NOT EXISTS ebay_oauth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    token_type TEXT NOT NULL,
    expires_in INTEGER NOT NULL,
    scope TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        $this->pdo->exec($sql);
    }

    /** @param array<string, mixed> $tokenPayload */
    public function save(array $tokenPayload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ebay_oauth_tokens (access_token, refresh_token, token_type, expires_in, scope) VALUES (:access_token, :refresh_token, :token_type, :expires_in, :scope)'
        );

        $statement->execute([
            ':access_token' => (string) ($tokenPayload['access_token'] ?? ''),
            ':refresh_token' => isset($tokenPayload['refresh_token']) ? (string) $tokenPayload['refresh_token'] : null,
            ':token_type' => (string) ($tokenPayload['token_type'] ?? 'Bearer'),
            ':expires_in' => (int) ($tokenPayload['expires_in'] ?? 0),
            ':scope' => (string) ($tokenPayload['scope'] ?? ''),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $statement = $this->pdo->query('SELECT * FROM ebay_oauth_tokens ORDER BY id DESC LIMIT 1');
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
