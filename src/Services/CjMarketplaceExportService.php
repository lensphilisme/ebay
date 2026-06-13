<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;

final class CjMarketplaceExportService
{
    public function __construct(private readonly MarketplaceTemplateService $templates)
    {
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $options
     * @return array{files:list<array{target:string,path:string,relative:string,rows:int}>,errors:list<string>,warnings:list<string>}
     */
    public function export(array $products, array $options = []): array
    {
        $targets = array_values(array_filter(array_map('strval', (array) ($options['targets'] ?? ['ebay', 'facebook', 'tiktok']))));
        if ($targets === []) {
            $targets = ['ebay', 'facebook', 'tiktok'];
        }

        $stamp = date('Ymd-His');
        $directory = $this->exportDirectory();
        $baseName = 'cj-marketplace-export-' . $stamp;
        $files = [];
        $errors = [];
        $warnings = [];

        $normalized = [];
        foreach ($products as $product) {
            $normalized[] = $this->normalizeProduct($product, $options);
        }

        if (in_array('ebay', $targets, true)) {
            $result = $this->exportEbay($normalized, $options, $directory, $baseName);
            $files = array_merge($files, $result['files']);
            $errors = array_merge($errors, $result['errors']);
            $warnings = array_merge($warnings, $result['warnings']);
        }

        if (in_array('facebook', $targets, true)) {
            $result = $this->exportFacebook($normalized, $options, $directory, $baseName);
            $files = array_merge($files, $result['files']);
            $errors = array_merge($errors, $result['errors']);
            $warnings = array_merge($warnings, $result['warnings']);
        }

        if (in_array('tiktok', $targets, true)) {
            $result = $this->exportTikTok($normalized, $options, $directory, $baseName);
            $files = array_merge($files, $result['files']);
            $errors = array_merge($errors, $result['errors']);
            $warnings = array_merge($warnings, $result['warnings']);
        }

        $reportRows = [];
        foreach ($errors as $error) {
            $reportRows[] = ['level' => 'error', 'message' => $error];
        }
        foreach ($warnings as $warning) {
            $reportRows[] = ['level' => 'warning', 'message' => $warning];
        }
        if ($reportRows !== []) {
            $reportPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-validation.csv';
            $this->writeCsv($reportPath, ['level', 'message'], $reportRows);
            $files[] = $this->fileResult('validation', $reportPath, count($reportRows));
        }

        return ['files' => $files, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param list<array<string, mixed>> $products @return array{files:list<array{target:string,path:string,relative:string,rows:int}>,errors:list<string>,warnings:list<string>} */
    private function exportEbay(array $products, array $options, string $directory, string $baseName): array
    {
        $template = $this->templates->ebayCategoryListingTemplate();
        $headers = $this->appendEbayAspectHeaders($template['headers'], (array) ($options['ebay_aspects_by_category'] ?? []));
        $fallbackCategoryId = trim((string) ($options['ebay_category_id'] ?? ''));
        $conditionId = trim((string) ($options['condition_id'] ?? '1000'));
        $postalCode = trim((string) ($options['postal_code'] ?? '10001'));
        $rows = [];
        $errors = [];
        $warnings = [];

        foreach ($products as $product) {
            $categoryId = $this->marketplaceCategoryValue($product, 'ebay_id');
            if ($categoryId === '') {
                $categoryId = $fallbackCategoryId;
            }
            if ($categoryId === '') {
                $errors[] = 'eBay export could not map a category for CJ product ' . (string) $product['pid'] . '.';
            }

            foreach ($this->variantRows($product) as $variant) {
                $row = array_fill_keys($headers, '');
                $attrs = $variant['attributes'];
                $title = $this->variationTitle((string) $product['title'], $attrs);
                $price = $this->money($variant['price'] > 0 ? $variant['price'] : $product['price']);
                $quantity = max(1, min(50, (int) ($variant['inventory'] ?: $product['inventory'] ?: 1)));
                $images = $this->joinImages($product['images'], '|');

                $this->setByCandidates($row, ['*Action', 'Action'], 'Add');
                $this->setByCandidates($row, ['CustomLabel', 'Custom Label (SKU)', 'Custom Label'], (string) $variant['sku']);
                $this->setByCandidates($row, ['*Category', 'Category ID', 'Category'], $categoryId);
                $this->setByCandidates($row, ['*Title', 'Title'], $this->limit($title, 80));
                $this->setByCandidates($row, ['*ConditionID', 'Condition ID', 'ConditionID'], $conditionId);
                $this->setByCandidates($row, ['PicURL', 'Item photo URL', 'Item Photo URL'], $images);
                $this->setByCandidates($row, ['*Description', 'Description'], (string) $product['description']);
                $this->setByCandidates($row, ['*Format', 'Format'], 'FixedPrice');
                $this->setByCandidates($row, ['*Duration', 'Duration'], 'GTC');
                $this->setByCandidates($row, ['*StartPrice', 'Start price', 'StartPrice', 'Buy It Now price'], $price);
                $this->setByCandidates($row, ['*Quantity', 'Quantity'], (string) $quantity);
                $this->setByCandidates($row, ['*Location', 'Location'], $postalCode);
                $this->setByCandidates($row, ['ShippingType'], 'Flat');
                $this->setByCandidates($row, ['ShippingService-1:Option', 'Shipping service 1 option'], (string) ($options['shipping_service'] ?? 'UPSGround'));
                $this->setByCandidates($row, ['ShippingService-1:Cost', 'Shipping service 1 cost'], '0.00');
                $this->setByCandidates($row, ['*DispatchTimeMax', 'Max dispatch time'], (string) ($options['dispatch_days'] ?? '3'));
                $this->setByCandidates($row, ['*ReturnsAcceptedOption', 'Returns accepted option'], 'ReturnsAccepted');
                $this->fillEbaySpecifics($row, $product, $attrs);
                $rows[] = $row;

                foreach ($this->validateRequired($row, $headers, 'eBay ' . (string) $product['pid']) as $error) {
                    $errors[] = $error;
                }
            }
        }

        $path = $directory . DIRECTORY_SEPARATOR . $baseName . '-ebay-listing.csv';
        $this->writeCsv($path, $headers, $rows, $template['info_rows']);

        return [
            'files' => [$this->fileResult('ebay', $path, count($rows))],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param list<array<string, mixed>> $products @return array{files:list<array{target:string,path:string,relative:string,rows:int}>,errors:list<string>,warnings:list<string>} */
    private function exportFacebook(array $products, array $options, string $directory, string $baseName): array
    {
        $template = $this->templates->facebookMarketplaceTemplate();
        $headers = $template['headers'];
        $rows = [];
        $catalogRows = [];
        $errors = [];
        $warnings = [];
        $categoryOverride = trim((string) ($options['facebook_category_path'] ?? ''));

        foreach ($products as $product) {
            $category = $this->marketplaceCategoryValue($product, 'facebook_path');
            $category = $categoryOverride !== ''
                ? $categoryOverride
                : ($category !== '' ? $category : $this->bestCategory((string) $product['category'] . ' ' . (string) $product['title'], $template['categories']));
            if ($category === '') {
                $warnings[] = 'Facebook export could not map a category for CJ product ' . (string) $product['pid'] . '.';
            }

            $row = array_fill_keys($headers, '');
            $row['TITLE'] = $this->limit((string) $product['title'], 150);
            $row['PRICE'] = (string) max(1, (int) round((float) $product['price']));
            $row['CONDITION'] = 'New';
            $row['DESCRIPTION'] = $this->limit((string) $product['description'], 5000);
            $row['CATEGORY'] = $category;
            $row['SHIPPING WEIGHT'] = $this->decimal((float) $product['weight_lb'], 2);
            $row['OFFER FREE SHIPPING'] = 'No';
            $row['OFFER SHIPPING'] = 'Yes';
            $rows[] = $row;

            foreach ($this->validateRequired($row, ['TITLE', 'PRICE', 'CONDITION'], 'Facebook Marketplace ' . (string) $product['pid']) as $error) {
                $errors[] = $error;
            }

            foreach ($this->variantRows($product) as $variant) {
                $attrs = $variant['attributes'];
                $catalogRows[] = [
                    'id' => (string) $variant['sku'],
                    'title' => $this->variationTitle((string) $product['title'], $attrs),
                    'description' => (string) $product['description'],
                    'availability' => ((int) $variant['inventory'] > 0 || (int) $product['inventory'] > 0) ? 'in stock' : 'out of stock',
                    'condition' => 'new',
                    'price' => $this->money($variant['price'] > 0 ? $variant['price'] : $product['price']) . ' USD',
                    'link' => (string) $product['link'],
                    'image_link' => (string) ($product['images'][0] ?? ''),
                    'additional_image_link' => implode(',', array_slice($product['images'], 1, 20)),
                    'brand' => (string) $product['brand'],
                    'mpn' => (string) $variant['sku'],
                    'gtin' => '',
                    'google_product_category' => $this->marketplaceCategoryValue($product, 'google_product_category') ?: (string) ($options['google_product_category'] ?? ''),
                    'product_type' => (string) $product['category'],
                    'item_group_id' => (string) $product['pid'],
                    'color' => (string) ($attrs['Color'] ?? ''),
                    'size' => (string) ($attrs['Size'] ?? ''),
                    'material' => (string) ($attrs['Material'] ?? ''),
                    'inventory' => (string) max(0, (int) ($variant['inventory'] ?: $product['inventory'])),
                    'shipping' => 'US:::0.00 USD',
                ];
            }
        }

        $files = [];
        $marketplacePath = $directory . DIRECTORY_SEPARATOR . $baseName . '-facebook-marketplace.csv';
        $this->writeCsv($marketplacePath, $headers, $rows);
        $files[] = $this->fileResult('facebook_marketplace_csv', $marketplacePath, count($rows));

        if (($template['path'] ?? '') !== '') {
            $xlsxPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-facebook-marketplace.xlsx';
            if ($this->templates->writeWorkbookRows($template['path'], 'Bulk Upload Template', 5, $headers, $rows, $xlsxPath)) {
                $files[] = $this->fileResult('facebook_marketplace_xlsx', $xlsxPath, count($rows));
            } else {
                $warnings[] = 'Facebook XLSX template copy failed; CSV was still generated.';
            }
        }

        $catalogHeaders = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'brand', 'mpn', 'gtin', 'google_product_category', 'product_type', 'item_group_id', 'color', 'size', 'material', 'inventory', 'shipping'];
        $catalogPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-meta-catalog-feed.csv';
        $this->writeCsv($catalogPath, $catalogHeaders, $catalogRows);
        $files[] = $this->fileResult('meta_catalog_csv', $catalogPath, count($catalogRows));

        return ['files' => $files, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param list<array<string, mixed>> $products @return array{files:list<array{target:string,path:string,relative:string,rows:int}>,errors:list<string>,warnings:list<string>} */
    private function exportTikTok(array $products, array $options, string $directory, string $baseName): array
    {
        $templates = $this->templates->tiktokShopTemplates();
        if ($templates === []) {
            $templates = [$this->templates->tiktokShopTemplate()];
        }
        $categoryOverride = trim((string) ($options['tiktok_category_path'] ?? ''));
        $groups = [];
        $errors = [];
        $warnings = [];

        foreach ($products as $product) {
            [$template, $category] = $this->resolveTikTokTemplateAndCategory($product, $templates, $categoryOverride);
            if ($category === '') {
                $warnings[] = 'TikTok export could not map a category for CJ product ' . (string) $product['pid'] . '.';
                $this->queueMissingTikTokTemplate($product);
            }
            $headers = $template['machine_headers'];
            $key = $this->templateGroupKey($template);
            if (!isset($groups[$key])) {
                $groups[$key] = ['template' => $template, 'rows' => []];
            }

            foreach ($this->variantRows($product) as $variant) {
                $row = $this->buildTikTokRow($headers, $product, $variant, $category);
                $groups[$key]['rows'][] = $row;

                foreach ($this->validateRequiredByTemplate($row, $template['requirements'], $headers, 'TikTok ' . (string) $product['pid']) as $error) {
                    $errors[] = $error;
                }
            }
        }

        $files = [];
        $groupIndex = 1;
        foreach ($groups as $group) {
            $template = (array) $group['template'];
            $headers = (array) $template['machine_headers'];
            $rows = (array) $group['rows'];
            $suffix = count($groups) > 1 ? '-tiktok-shop-' . $groupIndex : '-tiktok-shop';
            $csvPath = $directory . DIRECTORY_SEPARATOR . $baseName . $suffix . '.csv';
            $prelude = [$headers, (array) $template['metadata'], (array) $template['labels'], (array) $template['requirements']];
            $this->writeCsv($csvPath, $headers, $rows, $prelude);
            $files[] = $this->fileResult('tiktok_csv', $csvPath, count($rows));

            if (($template['path'] ?? '') !== '') {
                $xlsxPath = $directory . DIRECTORY_SEPARATOR . $baseName . $suffix . '.xlsx';
                if ($this->templates->writeWorkbookRows((string) $template['path'], 'Template', 7, $headers, $rows, $xlsxPath)) {
                    $files[] = $this->fileResult('tiktok_xlsx', $xlsxPath, count($rows));
                } else {
                    $warnings[] = 'TikTok XLSX template copy failed for ' . basename((string) $template['path']) . '; CSV was still generated.';
                }
            } else {
                $warnings[] = 'No TikTok XLSX template matched one export group; CSV was still generated.';
            }
            $groupIndex++;
        }

        return ['files' => $files, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function normalizeProduct(array $product, array $options): array
    {
        $pid = trim((string) ($product['pid'] ?? $product['productId'] ?? $product['id'] ?? ''));
        $title = $this->firstString($product, ['productNameEn', 'productName', 'title', 'productTitle']);
        $description = $this->htmlToText($this->firstString($product, ['description', 'productDescription', 'productDescriptionEn', 'productTitle']));
        $images = $this->extractImages($product);
        $variants = $this->normalizeVariants((array) ($product['_variants'] ?? $product['variants'] ?? $product['variantList'] ?? []));
        $priceOverrides = (array) ($options['price_overrides'] ?? []);
        $price = isset($priceOverrides[$pid]) ? $this->numeric($priceOverrides[$pid]) : 0.0;
        if ($price <= 0) {
            $price = $this->numeric($product['ebayPrice'] ?? $product['suggestedPrice'] ?? $product['productSellPrice'] ?? $product['sellPrice'] ?? $product['minPrice'] ?? $product['price'] ?? 0);
        }
        if ($price <= 0 && isset($variants[0])) {
            $price = $this->numeric($variants[0]['price'] ?? 0);
        }

        $weightGrams = $this->numeric($product['weight'] ?? $product['wrapWeight'] ?? $product['productWeight'] ?? 454);
        if ($weightGrams <= 0) {
            $weightGrams = 454;
        }

        return [
            'pid' => $pid,
            'title' => $title !== '' ? $title : 'CJ product ' . $pid,
            'description' => $description !== '' ? $description : $title,
            'images' => $images,
            'price' => $price,
            'inventory' => (int) $this->numeric($product['warehouseInventoryNum'] ?? $product['inventoryNum'] ?? $product['totalInventoryNum'] ?? 1),
            'category' => $this->firstString($product, ['categoryName', 'category', 'categoryPath']),
            'brand' => $this->firstString($product, ['brandName', 'brand']) ?: 'Unbranded',
            'link' => $this->firstString($product, ['productUrl', 'sourceUrl', 'url']) ?: rtrim(Env::get('APP_BASE_URL'), '/') . '/ebay/?page=listings&cj_pid=' . rawurlencode($pid),
            'weight_lb' => max(0.1, $weightGrams / 453.59237),
            'length_in' => max(1.0, $this->numeric($product['length'] ?? 10)),
            'width_in' => max(1.0, $this->numeric($product['width'] ?? 8)),
            'height_in' => max(1.0, $this->numeric($product['height'] ?? 2)),
            'variants' => $variants,
            'marketplace_categories' => (array) ($product['_marketplaceCategories'] ?? ($options['marketplace_categories'][$pid] ?? [])),
        ];
    }

    /** @param list<array<string, mixed>> $variants @return list<array<string, mixed>> */
    private function normalizeVariants(array $variants): array
    {
        $rows = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $sku = $this->firstString($variant, ['sku', 'variantSku', 'vid', 'variantId']);
            if ($sku === '') {
                $sku = 'CJ-SKU-' . ($index + 1);
            }
            $key = $this->firstString($variant, ['variantKey', 'variantKeyEn', 'variantNameEn', 'variantName', 'name']);
            $attrs = CjVariantParser::parseVariantKey($key);
            foreach (['Color' => ['color', 'colour'], 'Size' => ['size'], 'Material' => ['material']] as $name => $keys) {
                foreach ($keys as $keyName) {
                    $value = $this->firstString($variant, [$keyName]);
                    if ($value !== '' && !isset($attrs[$name])) {
                        $attrs[$name] = $value;
                    }
                }
            }
            $rows[] = [
                'sku' => $sku,
                'price' => $this->numeric($variant['variantSellPrice'] ?? $variant['sellPrice'] ?? $variant['price'] ?? 0),
                'inventory' => (int) $this->numeric($variant['inventoryNum'] ?? $variant['warehouseInventoryNum'] ?? $variant['quantity'] ?? 0),
                'attributes' => $attrs,
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $product @return list<array{sku:string,price:float,inventory:int,attributes:array<string,string>}> */
    private function variantRows(array $product): array
    {
        $variants = (array) ($product['variants'] ?? []);
        if ($variants === []) {
            return [[
                'sku' => (string) (($product['pid'] ?? '') !== '' ? 'CJ-' . $product['pid'] : 'CJ-SKU'),
                'price' => (float) ($product['price'] ?? 0),
                'inventory' => (int) ($product['inventory'] ?? 1),
                'attributes' => [],
            ]];
        }

        $rows = [];
        foreach ($variants as $variant) {
            $rows[] = [
                'sku' => (string) ($variant['sku'] ?? 'CJ-SKU'),
                'price' => (float) ($variant['price'] ?? 0),
                'inventory' => (int) ($variant['inventory'] ?? 0),
                'attributes' => array_map('strval', (array) ($variant['attributes'] ?? [])),
            ];
        }
        return $rows;
    }

    /** @param list<string> $headers @param array<string, mixed> $aspectsByCategory @return list<string> */
    private function appendEbayAspectHeaders(array $headers, array $aspectsByCategory): array
    {
        $known = [];
        foreach ($headers as $header) {
            $known[strtolower(ltrim((string) $header, '*'))] = true;
        }

        foreach ($aspectsByCategory as $aspects) {
            foreach ((array) $aspects as $aspect) {
                if (!is_array($aspect)) {
                    continue;
                }
                $name = trim((string) ($aspect['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = 'c:' . strtolower($name);
                if (isset($known[$key])) {
                    continue;
                }
                $headers[] = ((bool) ($aspect['required'] ?? false) ? '*C:' : 'C:') . $name;
                $known[$key] = true;
            }
        }

        return $headers;
    }

    private function marketplaceCategoryValue(array $product, string $key): string
    {
        $categories = (array) ($product['marketplace_categories'] ?? []);
        return trim((string) ($categories[$key] ?? ''));
    }

    /** @param list<array<string, mixed>> $templates @return array{0:array<string,mixed>,1:string} */
    private function resolveTikTokTemplateAndCategory(array $product, array $templates, string $override): array
    {
        $fallback = (array) ($templates[0] ?? $this->templates->tiktokShopTemplate());
        if ($override !== '') {
            return [$this->findTikTokTemplateForCategory($override, $templates) ?: $fallback, $override];
        }

        $mapped = $this->marketplaceCategoryValue($product, 'tiktok_path');
        if ($mapped !== '') {
            return [$this->findTikTokTemplateForCategory($mapped, $templates) ?: $fallback, $mapped];
        }

        $needle = (string) $product['category'] . ' ' . (string) $product['title'];
        $bestTemplate = $fallback;
        $bestCategory = '';
        $bestScore = -1;
        foreach ($templates as $template) {
            $match = $this->bestCategoryMatch($needle, (array) ($template['categories'] ?? []));
            if ($match['score'] > $bestScore) {
                $bestScore = $match['score'];
                $bestTemplate = $template;
                $bestCategory = $match['category'];
            }
        }

        if ($bestScore < 25) {
            return [$bestTemplate, ''];
        }

        return [$bestTemplate, $bestCategory];
    }

    /** @param list<array<string, mixed>> $templates */
    private function findTikTokTemplateForCategory(string $category, array $templates): ?array
    {
        $needle = strtolower(trim($category));
        foreach ($templates as $template) {
            foreach ((array) ($template['categories'] ?? []) as $candidate) {
                $candidateText = strtolower(trim((string) $candidate));
                if ($candidateText === $needle || str_contains($candidateText, $needle) || str_contains($needle, $candidateText)) {
                    return $template;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $template */
    private function templateGroupKey(array $template): string
    {
        $path = trim((string) ($template['path'] ?? ''));
        return $path !== '' ? md5(strtolower($path)) : 'fallback';
    }

    /** @param list<string> $headers @param array<string, mixed> $product @param array{sku:string,price:float,inventory:int,attributes:array<string,string>} $variant @return array<string,string> */
    private function buildTikTokRow(array $headers, array $product, array $variant, string $category): array
    {
        $attrs = $variant['attributes'];
        $row = array_fill_keys($headers, '');
        $this->putIfHeader($row, 'category', $category);
        $this->putIfHeader($row, 'brand', (string) $product['brand']);
        $this->putIfHeader($row, 'product_name', $this->limit($this->variationTitle((string) $product['title'], $attrs), 255));
        $this->putIfHeader($row, 'product_description', (string) $product['description']);
        $this->putIfHeader($row, 'main_image', (string) ($product['images'][0] ?? ''));
        for ($i = 2; $i <= 9; $i++) {
            $this->putIfHeader($row, 'image_' . $i, (string) ($product['images'][$i - 1] ?? ''));
        }
        $this->putIfHeader($row, 'gtin_type', '');
        $this->putIfHeader($row, 'gtin_code', '');
        $keys = array_keys($attrs);
        $values = array_values($attrs);
        $this->putIfHeader($row, 'property_name_1', isset($keys[0]) ? (string) $keys[0] : '');
        $this->putIfHeader($row, 'property_value_1', isset($values[0]) ? (string) $values[0] : '');
        $this->putIfHeader($row, 'property_name_2', isset($keys[1]) ? (string) $keys[1] : '');
        $this->putIfHeader($row, 'property_value_2', isset($values[1]) ? (string) $values[1] : '');
        $this->putIfHeader($row, 'parcel_weight', $this->decimal((float) $product['weight_lb'], 2));
        $this->putIfHeader($row, 'parcel_length', $this->decimal((float) $product['length_in'], 2));
        $this->putIfHeader($row, 'parcel_width', $this->decimal((float) $product['width_in'], 2));
        $this->putIfHeader($row, 'parcel_height', $this->decimal((float) $product['height_in'], 2));
        $this->putIfHeader($row, 'delivery', 'Default');
        $this->putIfHeader($row, 'price', $this->money($variant['price'] > 0 ? $variant['price'] : (float) $product['price']));
        $this->putIfHeader($row, 'list_price', '');
        foreach (array_keys($row) as $header) {
            if (str_starts_with($header, 'warehouse_quantity/')) {
                $row[$header] = (string) max(0, (int) ($variant['inventory'] ?: $product['inventory']));
                break;
            }
        }
        $this->putIfHeader($row, 'seller_sku', (string) $variant['sku']);
        $this->putIfHeader($row, 'product_property/100198', (string) ($attrs['Pattern'] ?? ''));
        $this->putIfHeader($row, 'product_property/100548', (string) ($attrs['Shape'] ?? ''));
        $this->putIfHeader($row, 'product_property/100701', (string) ($attrs['Material'] ?? ''));

        return array_map('strval', $row);
    }

    /** @param array<string, string> $row */
    private function fillEbaySpecifics(array &$row, array $product, array $attrs): void
    {
        foreach (array_keys($row) as $header) {
            $clean = ltrim($header, '*');
            if (!str_starts_with($clean, 'C:')) {
                continue;
            }
            $name = substr($clean, 2);
            $value = match (strtolower($name)) {
                'brand' => (string) $product['brand'],
                'color' => (string) ($attrs['Color'] ?? 'Multicolor'),
                'size', 'us shoe size' => (string) ($attrs['Size'] ?? 'One Size'),
                'material', 'upper material' => (string) ($attrs['Material'] ?? 'Synthetic'),
                'department' => 'Unisex Adults',
                'style' => (string) ($attrs['Style'] ?? 'Modern'),
                'type' => $this->categoryLeaf((string) $product['category']) ?: 'Accessory',
                'mpn' => (string) ($attrs['MPN'] ?? 'Does Not Apply'),
                default => (string) ($attrs[$name] ?? ''),
            };
            if ($value !== '') {
                $row[$header] = $value;
            }
        }
    }

    /** @param array<string, string> $row @param list<string> $candidates */
    private function setByCandidates(array &$row, array $candidates, string $value): void
    {
        foreach (array_keys($row) as $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate || str_starts_with($header, $candidate)) {
                    $row[$header] = $value;
                    return;
                }
            }
        }
    }

    /** @param array<string, string> $row */
    private function putIfHeader(array &$row, string $header, string $value): void
    {
        if (array_key_exists($header, $row)) {
            $row[$header] = $value;
        }
    }

    /** @param list<string> $headers @param list<array<string,string>> $rows @param list<list<string>> $prelude */
    private function writeCsv(string $path, array $headers, array $rows, array $prelude = []): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write export CSV: ' . $path);
        }
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($prelude as $row) {
            fputcsv($handle, $row);
        }
        if ($prelude === []) {
            fputcsv($handle, $headers);
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = (string) ($row[$header] ?? '');
            }
            fputcsv($handle, $values);
        }
        fclose($handle);
    }

    /** @param list<string> $requiredHeaders @return list<string> */
    private function validateRequired(array $row, array $requiredHeaders, string $label): array
    {
        $errors = [];
        foreach ($requiredHeaders as $header) {
            if (!str_starts_with($header, '*') && !in_array($header, ['TITLE', 'PRICE', 'CONDITION'], true)) {
                continue;
            }
            if (trim((string) ($row[$header] ?? '')) === '') {
                $errors[] = $label . ' missing required field: ' . $header;
            }
        }
        return $errors;
    }

    /** @param list<string> $requirements @param list<string> $headers @return list<string> */
    private function validateRequiredByTemplate(array $row, array $requirements, array $headers, string $label): array
    {
        $errors = [];
        foreach ($headers as $index => $header) {
            $requirement = strtolower((string) ($requirements[$index] ?? ''));
            if ($requirement !== 'mandatory') {
                continue;
            }
            if (trim((string) ($row[$header] ?? '')) === '') {
                $errors[] = $label . ' missing TikTok mandatory field: ' . $header;
            }
        }
        return $errors;
    }

    /** @param list<string> $categories */
    private function bestCategory(string $needle, array $categories): string
    {
        return $this->bestCategoryMatch($needle, $categories)['category'];
    }

    /** @param list<string> $categories @return array{category:string,score:int} */
    private function bestCategoryMatch(string $needle, array $categories): array
    {
        $needleTokens = $this->tokens($needle);
        $best = '';
        $bestScore = 0;
        foreach ($categories as $category) {
            $tokens = $this->tokens($category);
            $score = count(array_intersect($needleTokens, $tokens)) * 10;
            similar_text(strtolower($needle), strtolower($category), $percent);
            $score += (int) round($percent);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $category;
            }
        }
        return ['category' => $best, 'score' => $bestScore];
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];
        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => strlen($part) > 2)));
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @return list<string> */
    private function extractImages(array $product): array
    {
        $values = [];
        foreach (['image', 'productImage', 'productImageSet', 'productImages', 'images', 'imageList', 'variantImage'] as $key) {
            if (!array_key_exists($key, $product)) {
                continue;
            }
            $raw = $product[$key];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $raw = preg_split('/[,\|]+/', $raw) ?: [];
                }
            }
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_array($item)) {
                        $item = $item['url'] ?? $item['image'] ?? $item['src'] ?? '';
                    }
                    $url = trim((string) $item);
                    if ($url !== '' && preg_match('/^https?:\/\//i', $url) === 1) {
                        $values[] = $url;
                    }
                }
            }
        }
        return array_values(array_unique($values));
    }

    private function variationTitle(string $title, array $attrs): string
    {
        $parts = [];
        foreach (['Color', 'Size', 'Material', 'Style'] as $key) {
            if (isset($attrs[$key]) && trim((string) $attrs[$key]) !== '') {
                $parts[] = trim((string) $attrs[$key]);
            }
        }
        return $parts === [] ? $title : $title . ' - ' . implode(' ', array_unique($parts));
    }

    private function joinImages(array $images, string $separator): string
    {
        return implode($separator, array_slice(array_values(array_filter(array_map('strval', $images))), 0, 12));
    }

    private function categoryLeaf(string $category): string
    {
        $parts = preg_split('/\s*(?:>|\/\/|\/)\s*/', $category) ?: [];
        $leaf = trim((string) end($parts));
        return $leaf;
    }

    private function numeric(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }
        if (preg_match('/\d+(?:\.\d+)?/', str_replace(',', '', (string) $value), $matches) !== 1) {
            return 0.0;
        }
        return max(0.0, (float) $matches[0]);
    }

    private function money(float $value): string
    {
        return number_format(max(0.01, $value), 2, '.', '');
    }

    private function decimal(float $value, int $places): string
    {
        return number_format(max(0.0, $value), $places, '.', '');
    }

    private function limit(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return strlen($value) > $max ? substr($value, 0, $max - 1) : $value;
    }

    private function htmlToText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function exportDirectory(): string
    {
        $dir = trim(Env::get('MARKETPLACE_EXPORT_DIR', 'database/exports'));
        if ($dir === '') {
            $dir = 'database/exports';
        }
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $dir) !== 1 && !str_starts_with($dir, DIRECTORY_SEPARATOR)) {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** @param array<string, mixed> $product */
    private function queueMissingTikTokTemplate(array $product): void
    {
        $path = trim(Env::get('TIKTOK_TEMPLATE_REQUEST_QUEUE', 'database/template-requests/tiktok-missing-categories.json'));
        if ($path === '') {
            return;
        }
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1 && !str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $entries = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $entries = array_values(array_filter($decoded, 'is_array'));
            }
        }

        $category = trim((string) ($product['category'] ?? ''));
        $title = trim((string) ($product['title'] ?? ''));
        $key = md5(strtolower($category . '|' . $title));
        foreach ($entries as $entry) {
            if ((string) ($entry['key'] ?? '') === $key) {
                return;
            }
        }

        $entries[] = [
            'key' => $key,
            'pid' => (string) ($product['pid'] ?? ''),
            'title' => $title,
            'cj_category' => $category,
            'search_text' => trim($category . ' ' . $title),
            'status' => 'missing_template',
            'created_at' => date('c'),
        ];

        file_put_contents($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{target:string,path:string,relative:string,rows:int} */
    private function fileResult(string $target, string $path, int $rows): array
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
        return [
            'target' => $target,
            'path' => $path,
            'relative' => str_replace('\\', '/', $relative),
            'rows' => $rows,
        ];
    }
}
