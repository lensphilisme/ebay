<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\EbayConfig;
use App\Repositories\SqliteCjMappingRepository;
use App\Repositories\SqliteWebhookLogRepository;
use App\Services\ChallengeResponder;

final class EbayWebhookController
{
    public function __construct(
        private readonly EbayConfig $config,
        private readonly ChallengeResponder $challengeResponder,
        private readonly SqliteWebhookLogRepository $logRepository,
        private readonly ?SqliteCjMappingRepository $cjMappingRepository = null
    ) {
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET') {
            $this->handleGet();
            return;
        }

        if ($method === 'POST') {
            $this->handlePost();
            return;
        }

        $this->json(['error' => 'Method not allowed'], 405);
    }

    private function handleGet(): void
    {
        $challengeCode = $_GET['challenge_code'] ?? '';
        if ($challengeCode === '') {
            $this->json([
                'status' => 'ok',
                'service' => 'ebay-webhook',
                'message' => 'Send challenge_code query parameter for eBay validation.',
            ], 200);
            return;
        }

        $endpoint = $this->resolveCurrentEndpointUrl();
        $challengeResponse = $this->challengeResponder->compute(
            (string) $challengeCode,
            $this->config->verificationToken,
            $endpoint
        );

        $this->json(['challengeResponse' => $challengeResponse], 200);
    }

    private function handlePost(): void
    {
        $rawPayload = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($rawPayload, true);
        $notificationId = $decoded['notification']['notificationId'] ?? null;
        $topic = $decoded['metadata']['topic'] ?? null;

        $this->logRepository->save($rawPayload, is_string($notificationId) ? $notificationId : null, is_string($topic) ? $topic : null);
        $this->json(['received' => true], 200);
    }

    public function handleCj(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $this->json([
                'status' => 'ok',
                'service' => 'cj-webhook',
                'message' => 'CJ should POST JSON webhook messages here.',
            ], 200);
            return;
        }

        if ($method !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $rawPayload = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            $this->logRepository->save($rawPayload, null, 'CJ:INVALID');
            $this->json(['received' => true], 200);
            return;
        }

        $messageId = isset($decoded['messageId']) ? (string) $decoded['messageId'] : null;
        $type = strtoupper(trim((string) ($decoded['type'] ?? 'UNKNOWN')));
        $this->logRepository->save($rawPayload, $messageId, 'CJ:' . $type);

        if ($this->cjMappingRepository !== null) {
            $params = is_array($decoded['params'] ?? null) ? (array) $decoded['params'] : [];
            if ($type === 'ORDER') {
                $this->applyCjOrderWebhook($params);
            } elseif ($type === 'LOGISTIC') {
                $this->applyCjLogisticWebhook($params);
            }
        }

        $this->json(['received' => true], 200);
    }

    /** @param array<string, mixed> $params */
    private function applyCjOrderWebhook(array $params): void
    {
        if ($this->cjMappingRepository === null) {
            return;
        }

        $cjOrderId = trim((string) ($params['cjOrderId'] ?? $params['orderId'] ?? ''));
        $orderNumber = trim((string) ($params['orderNumber'] ?? $params['orderNum'] ?? ''));
        $ebayOrderId = stripos($orderNumber, 'EBAY-') === 0 ? substr($orderNumber, 5) : '';
        $existing = $cjOrderId !== '' ? $this->cjMappingRepository->findOrderMapByCjOrderId($cjOrderId) : null;
        if ($existing === null && $ebayOrderId !== '') {
            $existing = $this->cjMappingRepository->findOrderMapByEbayOrderId($ebayOrderId);
        }

        $payload = [
            'ebay_order_id' => $ebayOrderId,
            'cj_order_id' => $cjOrderId,
            'cj_order_number' => $orderNumber,
            'cj_order_status' => (string) ($params['orderStatus'] ?? ''),
            'logistic_name' => (string) ($params['logisticName'] ?? ''),
            'tracking_number' => (string) ($params['trackNumber'] ?? $params['trackingNumber'] ?? ''),
            'shipping_carrier' => (string) ($params['logisticName'] ?? ''),
            'tracking_url' => (string) ($params['trackingUrl'] ?? ''),
            'last_cj_payload_json' => $params,
        ];

        if ($existing !== null) {
            $this->cjMappingRepository->updateOrderMapStatus((int) $existing['id'], $payload);
            return;
        }

        if ($ebayOrderId !== '' || $cjOrderId !== '') {
            $this->cjMappingRepository->saveOrderMap($payload);
        }
    }

    /** @param array<string, mixed> $params */
    private function applyCjLogisticWebhook(array $params): void
    {
        if ($this->cjMappingRepository === null) {
            return;
        }

        $cjOrderId = trim((string) ($params['orderId'] ?? $params['cjOrderId'] ?? ''));
        if ($cjOrderId === '') {
            return;
        }

        $existing = $this->cjMappingRepository->findOrderMapByCjOrderId($cjOrderId);
        if ($existing === null) {
            return;
        }

        $tracking = trim((string) ($params['trackingNumber'] ?? $params['trackNumber'] ?? ''));
        $carrier = trim((string) ($params['logisticName'] ?? ''));
        $this->cjMappingRepository->updateOrderMapStatus((int) $existing['id'], [
            'cj_order_status' => isset($params['trackingStatus']) ? 'TRACKING_' . (string) $params['trackingStatus'] : null,
            'logistic_name' => $carrier,
            'tracking_number' => $tracking,
            'shipping_carrier' => $carrier,
            'tracking_url' => (string) ($params['trackingUrl'] ?? ''),
            'last_cj_payload_json' => $params,
        ]);
    }

    private function resolveCurrentEndpointUrl(): string
    {
        $scheme = 'https';

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = trim((string) $_SERVER['REQUEST_SCHEME']);
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return $scheme . '://' . $host . $path;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
