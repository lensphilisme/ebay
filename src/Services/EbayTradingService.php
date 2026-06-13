<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EbayConfig;
use RuntimeException;
use SimpleXMLElement;

final class EbayTradingService
{
    private const COMPATIBILITY_LEVEL = '1451';
    private const SITE_ID = '0';
    private const LOG_PATH = __DIR__ . '/../../database/logs/ebay-trading.log';

    public function __construct(private readonly EbayConfig $config)
    {
    }

    /** @return array<string, mixed> */
    public function getActiveListings(string $token, int $page = 1, int $entriesPerPage = 20, string $sort = 'TimeLeft'): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ActiveList>
    <Sort>{$sort}</Sort>
    <Pagination>
      <EntriesPerPage>{$entriesPerPage}</EntriesPerPage>
      <PageNumber>{$page}</PageNumber>
    </Pagination>
  </ActiveList>
</GetMyeBaySellingRequest>
XML;

        $result = $this->request('GetMyeBaySelling', $token, $xml);
        $activeList = $result['ActiveList'] ?? null;
        $pagination = is_array($activeList) ? (array) ($activeList['PaginationResult'] ?? []) : [];
        $items = [];
        $rawItems = is_array($activeList) ? ($activeList['ItemArray']['Item'] ?? []) : [];
        if (is_array($rawItems) && array_is_list($rawItems)) {
            $items = $rawItems;
        } elseif (is_array($rawItems) && $rawItems !== []) {
            $items = [$rawItems];
        }

        $listings = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = $item['SellingStatus']['CurrentPrice']['#text'] ?? $item['SellingStatus']['CurrentPrice'] ?? null;
            $currency = $item['SellingStatus']['CurrentPrice']['@currencyID'] ?? 'USD';
            $listings[] = [
                'itemId' => (string) ($item['ItemID'] ?? ''),
                'title' => (string) ($item['Title'] ?? ''),
                'sku' => (string) ($item['SKU'] ?? ''),
                'quantity' => (int) ($item['Quantity'] ?? 0),
                'quantityAvailable' => (int) ($item['QuantityAvailable'] ?? 0),
                'currentPrice' => $price !== null ? (string) $currency . ' ' . (string) $price : 'Not available',
                'watchCount' => (int) ($item['WatchCount'] ?? 0),
                'listingType' => (string) ($item['ListingType'] ?? ''),
            ];
        }

        return [
            'listings' => $listings,
            'total' => (int) ($pagination['TotalNumberOfEntries'] ?? count($listings)),
            'totalPages' => (int) ($pagination['TotalNumberOfPages'] ?? 1),
        ];
    }

    /** @return array<string, mixed> */
    public function getListing(string $token, string $itemId): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }
        if ($itemId === '') {
            throw new RuntimeException('itemId is required.');
        }

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ItemID>{$itemId}</ItemID>
  <DetailLevel>ReturnAll</DetailLevel>
</GetItemRequest>
XML;

        $result = $this->request('GetItem', $token, $xml);
        $item = $result['Item'] ?? $result;
        if (is_array($item) && array_is_list($item)) {
            return (array) ($item[0] ?? []);
        }

        return is_array($item) ? $item : [];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    public function createListing(string $token, array $item): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }

        $xml = $this->buildItemRequestXml('AddFixedPriceItemRequest', [
            'Item' => $item,
        ]);

        return $this->request('AddFixedPriceItem', $token, $xml);
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    public function reviseListing(string $token, string $itemId, array $fields): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }
        if ($itemId === '') {
            throw new RuntimeException('itemId is required.');
        }

        $item = $fields;
        $item['ItemID'] = $itemId;
        $xml = $this->buildItemRequestXml('ReviseFixedPriceItemRequest', [
            'Item' => $item,
        ]);

        return $this->request('ReviseFixedPriceItem', $token, $xml);
    }

    /**
     * @param list<array{ItemID:string,SKU?:string,Quantity:int,StartPrice?:string}> $inventoryStatuses
     * @return array<string, mixed>
     */
    public function reviseInventoryStatus(string $token, array $inventoryStatuses): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }
        if ($inventoryStatuses === []) {
            throw new RuntimeException('At least one inventory status row is required.');
        }

        $rows = [];
        foreach ($inventoryStatuses as $status) {
            $itemId = trim((string) ($status['ItemID'] ?? ''));
            if ($itemId === '') {
                continue;
            }

            $row = [
                'ItemID' => $itemId,
                'Quantity' => max(0, (int) ($status['Quantity'] ?? 0)),
            ];
            $sku = trim((string) ($status['SKU'] ?? ''));
            if ($sku !== '') {
                $row['SKU'] = $sku;
            }
            $price = trim((string) ($status['StartPrice'] ?? ''));
            if ($price !== '') {
                $row['StartPrice'] = $price;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('No usable inventory status rows were provided.');
        }

        $xml = $this->buildItemRequestXml('ReviseInventoryStatusRequest', [
            'InventoryStatus' => $rows,
        ]);

        return $this->request('ReviseInventoryStatus', $token, $xml);
    }

    /** @return array<string, mixed> */
    public function endListing(string $token, string $itemId, string $reason = 'NotAvailable'): array
    {
        if ($token === '') {
            throw new RuntimeException('Trading API requires a user token.');
        }
        if ($itemId === '') {
            throw new RuntimeException('itemId is required.');
        }

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<EndFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ItemID>{$itemId}</ItemID>
  <EndingReason>{$reason}</EndingReason>
</EndFixedPriceItemRequest>
XML;

        return $this->request('EndFixedPriceItem', $token, $xml);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $callName, string $token, string $xmlBody): array
    {
        $ch = curl_init(rtrim($this->config->apiBase, '/') . '/ws/api.dll');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-EBAY-API-SITEID: ' . self::SITE_ID,
            'X-EBAY-API-COMPATIBILITY-LEVEL: ' . self::COMPATIBILITY_LEVEL,
            'X-EBAY-API-CALL-NAME: ' . $callName,
            'X-EBAY-API-IAF-TOKEN: ' . $token,
            'Content-Type: text/xml',
        ]);
        if ($this->config->disableSslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            $this->logExchange($callName, $status, $xmlBody, $raw, [
                'transport_error' => $error !== '' ? $error : 'Empty response',
            ]);
            throw new RuntimeException('Trading API request failed: ' . ($error !== '' ? $error : 'Empty response'));
        }

        if ($status < 200 || $status >= 300) {
            $this->logExchange($callName, $status, $xmlBody, $raw, [
                'transport_error' => 'HTTP ' . $status,
            ]);
            throw new RuntimeException('Trading API request failed with HTTP ' . $status . '.');
        }

        $xml = @simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$xml instanceof SimpleXMLElement) {
            $this->logExchange($callName, $status, $xmlBody, $raw, [
                'parse_error' => 'Trading API returned invalid XML.',
            ]);
            throw new RuntimeException('Trading API returned invalid XML.');
        }

        $data = $this->xmlToArray($xml);
        $ack = (string) ($data['Ack'] ?? '');
        $issues = $this->normalizeIssues($data['Errors'] ?? []);
        $warnings = array_values(array_map(
            static fn (array $issue): string => (string) ($issue['message'] ?? 'Trading API warning'),
            array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? '') === 'Warning')
        ));
        $errors = array_values(array_map(
            static fn (array $issue): string => (string) ($issue['message'] ?? 'Trading API error'),
            array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? '') !== 'Warning')
        ));

        if ($warnings !== []) {
            $data['_warnings'] = $warnings;
        }

        $this->logExchange($callName, $status, $xmlBody, $raw, [
            'ack' => $ack,
            'warnings' => $warnings,
            'errors' => $errors,
        ]);

        if (($ack === 'Failure' || $ack === 'PartialFailure') && $errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
        }

        if ($ack === 'Failure' && $errors === [] && $warnings === []) {
            throw new RuntimeException('Trading API returned a failure response.');
        }

        return $data;
    }

    /**
     * @param mixed $rawErrors
     * @return array<int, array{severity:string, message:string, code:string}>
     */
    private function normalizeIssues(mixed $rawErrors): array
    {
        if (!is_array($rawErrors) || $rawErrors === []) {
            return [];
        }

        $entries = array_is_list($rawErrors) ? $rawErrors : [$rawErrors];
        $issues = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $issues[] = [
                'severity' => (string) ($entry['SeverityCode'] ?? ''),
                'message' => (string) ($entry['LongMessage'] ?? $entry['ShortMessage'] ?? 'Trading API issue'),
                'code' => (string) ($entry['ErrorCode'] ?? ''),
            ];
        }

        return $issues;
    }

    /** @param array<string, mixed> $context */
    private function logExchange(string $callName, int $status, string $requestXml, string|false $responseXml, array $context = []): void
    {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $payload = [
            'time' => gmdate('c'),
            'call' => $callName,
            'http_status' => $status,
            'context' => $context,
            'request_xml' => $requestXml,
            'response_xml' => is_string($responseXml) ? $responseXml : '',
        ];

        @file_put_contents(
            self::LOG_PATH,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }

    /** @param array<string, mixed> $payload */
    private function buildItemRequestXml(string $rootNode, array $payload): string
    {
        $xml = new SimpleXMLElement(sprintf('<?xml version="1.0" encoding="utf-8"?><%s xmlns="urn:ebay:apis:eBLBaseComponents"></%s>', $rootNode, $rootNode));
        $this->appendXml($xml, $payload);
        $rendered = $xml->asXML();
        if (!is_string($rendered)) {
            throw new RuntimeException('Failed to build Trading API XML.');
        }

        return $rendered;
    }

    /** @param array<string, mixed> $data */
    private function appendXml(SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $entry) {
                        $child = $xml->addChild((string) $key);
                        if (is_array($entry)) {
                            $this->appendXml($child, $entry);
                        } else {
                            $child[0] = htmlspecialchars((string) $entry, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                        }
                    }
                    continue;
                }

                $child = $xml->addChild((string) $key);
                $this->appendXml($child, $value);
                continue;
            }

            $xml->addChild((string) $key, htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
    }

    /** @return mixed */
    private function xmlToArray(SimpleXMLElement $xml): mixed
    {
        $children = $xml->children();
        if (count($children) === 0) {
            $attributes = $xml->attributes();
            if (count($attributes) === 0) {
                return trim((string) $xml);
            }

            $value = ['#text' => trim((string) $xml)];
            foreach ($attributes as $name => $attribute) {
                $value['@' . $name] = (string) $attribute;
            }
            return $value;
        }

        $result = [];
        foreach ($children as $name => $child) {
            $value = $this->xmlToArray($child);
            if (array_key_exists($name, $result)) {
                if (!is_array($result[$name]) || !array_is_list($result[$name])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
