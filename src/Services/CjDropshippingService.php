<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EbayConfig;
use RuntimeException;

class CjDropshippingService
{
    private static array $freightCache = [];
    private static array $freightCacheTimestamps = [];
    private static ?int $lastRequestTime = null;

    public function __construct(private readonly EbayConfig $config)
    {
    }

    /** @return array<string, mixed> */
    public function authenticate(string $apiKey): array
    {
        if ($apiKey === '') {
            throw new RuntimeException('CJ API key is required.');
        }

        return $this->request('POST', '/authentication/getAccessToken', null, [], [
            'apiKey' => $apiKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function refreshAccessToken(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw new RuntimeException('CJ refresh token is required.');
        }

        return $this->request('POST', '/authentication/refreshAccessToken', null, [], [
            'refreshToken' => $refreshToken,
        ]);
    }

    /** @return array<string, mixed> */
    public function getSettings(string $accessToken): array
    {
        return $this->request('GET', '/setting/get', $accessToken);
    }

    /** @return array<string, mixed> */
    public function getCategory(string $accessToken): array
    {
        return $this->request('GET', '/product/getCategory', $accessToken);
    }

    /** @return array<string, mixed> */
    public function listProducts(string $accessToken, array $query = []): array
    {
        return $this->request('GET', '/product/listV2', $accessToken, $query);
    }

    /** @return array<string, mixed> */
    public function getProductDetail(string $accessToken, array $query): array
    {
        return $this->request('GET', '/product/query', $accessToken, $query);
    }

    /** @return array<string, mixed> */
    public function getVariants(string $accessToken, array $query): array
    {
        return $this->request('GET', '/product/variant/query', $accessToken, $query);
    }

    /** @return array<string, mixed> */
    public function getVariantByVid(string $accessToken, string $vid): array
    {
        return $this->request('GET', '/product/variant/queryByVid', $accessToken, ['vid' => $vid, 'features' => 'enable_inventory']);
    }

    /** @return array<string, mixed> */
    public function getStockByVid(string $accessToken, string $vid): array
    {
        return $this->request('GET', '/product/stock/queryByVid', $accessToken, ['vid' => $vid]);
    }

    /** @return array<string, mixed> */
    public function getStockBySku(string $accessToken, string $sku): array
    {
        return $this->request('GET', '/product/stock/queryBySku', $accessToken, ['sku' => $sku]);
    }

    /** @return array<string, mixed> */
    public function getInventoryByProductId(string $accessToken, string $pid): array
    {
        return $this->request('GET', '/product/stock/getInventoryByPid', $accessToken, ['pid' => $pid]);
    }

    /** @return array<string, mixed> */
    public function listOrders(string $accessToken, array $query = []): array
    {
        return $this->request('GET', '/shopping/order/list', $accessToken, $query);
    }

    /** @return array<string, mixed> */
    public function getOrderDetail(string $accessToken, string $orderId): array
    {
        return $this->request('GET', '/shopping/order/getOrderDetail', $accessToken, ['orderId' => $orderId]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrderV2(string $accessToken, array $payload, string $platformToken = ''): array
    {
        $headers = [];
        if ($platformToken !== '') {
            $headers['platformToken'] = $platformToken;
        }

        return $this->request('POST', '/shopping/order/createOrderV2', $accessToken, [], $payload, $headers);
    }

    /** @return array<string, mixed> */
    public function confirmOrder(string $accessToken, string $orderId): array
    {
        return $this->request('PATCH', '/shopping/order/confirmOrder', $accessToken, [], ['orderId' => $orderId]);
    }

    /** @return array<string, mixed> */
    public function getTrackInfo(string $accessToken, array $query): array
    {
        return $this->request('GET', '/logistic/trackInfo', $accessToken, $query);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function setWebhook(string $accessToken, array $payload): array
    {
        return $this->request('POST', '/webhook/set', $accessToken, [], $payload);
    }

    /** @return array<string, mixed> */
    public function calculateFreightTip(string $accessToken, string $sku, string $srcCountryCode, string $destCountryCode, array $productData = [], int $quantity = 1): array
    {
        $cacheKey = $sku . '|' . $srcCountryCode . '|' . $destCountryCode;
        $now = time();

        // Check cache (24 hours = 86400 seconds)
        if (isset(self::$freightCache[$cacheKey]) && isset(self::$freightCacheTimestamps[$cacheKey])) {
            if ($now - self::$freightCacheTimestamps[$cacheKey] < 86400) {
                error_log('Freight calculation using cache for sku: ' . $sku);
                return self::$freightCache[$cacheKey];
            }
        }

        // Add rate limiting delay (CJ API limit: 1 request per second)
        if (isset(self::$lastRequestTime)) {
            $timeSinceLastRequest = $now - self::$lastRequestTime;
            if ($timeSinceLastRequest < 1) {
                usleep((1 - $timeSinceLastRequest) * 1000000); // Sleep remaining microseconds
            }
        }
        self::$lastRequestTime = time();

        // Use freightCalculateTip endpoint with SKU
        $payload = [
            'reqDTOS' => [
                [
                    'srcAreaCode' => $srcCountryCode,
                    'destAreaCode' => $destCountryCode,
                    'length' => (float) ($productData['length'] ?? 0.3),
                    'width' => (float) ($productData['width'] ?? 0.4),
                    'height' => (float) ($productData['height'] ?? 0.5),
                    'volume' => (float) ($productData['volume'] ?? 0.06),
                    'weight' => (int) ($productData['weight'] ?? 100),
                    'wrapWeight' => (int) ($productData['wrapWeight'] ?? 100),
                    'totalGoodsAmount' => (float) ($productData['price'] ?? 10.0),
                    'productProp' => ['COMMON'],
                    'freightTrialSkuList' => [
                        [
                            'skuQuantity' => $quantity,
                            'sku' => $sku,
                        ],
                    ],
                    'skuList' => [$sku],
                    'platforms' => ['Shopify'],
                ],
            ],
        ];

        error_log('Freight API request payload: ' . json_encode($payload));

        try {
            $response = $this->request('POST', '/logistic/freightCalculateTip', $accessToken, [], $payload);

            error_log('Freight API full response: ' . json_encode($response));

            // Cache the result
            self::$freightCache[$cacheKey] = $response;
            self::$freightCacheTimestamps[$cacheKey] = $now;

            return $response;
        } catch (RuntimeException $e) {
            error_log('Freight API exception: ' . $e->getMessage());
            // Return error response that can be handled by caller
            return [
                'code' => 0,
                'result' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?string $accessToken = null, array $query = [], ?array $body = null, array $extraHeaders = []): array
    {
        $url = rtrim($this->config->cjApiBase, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $headers[] = 'CJ-Access-Token: ' . $accessToken;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->config->disableSslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('CJ request failed: ' . ($error !== '' ? $error : 'Empty response'));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('CJ returned invalid JSON.');
        }

        $decoded['_http_status'] = $status;
        if ($status < 200 || $status >= 300 || (($decoded['result'] ?? $decoded['success'] ?? true) === false)) {
            $message = (string) ($decoded['message'] ?? 'CJ API error');
            throw new RuntimeException($message !== '' ? $message : 'CJ API error');
        }

        return $decoded;
    }
}
