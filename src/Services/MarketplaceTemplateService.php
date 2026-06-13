<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use DOMDocument;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class MarketplaceTemplateService
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const OFFICE_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @return array{path:string,headers:list<string>,rules:list<string>,categories:list<string>} */
    public function facebookMarketplaceTemplate(): array
    {
        $path = $this->resolveTemplatePath('FACEBOOK_MARKETPLACE_TEMPLATE_PATH', [
            'Marketplace_Bulk_Upload_Template.xlsx',
            'Marketplace_Bulk_Upload_Template (1).xlsx',
        ]);

        $headers = ['TITLE', 'PRICE', 'CONDITION', 'DESCRIPTION', 'CATEGORY', 'SHIPPING WEIGHT', 'OFFER FREE SHIPPING', 'OFFER SHIPPING'];
        $rules = [];
        $categories = [];

        if ($path !== '' && is_file($path)) {
            $workbook = $this->readWorkbook($path, ['Bulk Upload Template', 'VALIDATION']);
            $templateRows = $workbook['Bulk Upload Template']['rows'] ?? [];
            $validationRows = $workbook['VALIDATION']['rows'] ?? [];
            $headers = $this->nonEmptyRowValues($templateRows[4] ?? [], 1, 8) ?: $headers;
            $rules = $this->nonEmptyRowValues($templateRows[3] ?? [], 1, 8);
            $categories = $this->nonEmptyRowValues($validationRows[5] ?? [], 1, null);
        }

        return [
            'path' => $path,
            'headers' => $headers,
            'rules' => $rules,
            'categories' => $categories,
        ];
    }

    /** @return array{path:string,headers:list<string>,info_rows:list<list<string>>} */
    public function ebayCategoryListingTemplate(): array
    {
        $path = $this->resolveTemplatePath('EBAY_LISTING_CSV_TEMPLATE_PATH', [
            'eBay-category-listing-template-Jun-13-2026-12-35-9.csv',
        ]);

        $headers = [
            '*Action(SiteID=US|Country=US|Currency=USD|Version=1193|CC=UTF-8)',
            'CustomLabel',
            '*Category',
            '*Title',
            '*ConditionID',
            '*Description',
            '*Format',
            '*Duration',
            '*StartPrice',
            '*Quantity',
            '*Location',
            'PicURL',
        ];
        $infoRows = [['Info', 'Version=1.0.0', 'Template=fx_category_template_EBAY_US']];

        if ($path !== '' && is_file($path)) {
            $rows = [];
            $contents = file_get_contents($path);
            $lines = is_string($contents) ? preg_split('/\r\n|\n|\r/', $contents) : [];
            foreach ($lines ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $rows[] = array_map(
                    static fn (mixed $value): string => trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF"),
                    str_getcsv($line)
                );
                if (count($rows) >= 4) {
                    break;
                }
            }

            foreach ($rows as $row) {
                if ($row !== [] && str_starts_with((string) ($row[0] ?? ''), '*Action')) {
                    $headers = $row;
                    break;
                }
            }
            if (isset($rows[0]) && $rows[0] !== []) {
                $infoRows = [$rows[0]];
            }
        }

        return [
            'path' => $path,
            'headers' => $headers,
            'info_rows' => $infoRows,
        ];
    }

    /** @return array{path:string,machine_headers:list<string>,labels:list<string>,requirements:list<string>,categories:list<string>,metadata:list<string>} */
    public function tiktokShopTemplate(): array
    {
        return $this->tiktokShopTemplates()[0] ?? $this->fallbackTikTokTemplate('');
    }

    /** @return list<array{path:string,machine_headers:list<string>,labels:list<string>,requirements:list<string>,categories:list<string>,metadata:list<string>}> */
    public function tiktokShopTemplates(): array
    {
        $paths = [];
        $configured = trim(Env::get('TIKTOK_SHOP_TEMPLATE_PATH'));
        if ($configured !== '') {
            $paths[] = $configured;
        }

        $dir = trim(Env::get('TIKTOK_SHOP_TEMPLATE_DIR'));
        if ($dir === '') {
            $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
            if ($home !== '') {
                $dir = rtrim($home, "\\/") . DIRECTORY_SEPARATOR . 'Downloads';
            }
        }
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob(rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '*Tiktoksellercenter*template*.xlsx') ?: [] as $candidate) {
                $paths[] = $candidate;
            }
            foreach (glob(rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '*TikTok*template*.xlsx') ?: [] as $candidate) {
                $paths[] = $candidate;
            }
        }

        $templates = [];
        $seen = [];
        foreach ($paths as $path) {
            $real = is_file($path) ? realpath($path) : false;
            $key = $real !== false ? strtolower($real) : strtolower($path);
            if (isset($seen[$key]) || !is_file($path)) {
                continue;
            }
            $seen[$key] = true;
            $templates[] = $this->parseTikTokTemplate($path);
        }

        usort($templates, static function (array $left, array $right): int {
            return count($right['categories']) <=> count($left['categories']);
        });

        return $templates;
    }

    /** @return array{path:string,machine_headers:list<string>,labels:list<string>,requirements:list<string>,categories:list<string>,metadata:list<string>} */
    private function parseTikTokTemplate(string $path): array
    {
        $fallback = $this->fallbackTikTokTemplate($path);
        if ($path !== '' && is_file($path)) {
            $workbook = $this->readWorkbook($path, ['Template', 'Category']);
            $templateRows = $workbook['Template']['rows'] ?? [];
            $categoryRows = $workbook['Category']['rows'] ?? [];
            $machineHeaders = $this->nonEmptyRowValues($templateRows[1] ?? [], 1, null) ?: $fallback['machine_headers'];
            $metadata = $this->nonEmptyRowValues($templateRows[2] ?? [], 1, null) ?: $fallback['metadata'];
            $labels = $this->rowValuesByCount($templateRows[3] ?? [], count($machineHeaders)) ?: $fallback['labels'];
            $requirements = $this->rowValuesByCount($templateRows[4] ?? [], count($machineHeaders)) ?: $fallback['requirements'];
            $categories = [];
            foreach ($categoryRows as $row) {
                $category = trim((string) ($row[1] ?? ''));
                if ($category !== '') {
                    $categories[] = $category;
                }
            }
        }

        return [
            'path' => $path,
            'machine_headers' => $machineHeaders,
            'labels' => $labels,
            'requirements' => $requirements,
            'categories' => array_values(array_unique($categories)),
            'metadata' => $metadata,
        ];
    }

    /** @return array{path:string,machine_headers:list<string>,labels:list<string>,requirements:list<string>,categories:list<string>,metadata:list<string>} */
    private function fallbackTikTokTemplate(string $path): array
    {
        return [
            'path' => $path,
            'machine_headers' => ['category', 'brand', 'product_name', 'product_description', 'main_image', 'price', 'seller_sku'],
            'labels' => ['Category', 'Brand', 'Product name', 'Product description', 'Main image', 'Retail Price (Local Currency)', 'Seller SKU'],
            'requirements' => ['Mandatory', 'Optional', 'Mandatory', 'Mandatory', 'Mandatory', 'Mandatory', 'Optional'],
            'categories' => [],
            'metadata' => ['V5.0.2', 'create_product'],
        ];
    }

    /** @param list<array<string, string>> $rows */
    public function writeWorkbookRows(string $templatePath, string $sheetName, int $startRow, array $headers, array $rows, string $outputPath): bool
    {
        if ($templatePath === '' || !is_file($templatePath) || $headers === []) {
            return false;
        }
        if (!class_exists(ZipArchive::class)) {
            return false;
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        copy($templatePath, $outputPath);

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            return false;
        }

        try {
            $sheetPath = $this->resolveSheetPath($zip, $sheetName);
            if ($sheetPath === '') {
                $zip->close();
                return false;
            }

            $xml = $zip->getFromName($sheetPath);
            if (!is_string($xml) || $xml === '') {
                $zip->close();
                return false;
            }

            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            if (!$dom->loadXML($xml)) {
                $zip->close();
                return false;
            }

            $sheetData = $dom->getElementsByTagNameNS(self::SPREADSHEET_NS, 'sheetData')->item(0);
            if ($sheetData === null) {
                $zip->close();
                return false;
            }

            $remove = [];
            foreach ($sheetData->getElementsByTagNameNS(self::SPREADSHEET_NS, 'row') as $rowNode) {
                $rowIndex = (int) $rowNode->getAttribute('r');
                if ($rowIndex >= $startRow) {
                    $remove[] = $rowNode;
                }
            }
            foreach ($remove as $rowNode) {
                $sheetData->removeChild($rowNode);
            }

            $rowIndex = $startRow;
            foreach ($rows as $row) {
                $rowNode = $dom->createElementNS(self::SPREADSHEET_NS, 'row');
                $rowNode->setAttribute('r', (string) $rowIndex);
                foreach (array_values($headers) as $offset => $header) {
                    $cellValue = (string) ($row[$header] ?? '');
                    if ($cellValue === '') {
                        continue;
                    }
                    $cellNode = $dom->createElementNS(self::SPREADSHEET_NS, 'c');
                    $cellNode->setAttribute('r', $this->columnName($offset + 1) . $rowIndex);
                    $cellNode->setAttribute('t', 'inlineStr');
                    $inline = $dom->createElementNS(self::SPREADSHEET_NS, 'is');
                    $text = $dom->createElementNS(self::SPREADSHEET_NS, 't');
                    $text->appendChild($dom->createTextNode($cellValue));
                    $inline->appendChild($text);
                    $cellNode->appendChild($inline);
                    $rowNode->appendChild($cellNode);
                }
                $sheetData->appendChild($rowNode);
                $rowIndex++;
            }

            $dimension = $dom->getElementsByTagNameNS(self::SPREADSHEET_NS, 'dimension')->item(0);
            if ($dimension !== null) {
                $lastRow = max($startRow, $rowIndex - 1);
                $dimension->setAttribute('ref', 'A1:' . $this->columnName(count($headers)) . $lastRow);
            }

            $zip->addFromString($sheetPath, $dom->saveXML() ?: $xml);
            $zip->close();
            return true;
        } catch (\Throwable) {
            $zip->close();
            return false;
        }
    }

    /** @param list<string> $downloadNames */
    private function resolveTemplatePath(string $envName, array $downloadNames): string
    {
        $configured = trim(Env::get($envName));
        if ($configured !== '') {
            return $configured;
        }

        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        if ($home !== '') {
            foreach ($downloadNames as $name) {
                $candidate = rtrim($home, "\\/") . DIRECTORY_SEPARATOR . 'Downloads' . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /** @param list<string> $sheetNames @return array<string, array{rows: array<int, array<int, string>>, validations: list<array<string, string>>}> */
    private function readWorkbook(string $path, array $sheetNames = []): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read XLSX templates.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open workbook template: ' . $path);
        }

        try {
            $sharedStrings = $this->loadSharedStrings($zip);
            $sheetMap = $this->loadSheetMap($zip);
            $filter = $sheetNames !== [] ? array_flip($sheetNames) : [];
            $result = [];
            foreach ($sheetMap as $sheetName => $sheetPath) {
                if ($filter !== [] && !isset($filter[$sheetName])) {
                    continue;
                }
                $xml = $zip->getFromName($sheetPath);
                if (!is_string($xml) || $xml === '') {
                    continue;
                }
                $result[$sheetName] = $this->parseSheet($xml, $sharedStrings);
            }
            $zip->close();
            return $result;
        } catch (\Throwable $throwable) {
            $zip->close();
            throw $throwable;
        }
    }

    /** @return list<string> */
    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $root = simplexml_load_string($xml);
        if (!$root instanceof SimpleXMLElement) {
            return [];
        }
        $root->registerXPathNamespace('m', self::SPREADSHEET_NS);

        $strings = [];
        foreach ($root->xpath('//m:si') ?: [] as $item) {
            $item->registerXPathNamespace('m', self::SPREADSHEET_NS);
            $parts = [];
            foreach ($item->xpath('.//m:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /** @return array<string, string> */
    private function loadSheetMap(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($workbookXml) || !is_string($relsXml)) {
            return [];
        }

        $relsRoot = simplexml_load_string($relsXml);
        $relMap = [];
        if ($relsRoot instanceof SimpleXMLElement) {
            $relsRoot->registerXPathNamespace('rel', self::REL_NS);
            foreach ($relsRoot->xpath('//rel:Relationship') ?: [] as $rel) {
                $attrs = $rel->attributes();
                $relMap[(string) $attrs['Id']] = (string) $attrs['Target'];
            }
        }

        $workbookRoot = simplexml_load_string($workbookXml);
        if (!$workbookRoot instanceof SimpleXMLElement) {
            return [];
        }
        $workbookRoot->registerXPathNamespace('m', self::SPREADSHEET_NS);
        $workbookRoot->registerXPathNamespace('r', self::OFFICE_REL_NS);

        $sheets = [];
        foreach ($workbookRoot->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relAttrs = $sheet->attributes(self::OFFICE_REL_NS);
            $rid = (string) ($relAttrs['id'] ?? '');
            $target = $relMap[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            if (str_starts_with($target, '/xl/')) {
                $sheetPath = ltrim($target, '/');
            } elseif (str_starts_with($target, 'xl/')) {
                $sheetPath = $target;
            } else {
                $sheetPath = 'xl/' . ltrim($target, '/');
            }
            $sheets[(string) $attrs['name']] = $sheetPath;
        }

        return $sheets;
    }

    /** @return array{rows: array<int, array<int, string>>, validations: list<array<string, string>>} */
    private function parseSheet(string $xml, array $sharedStrings): array
    {
        $root = simplexml_load_string($xml);
        if (!$root instanceof SimpleXMLElement) {
            return ['rows' => [], 'validations' => []];
        }
        $root->registerXPathNamespace('m', self::SPREADSHEET_NS);

        $rows = [];
        foreach ($root->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $row->registerXPathNamespace('m', self::SPREADSHEET_NS);
            $rowAttrs = $row->attributes();
            $rowIndex = (int) ($rowAttrs['r'] ?? 0);
            if ($rowIndex <= 0) {
                continue;
            }
            $cells = [];
            foreach ($row->xpath('m:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('m', self::SPREADSHEET_NS);
                $attrs = $cell->attributes();
                $reference = (string) ($attrs['r'] ?? '');
                $column = $this->columnIndex($reference);
                if ($column <= 0) {
                    continue;
                }
                $type = (string) ($attrs['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? -1);
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('.//m:t') ?: [] as $text) {
                        $parts[] = (string) $text;
                    }
                    $value = implode('', $parts);
                } else {
                    $value = (string) ($cell->v ?? '');
                }
                $cells[$column] = $this->cleanCell($value);
            }
            $rows[$rowIndex] = $cells;
        }

        $validations = [];
        foreach ($root->xpath('//m:dataValidations/m:dataValidation') ?: [] as $validation) {
            $attrs = $validation->attributes();
            $validations[] = [
                'type' => (string) ($attrs['type'] ?? ''),
                'range' => (string) ($attrs['sqref'] ?? ''),
                'formula1' => $this->cleanCell((string) ($validation->formula1 ?? '')),
            ];
        }

        return ['rows' => $rows, 'validations' => $validations];
    }

    private function resolveSheetPath(ZipArchive $zip, string $sheetName): string
    {
        $sheetMap = $this->loadSheetMap($zip);
        return $sheetMap[$sheetName] ?? '';
    }

    /** @param array<int, string> $row @return list<string> */
    private function nonEmptyRowValues(array $row, int $startColumn, ?int $endColumn): array
    {
        $values = [];
        $last = $endColumn ?? (max(array_keys($row ?: [0])) ?: 0);
        for ($column = $startColumn; $column <= $last; $column++) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    /** @param array<int, string> $row @return list<string> */
    private function rowValuesByCount(array $row, int $count): array
    {
        $values = [];
        for ($column = 1; $column <= $count; $column++) {
            $values[] = trim((string) ($row[$column] ?? ''));
        }
        return array_filter($values, static fn (string $value): bool => $value !== '') !== [] ? $values : [];
    }

    private function columnIndex(string $cellReference): int
    {
        if (preg_match('/^([A-Z]+)/i', $cellReference, $matches) !== 1) {
            return 0;
        }
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }
        return $index;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function cleanCell(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)));
    }
}
