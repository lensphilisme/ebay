<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SqliteCjMappingRepository;
use Throwable;

final class CjOrderFulfillmentSync
{
    private const LOG_PATH = __DIR__ . '/../../database/logs/cj-order-sync.log';

    public function __construct(
        private readonly CjDropshippingService $cjService,
        private readonly EbayApiClient $ebayClient,
        private readonly SqliteCjMappingRepository $mappingRepo
    ) {}

    /** @return array<string, mixed> */
    public function syncOrdersTwoWay(string $ebayToken, string $cjToken): array
    {
        return [
            'ebay_to_cj' => $this->syncEbayOrdersToCj($ebayToken, $cjToken),
            'cj_status' => $this->syncCjOrdersFromCj($cjToken),
            'tracking_to_ebay' => $this->syncCjTrackingToEbay($ebayToken, $cjToken),
        ];
    }

    /** @return array<string, mixed> */
    public function syncEbayOrdersToCj(string $ebayToken, string $cjToken): array
    {
        $response = $this->ebayClient->request(
            'GET',
            '/sell/fulfillment/v1/order?filter=orderfulfillmentstatus:%7BNOT_STARTED|IN_PROGRESS%7D&limit=50',
            $ebayToken
        );

        $orders = (array) ($response['body']['orders'] ?? []);
        $synced = [];
        $skipped = [];
        $errors = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $orderId = (string) ($order['orderId'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $cjOrderNumber = str_starts_with($orderId, 'EBAY-') ? $orderId : 'EBAY-' . $orderId;
            $existingMap = $this->mappingRepo->findOrderMapByEbayOrderId($orderId);
            if ($existingMap !== null && trim((string) ($existingMap['cj_order_id'] ?? '')) !== '') {
                $skipped[] = $orderId . ' already mapped';
                continue;
            }

            $existingCjOrder = $this->findCjOrderByOrderNumber($cjToken, $cjOrderNumber);
            if ($existingCjOrder !== null) {
                $this->saveCjOrderPayload($existingCjOrder, $orderId, $order);
                $synced[] = $orderId . ' linked to existing CJ order';
                continue;
            }

            $orderBuild = $this->buildCjPayloadFromEbayOrder($order, $cjOrderNumber);
            if ($orderBuild['products'] === []) {
                $this->log('skip_unmapped_order', ['order_id' => $orderId, 'line_items' => $order['lineItems'] ?? []]);
                $skipped[] = $orderId . ' has no mapped CJ variants';
                continue;
            }

            $payload = $orderBuild['payload'];
            try {
                $res = $this->cjService->createOrderV2($cjToken, $payload);
                $data = is_array($res['data'] ?? null) ? (array) $res['data'] : $res;
                $cjOrderId = $this->extractCjOrderId($data);
                if ($cjOrderId === '') {
                    throw new \RuntimeException('CJ created-order response did not include a CJ order id.');
                }

                $this->mappingRepo->saveOrderMap([
                    'ebay_order_id' => $orderId,
                    'ebay_line_item_id' => isset($orderBuild['line_items'][0]['lineItemId']) ? (string) $orderBuild['line_items'][0]['lineItemId'] : null,
                    'ebay_line_items_json' => $orderBuild['line_items'],
                    'cj_order_id' => $cjOrderId,
                    'cj_order_number' => $cjOrderNumber,
                    'cj_order_status' => $this->extractCjOrderStatus($data) ?: 'CREATED',
                    'logistic_name' => $this->extractCjLogisticName($data),
                    'tracking_number' => $this->extractCjTrackingNumber($data),
                    'shipping_carrier' => $this->extractCjLogisticName($data),
                    'tracking_url' => $this->extractCjTrackingUrl($data),
                    'last_cj_payload_json' => $res,
                ]);

                try {
                    $confirm = $this->cjService->confirmOrder($cjToken, $cjOrderId);
                    $map = $this->mappingRepo->findOrderMapByEbayOrderId($orderId);
                    if ($map !== null) {
                        $this->mappingRepo->updateOrderMapStatus((int) $map['id'], [
                            'cj_order_status' => 'CONFIRMED',
                            'last_cj_payload_json' => $confirm,
                        ]);
                    }
                } catch (Throwable $confirmError) {
                    $this->log('confirm_order_deferred', [
                        'ebay_order_id' => $orderId,
                        'cj_order_id' => $cjOrderId,
                        'error' => $confirmError->getMessage(),
                    ]);
                }

                $this->log('push_order_to_cj', [
                    'ebay_order_id' => $orderId,
                    'cj_order_id' => $cjOrderId,
                    'line_items' => $orderBuild['line_items'],
                ]);
                $synced[] = $orderId;
            } catch (Throwable $t) {
                $this->log('push_order_error', [
                    'ebay_order_id' => $orderId,
                    'error' => $t->getMessage(),
                    'payload' => $payload,
                ]);
                $errors[] = $orderId . ': ' . $t->getMessage();
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @return array<string, mixed> */
    public function syncCjOrdersFromCj(string $cjToken): array
    {
        $checked = 0;
        $updated = [];
        $errors = [];

        foreach ($this->mappingRepo->listRecentOrderMaps(200) as $map) {
            $cjOrderId = trim((string) ($map['cj_order_id'] ?? ''));
            if ($cjOrderId === '') {
                continue;
            }
            try {
                $detail = $this->cjService->getOrderDetail($cjToken, $cjOrderId);
                $payload = is_array($detail['data'] ?? null) ? (array) $detail['data'] : $detail;
                $this->saveCjOrderPayload($payload, (string) ($map['ebay_order_id'] ?? ''), null, (int) ($map['id'] ?? 0));
                $checked++;
                $updated[] = $cjOrderId;
            } catch (Throwable $t) {
                $errors[] = $cjOrderId . ': ' . $t->getMessage();
                $this->log('cj_order_detail_error', ['cj_order_id' => $cjOrderId, 'error' => $t->getMessage()]);
            }
        }

        try {
            $list = $this->cjService->listOrders($cjToken, ['pageNum' => '1', 'pageSize' => '50']);
            foreach ($this->normalizeCjOrderRows($list['data'] ?? []) as $row) {
                $orderNumber = $this->extractCjCustomerOrderNumber($row);
                $ebayOrderId = $this->extractEbayOrderIdFromCjNumber($orderNumber);
                $cjOrderId = $this->extractCjOrderId($row);
                if ($ebayOrderId === '' && $cjOrderId === '') {
                    continue;
                }
                $this->saveCjOrderPayload($row, $ebayOrderId);
                if ($cjOrderId !== '') {
                    $updated[] = $cjOrderId;
                }
            }
        } catch (Throwable $t) {
            $errors[] = 'CJ order list: ' . $t->getMessage();
            $this->log('cj_order_list_error', ['error' => $t->getMessage()]);
        }

        return [
            'checked' => $checked,
            'updated' => array_values(array_unique(array_filter($updated))),
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function syncCjTrackingToEbay(string $ebayToken, string $cjToken): array
    {
        $pending = $this->mappingRepo->getPendingOrderMaps();
        $synced = [];
        $errors = [];

        foreach ($pending as $map) {
            try {
                $cjOrderId = (string) ($map['cj_order_id'] ?? '');
                if ($cjOrderId === '') {
                    continue;
                }

                $info = $this->cjService->getTrackInfo($cjToken, ['orderId' => $cjOrderId]);
                $data = $info['data'] ?? [];
                if (is_array($data) && !isset($data['trackingNumber'], $data['trackNumber']) && isset($data[0]) && is_array($data[0])) {
                    $data = $data[0];
                }
                if (!is_array($data)) {
                    $data = [];
                }

                $tracking = $this->extractCjTrackingNumber($data);
                $carrier = $this->extractCjLogisticName($data);
                $trackingUrl = $this->extractCjTrackingUrl($data);

                if ($tracking === '') {
                    continue;
                }

                $lineItems = json_decode((string) ($map['ebay_line_items_json'] ?? ''), true);
                if (!is_array($lineItems) || $lineItems === []) {
                    $lineItems = [];
                    if ((string) ($map['ebay_line_item_id'] ?? '') !== '') {
                        $lineItems[] = [
                            'lineItemId' => (string) $map['ebay_line_item_id'],
                            'quantity' => 1,
                        ];
                    }
                }

                $fulfillmentLineItems = array_values(array_filter(array_map(
                    static fn (mixed $lineItem): array => is_array($lineItem) ? [
                        'lineItemId' => (string) ($lineItem['lineItemId'] ?? ''),
                        'quantity' => max(1, (int) ($lineItem['quantity'] ?? 1)),
                    ] : [],
                    $lineItems
                ), static fn (array $lineItem): bool => (string) ($lineItem['lineItemId'] ?? '') !== ''));

                if ($fulfillmentLineItems !== []) {
                    $this->ebayClient->request(
                        'POST',
                        '/sell/fulfillment/v1/order/' . rawurlencode((string) $map['ebay_order_id']) . '/shipping_fulfillment',
                        $ebayToken,
                        [],
                        ['Content-Type' => 'application/json'],
                        [
                            'lineItems' => $fulfillmentLineItems,
                            'shippedDate' => gmdate('c'),
                            'shippingCarrierCode' => $carrier !== '' ? $carrier : 'OTHER',
                            'trackingNumber' => $tracking,
                        ]
                    );
                }

                $this->mappingRepo->updateOrderMapTracking((int) $map['id'], $tracking, $carrier !== '' ? $carrier : 'OTHER', 'SHIPPED', $trackingUrl);
                $this->log('push_tracking_to_ebay', [
                    'ebay_order_id' => (string) $map['ebay_order_id'],
                    'cj_order_id' => $cjOrderId,
                    'tracking' => $tracking,
                    'carrier' => $carrier,
                ]);
                $synced[] = $cjOrderId;
            } catch (Throwable $t) {
                $this->log('tracking_sync_error', [
                    'cj_order_id' => (string) ($map['cj_order_id'] ?? ''),
                    'error' => $t->getMessage(),
                ]);
                $errors[] = (string) ($map['cj_order_id'] ?? '') . ': ' . $t->getMessage();
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /** @return array{payload: array<string, mixed>, products: list<array<string, mixed>>, line_items: list<array<string, mixed>>} */
    private function buildCjPayloadFromEbayOrder(array $order, string $cjOrderNumber): array
    {
        $cjVariants = [];
        $ebayLineItems = [];

        foreach ((array) ($order['lineItems'] ?? []) as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $itemId = (string) ($lineItem['legacyItemId'] ?? $lineItem['itemId'] ?? '');
            $map = $itemId !== '' ? $this->mappingRepo->findListingMapByItemId($itemId) : null;
            if ($map === null) {
                continue;
            }

            $skuMap = json_decode((string) ($map['sku_vid_map_json'] ?? ''), true);
            $vids = json_decode((string) ($map['cj_vids_json'] ?? '[]'), true) ?: [];
            $lineItemId = (string) ($lineItem['lineItemId'] ?? '');
            $sku = (string) ($lineItem['sku'] ?? '');
            $vid = is_array($skuMap) && $sku !== '' ? (string) ($skuMap[$sku] ?? '') : '';
            if ($vid === '') {
                $vid = (string) ($vids[0] ?? '');
            }

            if ($vid === '') {
                continue;
            }

            $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
            $cjVariants[] = ['vid' => $vid, 'quantity' => $quantity];
            if ($lineItemId !== '') {
                $ebayLineItems[] = [
                    'lineItemId' => $lineItemId,
                    'quantity' => $quantity,
                    'legacyItemId' => $itemId,
                    'sku' => $sku,
                    'cjVid' => $vid,
                ];
            }
        }

        $buyer = (array) (($order['fulfillmentStartInstructions'][0] ?? [])['shippingStep']['shipTo'] ?? []);
        $contact = (array) ($buyer['contactAddress'] ?? []);
        $payload = [
            'orderNumber' => $cjOrderNumber,
            'shippingZip' => (string) ($contact['postalCode'] ?? ''),
            'shippingCountryCode' => (string) ($contact['countryCode'] ?? 'US'),
            'shippingProvince' => (string) ($contact['stateOrProvince'] ?? ''),
            'shippingCity' => (string) ($contact['city'] ?? ''),
            'shippingAddress' => trim((string) ($contact['addressLine1'] ?? '') . ' ' . (string) ($contact['addressLine2'] ?? '')),
            'shippingCustomerName' => (string) ($buyer['fullName'] ?? ''),
            'shippingPhone' => (string) (($buyer['primaryPhone'] ?? [])['phoneNumber'] ?? '0000000000'),
            'remark' => 'Dropshipped from eBay by API',
            'products' => $cjVariants,
        ];

        return ['payload' => $payload, 'products' => $cjVariants, 'line_items' => $ebayLineItems];
    }

    /** @return array<string, mixed>|null */
    private function findCjOrderByOrderNumber(string $cjToken, string $orderNumber): ?array
    {
        if ($orderNumber === '') {
            return null;
        }

        foreach ([['orderNumber' => $orderNumber], ['orderNum' => $orderNumber]] as $query) {
            try {
                $response = $this->cjService->listOrders($cjToken, $query + ['pageNum' => '1', 'pageSize' => '20']);
                foreach ($this->normalizeCjOrderRows($response['data'] ?? []) as $row) {
                    if ($this->extractCjCustomerOrderNumber($row) === $orderNumber) {
                        return $row;
                    }
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $ebayOrder */
    private function saveCjOrderPayload(array $payload, string $ebayOrderId = '', ?array $ebayOrder = null, int $existingId = 0): void
    {
        $cjOrderId = $this->extractCjOrderId($payload);
        $cjOrderNumber = $this->extractCjCustomerOrderNumber($payload);
        if ($ebayOrderId === '') {
            $ebayOrderId = $this->extractEbayOrderIdFromCjNumber($cjOrderNumber);
        }

        $lineItems = [];
        $firstLineItemId = null;
        if (is_array($ebayOrder)) {
            $build = $this->buildCjPayloadFromEbayOrder($ebayOrder, $cjOrderNumber !== '' ? $cjOrderNumber : 'EBAY-' . $ebayOrderId);
            $lineItems = $build['line_items'];
            $firstLineItemId = isset($lineItems[0]['lineItemId']) ? (string) $lineItems[0]['lineItemId'] : null;
        }

        $row = [
            'ebay_order_id' => $ebayOrderId,
            'ebay_line_item_id' => $firstLineItemId,
            'cj_order_id' => $cjOrderId,
            'cj_order_number' => $cjOrderNumber,
            'cj_order_status' => $this->extractCjOrderStatus($payload),
            'logistic_name' => $this->extractCjLogisticName($payload),
            'tracking_number' => $this->extractCjTrackingNumber($payload),
            'shipping_carrier' => $this->extractCjLogisticName($payload),
            'tracking_url' => $this->extractCjTrackingUrl($payload),
            'last_cj_payload_json' => $payload,
        ];
        if ($lineItems !== []) {
            $row['ebay_line_items_json'] = $lineItems;
        }

        if ($existingId > 0) {
            $this->mappingRepo->updateOrderMapStatus($existingId, $row);
            return;
        }

        if ($ebayOrderId !== '' || $cjOrderId !== '') {
            $this->mappingRepo->saveOrderMap($row);
        }
    }

    /** @return list<array<string, mixed>> */
    private function normalizeCjOrderRows(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (!is_array($data)) {
            return [];
        }
        foreach (['list', 'orders', 'content', 'records', 'rows', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->normalizeCjOrderRows($data[$key]);
            }
        }
        return [];
    }

    private function extractCjOrderId(array $payload): string
    {
        foreach (['cjOrderId', 'orderId', 'id', 'orderCode'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extractCjCustomerOrderNumber(array $payload): string
    {
        foreach (['orderNumber', 'orderNum', 'customerOrderNumber'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extractEbayOrderIdFromCjNumber(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);
        if (stripos($orderNumber, 'EBAY-') === 0) {
            return substr($orderNumber, 5);
        }
        return '';
    }

    private function extractCjOrderStatus(array $payload): string
    {
        foreach (['orderStatus', 'status', 'shippingStatus', 'deliveryStatus'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extractCjLogisticName(array $payload): string
    {
        foreach (['logisticName', 'logisticsName', 'shippingMethod', 'carrier'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extractCjTrackingNumber(array $payload): string
    {
        foreach (['trackingNumber', 'trackNumber', 'tracking_number'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extractCjTrackingUrl(array $payload): string
    {
        foreach (['trackingUrl', 'trackUrl', 'tracking_url'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context): void
    {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents(
            self::LOG_PATH,
            json_encode([
                'time' => gmdate('c'),
                'event' => $event,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }
}
