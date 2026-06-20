import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import * as XLSX from 'xlsx';
import type { EbayTradingPayload, EbayTradingVariation } from '@/backend/services/ebay-listing-engine/types';

export type MarketplaceExportTarget = 'ebay' | 'facebook' | 'tiktok';
export type MarketplaceExportFormat = 'csv' | 'xls' | 'xlsx';

export interface MarketplaceExportSource {
  item: Record<string, unknown>;
  detail?: unknown;
  listing?: unknown;
}

export interface MarketplaceExportResult {
  filename: string;
  mimeType: string;
  content: string;
  encoding: 'text' | 'base64';
  rows: number;
  warnings: string[];
}

interface ExportVariant {
  sku: string;
  quantity: number;
  price: number;
  attributes: Record<string, string>;
  imageUrls: string[];
  weightGrams: number;
  lengthMm: number;
  widthMm: number;
  heightMm: number;
}

interface ExportProduct {
  id: string;
  sku: string;
  title: string;
  description: string;
  price: number;
  quantity: number;
  imageUrls: string[];
  categoryName: string;
  categoryId: string;
  brand: string;
  weightGrams: number;
  lengthMm: number;
  widthMm: number;
  heightMm: number;
  attributes: Record<string, string>;
  variants: ExportVariant[];
  ebayPayload?: EbayTradingPayload;
}

type CsvRow = Record<string, string | number>;

export function createMarketplaceExport(target: MarketplaceExportTarget, sources: MarketplaceExportSource[], format: MarketplaceExportFormat = 'csv'): MarketplaceExportResult {
  const products = sources.map(normalizeExportProduct).filter((product) => product.id && product.title);
  const warnings = marketplaceWarnings(target, products);
  const rows = products.flatMap((product) => rowsForMarketplace(target, product, format));
  const stamp = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15);
  const workbookFormat = format === 'xls' || format === 'xlsx';

  if (workbookFormat) {
    const workbook = workbookForMarketplace(target, rows, products);
    const content = XLSX.write(workbook, { bookType: 'xlsx', type: 'base64' }) as string;
    return {
      filename: `cj-${target}-export-${stamp}.xlsx`,
      mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      content,
      encoding: 'base64',
      rows: rows.length,
      warnings,
    };
  }

  return {
    filename: `cj-${target}-export-${stamp}.csv`,
    mimeType: 'text/csv;charset=utf-8',
    content: csv(rows),
    encoding: 'text',
    rows: rows.length,
    warnings,
  };
}

function normalizeExportProduct(source: MarketplaceExportSource): ExportProduct {
  const data = unwrapData(source.detail);
  const raw = { ...source.item, ...data };
  const payload = listingPayload(source.listing);
  const id = text(raw.pid ?? raw.productId ?? raw.id ?? source.item.productId ?? source.item.id ?? payload?.debug.cjProductId);
  const sku = text(raw.productSku ?? raw.sku ?? source.item.sku ?? payload?.sku ?? id);
  const title = cleanTitle(text(payload?.title ?? raw.productNameEn ?? raw.nameEn ?? raw.productName ?? raw.title ?? source.item.title));
  const description = stripHtml(text(payload?.descriptionHtml ?? raw.description ?? raw.productDescription ?? source.item.description ?? title));
  const price = firstPositive([payload?.startPrice, raw.sellPrice, raw.productSellPrice, raw.nowPrice, source.item.nowPrice, source.item.sellPrice, source.item.productCost], 0);
  const quantity = Math.max(0, Math.floor(firstPositive([payload?.quantity, raw.totalInventoryNum, raw.totalInventory, raw.totalVerifiedInventory, raw.warehouseInventoryNum, source.item.inventory], 10)));
  const imageUrls = payload?.images?.length ? payload.images : imageUrlsFrom(raw, source.item);
  const categoryName = text(raw.categoryName ?? raw.threeCategoryName ?? source.item.categoryName);
  const categoryId = text(payload?.categoryId ?? raw.categoryId ?? source.item.categoryId);
  const brand = text(payload?.itemSpecifics?.Brand ?? raw.brand ?? raw.brandName ?? source.item.brand) || 'Unbranded';
  const attributes = { ...attributesFrom(raw, title), ...(payload?.itemSpecifics ?? {}) };
  const variants = variantsFrom(raw, payload);
  return {
    id,
    sku,
    title,
    description: description || title,
    price,
    quantity,
    imageUrls,
    categoryName,
    categoryId,
    brand,
    weightGrams: positiveNumber(raw.productWeight ?? raw.variantWeight ?? raw.weight ?? source.item.weight, 0),
    lengthMm: positiveNumber(raw.variantLength ?? raw.productLength ?? raw.length, 0),
    widthMm: positiveNumber(raw.variantWidth ?? raw.productWidth ?? raw.width, 0),
    heightMm: positiveNumber(raw.variantHeight ?? raw.productHeight ?? raw.height, 0),
    attributes,
    variants,
    ebayPayload: payload,
  };
}

function rowsForMarketplace(target: MarketplaceExportTarget, product: ExportProduct, format: MarketplaceExportFormat): CsvRow[] {
  if (target === 'ebay') return ebayRows(product);
  if (target === 'facebook') return format === 'csv' ? [metaCatalogRow(product)] : facebookMarketplaceRows(product);
  return tiktokRows(product);
}

function ebayRows(product: ExportProduct): CsvRow[] {
  const payload = product.ebayPayload;
  if (payload) {
    const variations = payload.variations ?? [];
    if (variations.length > 0) return variations.map((variation) => ebayPayloadRow(product, payload, variation));
    return [ebayPayloadRow(product, payload)];
  }
  return [ebayFallbackRow(product)];
}

function ebayPayloadRow(product: ExportProduct, payload: EbayTradingPayload, variation?: EbayTradingVariation): CsvRow {
  const variationSpecifics = variation?.specifics ?? {};
  const specifics = { ...payload.itemSpecifics, ...variationSpecifics };
  const pictureUrls = variation ? variationImageUrls(payload, variation) : payload.images;
  const row: CsvRow = {
    '*Action(SiteID=US|Country=US|Currency=USD|Version=1193)': 'Add',
    CustomLabel: variation?.sku ?? payload.sku ?? product.sku,
    '*Category': payload.categoryId,
    StoreCategory: '',
    '*Title': payload.title,
    Subtitle: '',
    Relationship: variation ? 'Variation' : '',
    RelationshipDetails: variation ? Object.entries(variationSpecifics).map(([name, value]) => `${name}=${value}`).join('|') : '',
    '*Description': stripHtml(payload.descriptionHtml),
    '*ConditionID': payload.conditionId,
    PicURL: validPipeUrls(pictureUrls.length ? pictureUrls : payload.images),
    '*Quantity': Math.max(1, Math.floor(variation?.quantity ?? payload.quantity ?? 1)),
    '*StartPrice': money(variation?.startPrice ?? payload.startPrice ?? product.price),
    '*Format': 'FixedPrice',
    '*Duration': payload.listingDuration,
    Location: payload.location,
    PostalCode: payload.postalCode,
    'ShippingService-1:Option': payload.shippingDetails.shippingService,
    'ShippingService-1:Cost': money(payload.shippingDetails.shippingServiceCost),
    DispatchTimeMax: payload.dispatchTimeMax,
    ReturnsAcceptedOption: payload.returnPolicy.returnsAcceptedOption,
    ReturnsWithinOption: payload.returnPolicy.returnsWithinOption,
    RefundOption: payload.returnPolicy.refundOption,
    ShippingCostPaidByOption: payload.returnPolicy.shippingCostPaidByOption,
    CJProductId: payload.debug.cjProductId,
  };
  for (const [name, value] of Object.entries(specifics)) {
    if (name && value) row[`C:${name}`] = value;
  }
  return row;
}

function ebayFallbackRow(product: ExportProduct): CsvRow {
  const row: CsvRow = {
    '*Action(SiteID=US|Country=US|Currency=USD|Version=1193)': 'Add',
    CustomLabel: product.sku,
    '*Category': product.categoryId,
    StoreCategory: '',
    '*Title': product.title,
    Subtitle: '',
    Relationship: '',
    RelationshipDetails: '',
    '*Description': product.description,
    '*ConditionID': 1000,
    PicURL: validPipeUrls(product.imageUrls),
    '*Quantity': product.quantity || 1,
    '*StartPrice': money(product.price),
    '*Format': 'FixedPrice',
    '*Duration': 'GTC',
    Location: 'New York, New York',
    PostalCode: '10001',
    'ShippingService-1:Option': 'UPSGround',
    'ShippingService-1:Cost': '0.00',
    DispatchTimeMax: 3,
    ReturnsAcceptedOption: 'ReturnsAccepted',
    ReturnsWithinOption: 'Days_30',
    RefundOption: 'MoneyBack',
    ShippingCostPaidByOption: 'Buyer',
    CJProductId: product.id,
  };
  for (const [name, value] of Object.entries({ Brand: product.brand, MPN: 'Does not apply', ...product.attributes })) {
    if (name && value) row[`C:${name}`] = value;
  }
  return row;
}

function metaCatalogRow(product: ExportProduct): CsvRow {
  return {
    id: product.sku || product.id,
    title: product.title,
    description: product.description,
    availability: product.quantity > 0 ? 'in stock' : 'out of stock',
    condition: 'new',
    price: `${money(product.price)} USD`,
    link: '',
    image_link: product.imageUrls[0] ?? '',
    additional_image_link: product.imageUrls.slice(1, 10).join(','),
    brand: product.brand,
    google_product_category: metaCategoryHint(product),
    fb_product_category: metaCategoryHint(product),
    quantity_to_sell_on_facebook: product.quantity || 1,
    inventory: product.quantity || 1,
    item_group_id: product.id,
    custom_label_0: 'CJ Dropshipping',
    custom_label_1: product.categoryName,
  };
}

function facebookMarketplaceRows(product: ExportProduct): CsvRow[] {
  const variants = product.variants.length ? product.variants : [defaultVariant(product)];
  return variants.map((variant) => ({
    TITLE: variationTitle(product, variant),
    PRICE: Math.max(1, Math.round(variant.price || product.price)),
    CONDITION: 'New',
    DESCRIPTION: product.description,
    CATEGORY: facebookMarketplaceCategory(product),
    'SHIPPING WEIGHT': pounds(variant.weightGrams || product.weightGrams),
    'OFFER FREE SHIPPING': 'No',
    'OFFER SHIPPING': 'Yes',
  }));
}

function tiktokRows(product: ExportProduct): CsvRow[] {
  const variants = product.variants.length ? product.variants : [defaultVariant(product)];
  return variants.map((variant) => {
    const images = variant.imageUrls.length ? variant.imageUrls : product.imageUrls;
    const entries = Object.entries(variant.attributes);
    return {
      Category: tiktokCategoryHint(product),
      Brand: product.brand === 'Unbranded' ? '' : product.brand,
      'Product name': product.title,
      'Product description': product.description,
      'Main image': images[0] ?? '',
      'Image 2': images[1] ?? '',
      'Image 3': images[2] ?? '',
      'Image 4': images[3] ?? '',
      'Image 5': images[4] ?? '',
      'Image 6': images[5] ?? '',
      'Image 7': images[6] ?? '',
      'Product Image 8': images[7] ?? '',
      'Product Image 9': images[8] ?? '',
      'Identifier Code Type': '',
      'Identifier Code': '',
      'Primary variation name (theme)': entries[0]?.[0] ?? '',
      'Primary variation value (option)': entries[0]?.[1] ?? '',
      'Primary variation image 1': images[0] ?? '',
      'Secondary variation name (theme)': entries[1]?.[0] ?? '',
      'Secondary variation value (option)': entries[1]?.[1] ?? '',
      'Package weight(lb)': pounds(variant.weightGrams || product.weightGrams),
      'Package length(inch)': inches(variant.lengthMm || product.lengthMm),
      'Package width(inch)': inches(variant.widthMm || product.widthMm),
      'Package height(inch)': inches(variant.heightMm || product.heightMm),
      Price: money(variant.price || product.price),
      Quantity: variant.quantity || product.quantity || 1,
      'Seller SKU': variant.sku || product.sku,
      'CJ Product ID': product.id,
    };
  });
}

function marketplaceWarnings(target: MarketplaceExportTarget, products: ExportProduct[]): string[] {
  const warnings: string[] = [];
  if (products.some((product) => product.imageUrls.length === 0)) warnings.push('Some products have no image URL; marketplace imports usually require at least one image.');
  if (products.some((product) => product.price <= 0)) warnings.push('Some products have no price; fill price before importing.');
  if (target === 'ebay' && products.some((product) => !product.ebayPayload)) warnings.push('Some eBay rows were generated without a live eBay category/aspect payload. Re-export while eBay Taxonomy is connected to avoid non-leaf category errors.');
  if (target === 'ebay' && products.some((product) => !product.categoryId)) warnings.push('Some eBay rows have no category ID. eBay will reject rows without a leaf category.');
  if (target === 'tiktok') warnings.push('TikTok Shop bulk upload templates are leaf-category specific. XLSX exports fill the available Seller Center template shape, but final validation must happen against the matching TikTok category template.');
  if (target === 'facebook') warnings.push('CSV export is a Meta catalog feed. XLSX export follows the Facebook Marketplace bulk upload workbook shape.');
  return warnings;
}

function workbookForMarketplace(target: MarketplaceExportTarget, rows: CsvRow[], products: ExportProduct[]): XLSX.WorkBook {
  if (target === 'facebook') return facebookWorkbook(rows);
  if (target === 'tiktok') return tiktokWorkbook(rows);
  return genericWorkbook('eBay Feed', rows, products);
}

function facebookWorkbook(rows: CsvRow[]): XLSX.WorkBook {
  const workbook = XLSX.utils.book_new();
  const aoa = [
    ['Facebook Marketplace Bulk Upload Template'],
    ['You can create up to 50 listings at once. Save/export as XLSX before uploading.'],
    ['REQUIRED | Plain text (up to 150 characters)', 'REQUIRED | A whole number in $', 'REQUIRED | Supported values: "New"; "Used - Like New"; "Used - Good"; "Used - Fair"', 'OPTIONAL | Plain text (up to 5000 characters)', 'OPTIONAL | Type of listing', 'OPTIONAL | Pounds', 'OPTIONAL | Yes or No', 'OPTIONAL | Yes or No'],
    ['TITLE', 'PRICE', 'CONDITION', 'DESCRIPTION', 'CATEGORY', 'SHIPPING WEIGHT', 'OFFER FREE SHIPPING', 'OFFER SHIPPING'],
    ...rows.map((row) => ['TITLE', 'PRICE', 'CONDITION', 'DESCRIPTION', 'CATEGORY', 'SHIPPING WEIGHT', 'OFFER FREE SHIPPING', 'OFFER SHIPPING'].map((header) => row[header] ?? '')),
  ];
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(aoa), 'Bulk Upload Template');
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([['New', 'Used - Like New', 'Used - Good', 'Used - Fair']]), 'VALIDATION');
  return workbook;
}

function tiktokWorkbook(rows: CsvRow[]): XLSX.WorkBook {
  const templatePath = findTikTokTemplate();
  if (templatePath) {
    const workbook = XLSX.readFile(templatePath);
    const sheetName = workbook.SheetNames.find((name) => name.toLowerCase() === 'template') ?? workbook.SheetNames[0];
    const sheetRows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, raw: false, blankrows: false }) as unknown[][];
    const headers = (sheetRows[0] ?? Object.keys(rows[0] ?? {})).map(String);
    const kept = sheetRows.slice(0, 3);
    const dataRows = rows.map((row) => headers.map((header) => row[header] ?? ''));
    workbook.Sheets[sheetName] = XLSX.utils.aoa_to_sheet([...kept, ...dataRows]);
    return workbook;
  }
  return genericWorkbook('TikTok Source', rows, []);
}

function genericWorkbook(sheetName: string, rows: CsvRow[], products: ExportProduct[]): XLSX.WorkBook {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(rows), sheetName);
  if (products.length) {
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(products.map((product) => ({
      cj_product_id: product.id,
      sku: product.sku,
      category_id: product.categoryId,
      category_name: product.categoryName,
      variant_count: product.variants.length,
    }))), 'Source');
  }
  return workbook;
}

function variantsFrom(raw: Record<string, unknown>, payload?: EbayTradingPayload): ExportVariant[] {
  if (payload?.variations?.length) {
    return payload.variations.map((variation) => ({
      sku: variation.sku,
      quantity: variation.quantity,
      price: variation.startPrice,
      attributes: variation.specifics,
      imageUrls: variationImageUrls(payload, variation),
      weightGrams: 0,
      lengthMm: 0,
      widthMm: 0,
      heightMm: 0,
    }));
  }
  const rows = Array.isArray(raw.variants) ? raw.variants as Array<Record<string, unknown>> : [];
  return rows.map((variant, index) => ({
    sku: text(variant.variantSku ?? variant.sku ?? `${raw.productSku ?? raw.pid ?? 'CJ'}-${index + 1}`),
    quantity: Math.max(0, Math.floor(firstPositive([variant.totalInventory, variant.inventory, variant.inventoryNum, raw.totalInventoryNum], 0))),
    price: firstPositive([variant.variantSellPrice, variant.sellPrice, variant.price, raw.sellPrice], 0),
    attributes: attributesFrom({ ...raw, ...variant }, text(variant.variantNameEn ?? variant.variantName ?? raw.productNameEn)),
    imageUrls: imageUrlsFrom(variant),
    weightGrams: positiveNumber(variant.variantWeight ?? raw.productWeight, 0),
    lengthMm: positiveNumber(variant.variantLength, 0),
    widthMm: positiveNumber(variant.variantWidth, 0),
    heightMm: positiveNumber(variant.variantHeight, 0),
  })).filter((variant) => variant.sku);
}

function defaultVariant(product: ExportProduct): ExportVariant {
  return {
    sku: product.sku,
    quantity: product.quantity,
    price: product.price,
    attributes: product.attributes,
    imageUrls: product.imageUrls,
    weightGrams: product.weightGrams,
    lengthMm: product.lengthMm,
    widthMm: product.widthMm,
    heightMm: product.heightMm,
  };
}

function listingPayload(value: unknown): EbayTradingPayload | undefined {
  const root = value && typeof value === 'object' ? value as Record<string, unknown> : undefined;
  const payload = root?.payload && typeof root.payload === 'object' ? root.payload as EbayTradingPayload : undefined;
  return payload?.title && payload?.categoryId ? payload : undefined;
}

function variationImageUrls(payload: EbayTradingPayload, variation: EbayTradingVariation): string[] {
  const pictureKey = payload.variationPictures?.variationSpecificName;
  const pictureValue = pictureKey ? variation.specifics[pictureKey] : undefined;
  const urls = pictureValue ? payload.variationPictures?.sets.find((set) => set.value === pictureValue)?.urls ?? [] : [];
  return urls.length ? urls : payload.images;
}

function validPipeUrls(urls: string[]): string {
  return urls.filter((url) => /^https:\/\//i.test(url)).slice(0, 12).join('|');
}

function facebookMarketplaceCategory(product: ExportProduct): string {
  const haystack = `${product.title} ${product.categoryName}`.toLowerCase();
  if (/shoe|sneaker|boot|sandal/.test(haystack)) return "Clothing, Shoes & Accessories//Women's Shoes";
  if (/dress|shirt|clothing|pants|jacket|skirt/.test(haystack)) return "Clothing, Shoes & Accessories//Women's Clothing//Dresses";
  if (/wig|hair/.test(haystack)) return 'Health & Beauty//Hair Care';
  if (/bottle|kitchen|cook|oil|vinegar/.test(haystack)) return 'Home Goods//Kitchen & Dining';
  if (/car|auto|engine|brake|tire|wheel|exhaust/.test(haystack)) return 'Auto Parts & Accessories//Car Parts & Accessories';
  if (/pet|dog|cat/.test(haystack)) return 'Pet Supplies//Pet Beds';
  return product.categoryName || 'Home Goods//Other Home Goods';
}

function metaCategoryHint(product: ExportProduct): string {
  const haystack = `${product.title} ${product.categoryName}`.toLowerCase();
  if (/shoe|sneaker|boot|sandal/.test(haystack)) return 'Apparel & Accessories > Shoes';
  if (/dress|shirt|clothing|pants|jacket|skirt/.test(haystack)) return 'Apparel & Accessories > Clothing';
  if (/wig|hair/.test(haystack)) return 'Health & Beauty > Personal Care > Hair Care';
  if (/bottle|kitchen|cook|oil|vinegar/.test(haystack)) return 'Home & Garden > Kitchen & Dining';
  if (/car|auto|engine|brake|tire|wheel|exhaust/.test(haystack)) return 'Vehicles & Parts > Vehicle Parts & Accessories';
  return product.categoryName;
}

function tiktokCategoryHint(product: ExportProduct): string {
  const haystack = `${product.title} ${product.categoryName}`.toLowerCase();
  if (/shoe|sneaker|boot|sandal/.test(haystack)) return 'Womenswear & Underwear/Women Shoes';
  if (/dress|shirt|clothing|pants|jacket|skirt/.test(haystack)) return 'Womenswear & Underwear/Women Clothes';
  if (/wig|hair/.test(haystack)) return 'Beauty & Personal Care/Hair Care & Styling';
  if (/soap dispenser|bathroom tumbler|toothbrush holder|soap dish|hanger|peg/.test(haystack)) return 'Bathroom Supplies/Soap Dispensers';
  if (/bottle|kitchen|cook|oil|vinegar/.test(haystack)) return 'Kitchenware/Kitchen Tools';
  if (/car|auto|engine|brake|tire|wheel|exhaust/.test(haystack)) return 'Automotive & Motorcycle';
  return product.categoryName.replace(/\s*>\s*/g, '/') || 'Home Organizers/Hangers & Pegs';
}

function attributesFrom(raw: Record<string, unknown>, title: string): Record<string, string> {
  const attributes: Record<string, string> = {};
  const productKey = parseMaybeJsonArray(raw.productKeyEn ?? raw.productKey);
  const variantKey = text(raw.variantKey ?? raw.variantNameEn ?? raw.variantName);
  if (productKey.length && variantKey) {
    const values = variantKey.split(/[-,/|]+/).map((value) => value.trim()).filter(Boolean);
    productKey.forEach((name, index) => {
      if (values[index]) attributes[normalizeAttributeName(name)] = cleanAttributeValue(values[index]);
    });
  }
  const lower = title.toLowerCase();
  if (!attributes.Color) {
    const color = ['black', 'white', 'red', 'blue', 'green', 'pink', 'purple', 'silver', 'gold', 'brown', 'grey', 'gray', 'yellow', 'orange', 'beige'].find((value) => lower.includes(value));
    if (color) attributes.Color = titleCase(color);
  }
  if (!attributes.Size) {
    const size = title.match(/\b(xs|s|m|l|xl|xxl|xxxl|\d{1,2}(?:inch|in|cm|mm)?)\b/i)?.[1];
    if (size) attributes.Size = size.toUpperCase();
  }
  if (!attributes.Type && raw.entryNameEn) attributes.Type = cleanAttributeValue(raw.entryNameEn);
  if (!attributes.Material && raw.materialNameEn) attributes.Material = parseMaybeJsonArray(raw.materialNameEn)[0] ?? '';
  return Object.fromEntries(Object.entries(attributes).filter(([, value]) => value));
}

function imageUrlsFrom(...values: unknown[]): string[] {
  const urls = new Set<string>();
  const visit = (value: unknown) => {
    if (Array.isArray(value)) return value.forEach(visit);
    if (typeof value === 'string') {
      if (value.trim().startsWith('[')) parseMaybeJsonArray(value).forEach(visit);
      const matches = value.match(/https?:\/\/[^"',\s<>]+?\.(?:jpg|jpeg|png|webp)(?:\?[^"',\s<>]*)?/gi) ?? [];
      matches.forEach((url) => urls.add(url));
      if (/^https?:\/\/[^\s"'<>]+$/i.test(value)) urls.add(value);
      return;
    }
    if (!value || typeof value !== 'object') return;
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
      if (key.toLowerCase().includes('image') || key.toLowerCase().includes('pic')) visit(entry);
    }
  };
  values.forEach(visit);
  return [...urls].slice(0, 12);
}

function csv(rows: CsvRow[]): string {
  const headers = [...new Set(rows.flatMap((row) => Object.keys(row)))];
  return [headers.join(','), ...rows.map((row) => headers.map((header) => csvCell(row[header] ?? '')).join(','))].join('\r\n');
}

function csvCell(value: string | number): string {
  const textValue = String(value).replace(/\r?\n/g, ' ').trim();
  return /[",]/.test(textValue) ? `"${textValue.replace(/"/g, '""')}"` : textValue;
}

function findTikTokTemplate(): string | undefined {
  const dir = 'csv';
  if (!existsSync(dir)) return undefined;
  const file = readdirSync(dir).find((name) => /tiktoksellercenter.*template\.xlsx$/i.test(name));
  return file ? join(dir, file) : undefined;
}

function unwrapData(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object') return {};
  const root = value as Record<string, unknown>;
  const data = root.data && typeof root.data === 'object' ? root.data as Record<string, unknown> : root;
  return data;
}

function parseMaybeJsonArray(value: unknown): string[] {
  if (Array.isArray(value)) return value.map(String).filter(Boolean);
  if (typeof value !== 'string') return [];
  const trimmed = value.trim();
  if (!trimmed) return [];
  if (trimmed.startsWith('[')) {
    try {
      const parsed = JSON.parse(trimmed);
      if (Array.isArray(parsed)) return parsed.map(String).filter(Boolean);
    } catch {
      return [trimmed];
    }
  }
  return trimmed.split(/[|,]/).map((entry) => entry.trim()).filter(Boolean);
}

function normalizeAttributeName(value: string): string {
  const cleaned = titleCase(value.replace(/[_-]+/g, ' ').trim());
  if (/colour/i.test(cleaned)) return 'Color';
  if (/size/i.test(cleaned)) return 'Size';
  if (/material/i.test(cleaned)) return 'Material';
  return cleaned;
}

function cleanAttributeValue(value: unknown): string {
  return titleCase(text(value).replace(/[_-]+/g, ' ').trim()).slice(0, 80);
}

function cleanTitle(value: string): string {
  return value.replace(/\b(?:amazon|aliexpress|temu|wish|cj dropshipping)\b/gi, '').replace(/\s+/g, ' ').replace(/[^\w\s.,'&()+/-]/g, '').trim().slice(0, 80);
}

function stripHtml(value: string): string {
  return value.replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 5000);
}

function positiveNumber(value: unknown, fallback: number): number {
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : fallback;
}

function firstPositive(values: unknown[], fallback: number): number {
  for (const value of values) {
    const number = Number(value);
    if (Number.isFinite(number) && number > 0) return number;
  }
  return fallback;
}

function money(value: number): string {
  return positiveNumber(value, 0).toFixed(2);
}

function pounds(grams: number): string {
  if (!grams || grams <= 0) return '';
  return (grams / 453.59237).toFixed(2);
}

function inches(mm: number): string {
  if (!mm || mm <= 0) return '';
  return (mm / 25.4).toFixed(2);
}

function variationTitle(product: ExportProduct, variant: ExportVariant): string {
  const suffix = Object.values(variant.attributes).filter(Boolean).slice(0, 2).join(' ');
  return cleanTitle(`${product.title} ${suffix}`.trim()).slice(0, 150);
}

function text(value: unknown): string {
  return value == null ? '' : String(value).trim();
}

function titleCase(value: string): string {
  return value.replace(/\b\w/g, (letter) => letter.toUpperCase());
}
