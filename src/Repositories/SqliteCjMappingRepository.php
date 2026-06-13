<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SqliteCjMappingRepository
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
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cj_ebay_listing_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ebay_item_id TEXT NOT NULL,
    cj_pid TEXT NOT NULL,
    cj_vids_json TEXT NOT NULL,
    sku_vid_map_json TEXT NULL,
    cj_title TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cj_ebay_order_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ebay_order_id TEXT NOT NULL,
    ebay_line_item_id TEXT NULL,
    ebay_line_items_json TEXT NULL,
    cj_order_id TEXT NOT NULL,
    cj_order_number TEXT NULL,
    cj_order_status TEXT NULL,
    logistic_name TEXT NULL,
    tracking_number TEXT NULL,
    shipping_carrier TEXT NULL,
    tracking_url TEXT NULL,
    last_cj_payload_json TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ebay_aspect_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id TEXT NOT NULL,
    category_name TEXT NULL,
    aspect_values_json TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

        $this->ensureColumn('cj_ebay_listing_map', 'sku_vid_map_json', 'TEXT NULL');
        $this->ensureColumn('cj_ebay_order_map', 'ebay_line_items_json', 'TEXT NULL');
        $this->ensureColumn('cj_ebay_order_map', 'cj_order_number', 'TEXT NULL');
        $this->ensureColumn('cj_ebay_order_map', 'logistic_name', 'TEXT NULL');
        $this->ensureColumn('cj_ebay_order_map', 'tracking_url', 'TEXT NULL');
        $this->ensureColumn('cj_ebay_order_map', 'last_cj_payload_json', 'TEXT NULL');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $statement = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        $rows = $statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $column) {
                return;
            }
        }

        $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    /** @param list<string> $vids @param array<string, string> $skuVidMap */
    public function saveListingMap(string $ebayItemId, string $cjPid, array $vids, string $title = '', array $skuVidMap = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cj_ebay_listing_map (ebay_item_id, cj_pid, cj_vids_json, sku_vid_map_json, cj_title) VALUES (:ebay_item_id, :cj_pid, :cj_vids_json, :sku_vid_map_json, :cj_title)'
        );

        $statement->execute([
            ':ebay_item_id' => $ebayItemId,
            ':cj_pid' => $cjPid,
            ':cj_vids_json' => json_encode(array_values($vids), JSON_UNESCAPED_SLASHES),
            ':sku_vid_map_json' => $skuVidMap !== [] ? json_encode($skuVidMap, JSON_UNESCAPED_SLASHES) : null,
            ':cj_title' => $title,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findListingMapByItemId(string $ebayItemId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM cj_ebay_listing_map WHERE ebay_item_id = :ebay_item_id ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':ebay_item_id' => $ebayItemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findListingMapByCjPid(string $cjPid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM cj_ebay_listing_map WHERE cj_pid = :cj_pid ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':cj_pid' => $cjPid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function deleteListingMapByItemId(string $ebayItemId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM cj_ebay_listing_map WHERE ebay_item_id = :ebay_item_id');
        $statement->execute([':ebay_item_id' => $ebayItemId]);
    }

    public function countListingMaps(): int
    {
        $result = $this->pdo->query('SELECT COUNT(*) FROM cj_ebay_listing_map');
        return $result !== false ? (int) $result->fetchColumn() : 0;
    }

    /** @return list<array<string, mixed>> */
    public function listRecentListingMaps(int $limit = 12): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM cj_ebay_listing_map ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public function listAllListingMaps(int $limit = 250): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM cj_ebay_listing_map ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string, mixed> $payload */
    public function saveOrderMap(array $payload): void
    {
        $ebayOrderId = trim((string) ($payload['ebay_order_id'] ?? ''));
        $cjOrderId = trim((string) ($payload['cj_order_id'] ?? ''));
        $existing = $ebayOrderId !== '' ? $this->findOrderMapByEbayOrderId($ebayOrderId) : null;
        if ($existing === null && $cjOrderId !== '') {
            $existing = $this->findOrderMapByCjOrderId($cjOrderId);
        }

        if ($existing !== null) {
            $this->updateOrderMapStatus((int) $existing['id'], $payload);
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO cj_ebay_order_map (
                ebay_order_id, ebay_line_item_id, ebay_line_items_json, cj_order_id, cj_order_number,
                cj_order_status, logistic_name, tracking_number, shipping_carrier, tracking_url, last_cj_payload_json, updated_at
             )
             VALUES (
                :ebay_order_id, :ebay_line_item_id, :ebay_line_items_json, :cj_order_id, :cj_order_number,
                :cj_order_status, :logistic_name, :tracking_number, :shipping_carrier, :tracking_url, :last_cj_payload_json, CURRENT_TIMESTAMP
             )'
        );

        $statement->execute([
            ':ebay_order_id' => $ebayOrderId,
            ':ebay_line_item_id' => isset($payload['ebay_line_item_id']) ? (string) $payload['ebay_line_item_id'] : null,
            ':ebay_line_items_json' => isset($payload['ebay_line_items_json']) ? json_encode($payload['ebay_line_items_json'], JSON_UNESCAPED_SLASHES) : null,
            ':cj_order_id' => $cjOrderId,
            ':cj_order_number' => isset($payload['cj_order_number']) ? (string) $payload['cj_order_number'] : null,
            ':cj_order_status' => isset($payload['cj_order_status']) ? (string) $payload['cj_order_status'] : null,
            ':logistic_name' => isset($payload['logistic_name']) ? (string) $payload['logistic_name'] : null,
            ':tracking_number' => isset($payload['tracking_number']) ? (string) $payload['tracking_number'] : null,
            ':shipping_carrier' => isset($payload['shipping_carrier']) ? (string) $payload['shipping_carrier'] : null,
            ':tracking_url' => isset($payload['tracking_url']) ? (string) $payload['tracking_url'] : null,
            ':last_cj_payload_json' => isset($payload['last_cj_payload_json'])
                ? (is_string($payload['last_cj_payload_json']) ? $payload['last_cj_payload_json'] : json_encode($payload['last_cj_payload_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findOrderMapByEbayOrderId(string $ebayOrderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cj_ebay_order_map WHERE ebay_order_id = :ebay_order_id LIMIT 1');
        $statement->execute([':ebay_order_id' => $ebayOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findOrderMapByCjOrderId(string $cjOrderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cj_ebay_order_map WHERE cj_order_id = :cj_order_id LIMIT 1');
        $statement->execute([':cj_order_id' => $cjOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function listRecentOrderMaps(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM cj_ebay_order_map ORDER BY updated_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public function getPendingOrderMaps(): array
    {
        $statement = $this->pdo->query('SELECT * FROM cj_ebay_order_map WHERE tracking_number IS NULL OR tracking_number = \'\'');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string, mixed> $payload */
    public function updateOrderMapStatus(int $id, array $payload): void
    {
        $current = $this->loadOrderMapById($id);
        if ($current === null) {
            return;
        }

        $values = [
            ':ebay_order_id' => $this->coalesceString($payload['ebay_order_id'] ?? null, (string) ($current['ebay_order_id'] ?? '')),
            ':ebay_line_item_id' => $this->coalesceNullableString($payload['ebay_line_item_id'] ?? null, $current['ebay_line_item_id'] ?? null),
            ':ebay_line_items_json' => array_key_exists('ebay_line_items_json', $payload)
                ? json_encode($payload['ebay_line_items_json'], JSON_UNESCAPED_SLASHES)
                : ($current['ebay_line_items_json'] ?? null),
            ':cj_order_id' => $this->coalesceString($payload['cj_order_id'] ?? null, (string) ($current['cj_order_id'] ?? '')),
            ':cj_order_number' => $this->coalesceNullableString($payload['cj_order_number'] ?? null, $current['cj_order_number'] ?? null),
            ':cj_order_status' => $this->coalesceNullableString($payload['cj_order_status'] ?? null, $current['cj_order_status'] ?? null),
            ':logistic_name' => $this->coalesceNullableString($payload['logistic_name'] ?? null, $current['logistic_name'] ?? null),
            ':tracking_number' => $this->coalesceNullableString($payload['tracking_number'] ?? null, $current['tracking_number'] ?? null),
            ':shipping_carrier' => $this->coalesceNullableString($payload['shipping_carrier'] ?? null, $current['shipping_carrier'] ?? null),
            ':tracking_url' => $this->coalesceNullableString($payload['tracking_url'] ?? null, $current['tracking_url'] ?? null),
            ':last_cj_payload_json' => array_key_exists('last_cj_payload_json', $payload)
                ? (is_string($payload['last_cj_payload_json']) ? $payload['last_cj_payload_json'] : json_encode($payload['last_cj_payload_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : ($current['last_cj_payload_json'] ?? null),
            ':id' => $id,
        ];

        $statement = $this->pdo->prepare(
            'UPDATE cj_ebay_order_map
             SET ebay_order_id = :ebay_order_id,
                 ebay_line_item_id = :ebay_line_item_id,
                 ebay_line_items_json = :ebay_line_items_json,
                 cj_order_id = :cj_order_id,
                 cj_order_number = :cj_order_number,
                 cj_order_status = :cj_order_status,
                 logistic_name = :logistic_name,
                 tracking_number = :tracking_number,
                 shipping_carrier = :shipping_carrier,
                 tracking_url = :tracking_url,
                 last_cj_payload_json = :last_cj_payload_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute($values);
    }

    public function updateOrderMapTracking(int $id, string $tracking, string $carrier, string $status, string $trackingUrl = ''): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cj_ebay_order_map 
             SET tracking_number = :track, shipping_carrier = :car, logistic_name = :car, cj_order_status = :status, tracking_url = :tracking_url, updated_at = CURRENT_TIMESTAMP 
             WHERE id = :id'
        );
        $statement->execute([
            ':track' => $tracking,
            ':car' => $carrier,
            ':status' => $status,
            ':tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
            ':id' => $id
        ]);
    }

    /** @return array<string, mixed>|null */
    private function loadOrderMapById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cj_ebay_order_map WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function coalesceString(mixed $candidate, string $fallback): string
    {
        $value = trim((string) ($candidate ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    private function coalesceNullableString(mixed $candidate, mixed $fallback): ?string
    {
        $value = trim((string) ($candidate ?? ''));
        if ($value !== '') {
            return $value;
        }

        $fallbackValue = trim((string) ($fallback ?? ''));
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    /** @param array<string, string> $aspectValues */
    public function saveAspectTemplate(string $categoryId, string $categoryName, array $aspectValues): void
    {
        $delete = $this->pdo->prepare('DELETE FROM ebay_aspect_templates WHERE category_id = :category_id');
        $delete->execute([':category_id' => $categoryId]);

        $statement = $this->pdo->prepare(
            'INSERT INTO ebay_aspect_templates (category_id, category_name, aspect_values_json, updated_at)
             VALUES (:category_id, :category_name, :aspect_values_json, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':category_id' => $categoryId,
            ':category_name' => $categoryName,
            ':aspect_values_json' => json_encode($aspectValues, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function loadAspectTemplate(string $categoryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ebay_aspect_templates WHERE category_id = :category_id ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':category_id' => $categoryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
