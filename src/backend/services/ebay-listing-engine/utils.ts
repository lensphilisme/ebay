import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import type { EbayAttemptResult, EbayTradingPayload, EbayTradingVariation, NormalizedVariant, PreflightIssue } from '@/backend/services/ebay-listing-engine/types';

const SUPPORTED_VARIATION_KEYS = [
  'Color',
  'Size',
  'US Shoe Size',
  'Style',
  'Material',
  'Ships From',
  'Plug Type',
  'Quantity',
  'Capacity',
  'Model',
  'Pattern',
  'Type',
  'Bundle',
  'Length',
  'Width',
  'Specification',
];

const COLOR_WORDS = new Set([
  'black', 'white', 'red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink', 'gray', 'grey', 'brown', 'beige', 'gold', 'silver',
  'transparent', 'clear', 'multicolor', 'multi color', 'rose gold', 'navy', 'khaki', 'coffee', 'ivory', 'wine red',
]);

const UNSAFE_WORDS = [
  'adult', 'sex', 'weapon', 'knife', 'gun', 'rifle', 'tactical', 'self defense', 'pepper spray', 'supplement', 'cbd', 'medicine',
  'medical', 'treatment', 'cure', 'diagnose', 'therapy', 'prescription', 'replica', 'counterfeit',
];

export function sanitizeText(value: unknown, maxLength = 500): string {
  return String(value ?? '')
    .normalize('NFKC')
    .replace(/[\u0000-\u001f\u007f-\u009f]/g, ' ')
    .replace(/[\u200b-\u200f\u202a-\u202e\ufeff]/g, '')
    .replace(/\u00a0/g, ' ')
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength)
    .trim();
}

export function cleanTitle(value: unknown): string {
  return sanitizeText(value, 160)
    .replace(/\b(?:amazon|aliexpress|temu|wish|cj dropshipping)\b/gi, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80)
    .trim();
}

export function sanitizeHtml(value: unknown): string {
  const raw = String(value ?? '');
  const withoutScripts = raw
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/\son[a-z]+\s*=\s*(['"]).*?\1/gi, '')
    .replace(/javascript:/gi, '');
  const compact = withoutScripts.trim() || `<p>${escapeXml(sanitizeText(value, 3000))}</p>`;
  return compact.slice(0, 500000);
}

export function cleanSku(value: unknown, fallback: string): string {
  const cleaned = sanitizeText(value, 80)
    .replace(/[^A-Za-z0-9._-]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
  return (cleaned || fallback).slice(0, 80);
}

export function validImageUrls(values: unknown[]): string[] {
  const urls = new Set<string>();
  for (const value of values.flatMap((entry) => parsePossibleList(entry))) {
    const url = sanitizeText(value, 1000);
    if (!/^https:\/\/[^\s"'<>]+\.(?:jpg|jpeg|png|webp)(?:[?#][^\s"'<>]*)?$/i.test(url)) continue;
    urls.add(url);
  }
  return [...urls].slice(0, 24);
}

export function parsePossibleList(value: unknown): string[] {
  if (Array.isArray(value)) return value.flatMap(parsePossibleList);
  if (value == null) return [];
  const text = String(value).trim();
  if (!text) return [];
  if ((text.startsWith('[') && text.endsWith(']')) || (text.startsWith('{') && text.endsWith('}'))) {
    try {
      const parsed = JSON.parse(text) as unknown;
      return parsePossibleList(parsed);
    } catch {
      // Fall through to delimiter parsing.
    }
  }
  if (/^https?:\/\//i.test(text)) return [text];
  return text.split(/[,|]/).map((part) => part.trim()).filter(Boolean);
}

export function normalizeAttributeName(rawName: unknown, categoryId = ''): string {
  const name = sanitizeText(rawName, 80).toLowerCase();
  if (!name) return '';
  if (/color|colour|颜色|顏色/.test(name)) return 'Color';
  if (/plug|socket|adapter/.test(name)) return 'Plug Type';
  if (/ship|warehouse|area|country/.test(name)) return 'Ships From';
  if (/shoe/.test(name) || (/size|码|尺码|尺寸/.test(name) && prefersShoeSize(categoryId))) return 'US Shoe Size';
  if (/size|码|尺码|尺寸/.test(name)) return 'Size';
  if (/style|款式/.test(name)) return 'Style';
  if (/material|材质/.test(name)) return 'Material';
  if (/capacity|volume|gb|mah|ml|liter|litre/.test(name)) return 'Capacity';
  if (/model|型号/.test(name)) return 'Model';
  if (/pattern/.test(name)) return 'Pattern';
  if (/type|类型/.test(name)) return 'Type';
  if (/bundle|set|pack/.test(name)) return 'Bundle';
  if (/length|long/.test(name)) return 'Length';
  if (/width|wide/.test(name)) return 'Width';
  if (/quantity|qty|pcs|pieces/.test(name)) return 'Quantity';
  if (/spec|规格/.test(name)) return 'Specification';
  const title = name.split(/[\s_-]+/).map((part) => part ? part[0].toUpperCase() + part.slice(1) : '').join(' ');
  return SUPPORTED_VARIATION_KEYS.includes(title) ? title : title || 'Specification';
}

export function normalizeAttributeValue(value: unknown): string {
  return sanitizeText(value, 65).replace(/^[-_/|]+|[-_/|]+$/g, '').trim();
}

export function inferAttributeName(value: string, categoryId = ''): string {
  const lower = value.toLowerCase();
  if (looksLikeColor(value)) return 'Color';
  if (/\b(?:us|eu|uk|au)\s*plug\b|plug|socket/.test(lower)) return 'Plug Type';
  if (/\b(?:china|cn|us|usa|united states|eu|uk|warehouse)\b/.test(lower)) return 'Ships From';
  if (looksLikeSize(value)) return prefersShoeSize(categoryId) ? 'US Shoe Size' : 'Size';
  if (/\d+(?:\.\d+)?\s*(?:ml|l|oz|gb|mah|tb)\b/i.test(value)) return 'Capacity';
  if (/\d+(?:\.\d+)?\s*(?:cm|mm|in|inch|")\b/i.test(value)) return 'Length';
  if (/\b(?:cotton|polyester|leather|metal|steel|plastic|silicone|wood|glass|nylon|rubber)\b/i.test(value)) return 'Material';
  return 'Style';
}

export function looksLikeSize(value: string): boolean {
  const v = value.trim();
  return /^(?:US\s*)?\d{1,3}(?:\.\d{1,2})?(?:\s*(?:-|to|\/)\s*\d{1,3}(?:\.\d{1,2})?)?$/i.test(v)
    || /^(?:XXS|XS|S|M|L|XL|XXL|XXXL|[3-9]XL)$/i.test(v);
}

export function looksLikeColor(value: string): boolean {
  const v = value.trim().toLowerCase().replace(/[-_]+/g, ' ');
  if (COLOR_WORDS.has(v)) return true;
  return v.split(/\s+/).every((part) => COLOR_WORDS.has(part));
}

export function prefersShoeSize(categoryId: string): boolean {
  return ['95672', '3034', '15709', '11504', '93427'].includes(categoryId);
}

export function tokenizeVariantKey(value: unknown): string[] {
  const raw = sanitizeText(value, 300);
  if (!raw) return [];
  const parsed = parsePossibleList(raw);
  if (parsed.length > 1) return parsed.map((entry) => sanitizeText(entry, 80)).filter(Boolean);
  return raw.split(/\s*[-/|,]\s*/).map((part) => sanitizeText(part, 80)).filter(Boolean);
}

export function dedupeVariants(variants: NormalizedVariant[]): { variants: NormalizedVariant[]; warnings: string[] } {
  const seenSku = new Set<string>();
  const seenCombo = new Set<string>();
  const warnings: string[] = [];
  const kept: NormalizedVariant[] = [];

  for (const variant of variants) {
    if (!variant.isValid) {
      warnings.push(`${variant.cleanSku}: ${variant.invalidReason ?? 'invalid variant'}`);
      continue;
    }
    if (seenSku.has(variant.cleanSku)) {
      warnings.push(`${variant.cleanSku}: duplicate SKU skipped`);
      continue;
    }
    const combo = comboKey(variant.attributes);
    if (seenCombo.has(combo)) {
      warnings.push(`${variant.cleanSku}: duplicate variation combination skipped`);
      continue;
    }
    seenSku.add(variant.cleanSku);
    seenCombo.add(combo);
    kept.push(variant);
  }

  return { variants: kept, warnings };
}

export function comboKey(attributes: Record<string, string>): string {
  return Object.entries(attributes).sort(([a], [b]) => a.localeCompare(b)).map(([key, value]) => `${key}=${value.toLowerCase()}`).join('|');
}

export function selectVisualVariationKey(variants: NormalizedVariant[]): string | undefined {
  const keys = new Set(variants.flatMap((variant) => Object.keys(variant.attributes)));
  for (const preferred of ['Color', 'Style', 'Pattern', 'Model', 'Plug Type', 'Material', 'Type', 'Ships From', 'Size', 'US Shoe Size']) {
    if (keys.has(preferred)) return preferred;
  }
  return keys.values().next().value;
}

export function buildVariationSpecificsSet(variations: EbayTradingVariation[]): Record<string, string[]> {
  const set: Record<string, string[]> = {};
  for (const variation of variations) {
    for (const [name, value] of Object.entries(variation.specifics)) {
      if (!name || !value) continue;
      set[name] = [...new Set([...(set[name] ?? []), value])];
    }
  }
  return set;
}

export function preflightPayload(payload: EbayTradingPayload, requiredSpecifics: string[] = []): PreflightIssue[] {
  const issues: PreflightIssue[] = [];
  const error = (code: string, message: string) => issues.push({ code, severity: 'error', message });
  const warning = (code: string, message: string) => issues.push({ code, severity: 'warning', message });

  if (!payload.title || payload.title.length > 80) error('TITLE_INVALID', 'Title is empty or longer than 80 characters.');
  if (!payload.categoryId) error('CATEGORY_MISSING', 'eBay category ID is missing.');
  if (!payload.images.length) error('IMAGE_MISSING', 'At least one HTTPS product image is required.');
  if (payload.images.some((url) => !/^https:\/\//i.test(url))) error('IMAGE_INVALID', 'All eBay image URLs must be HTTPS.');
  if (!payload.conditionId) error('CONDITION_MISSING', 'ConditionID is missing.');

  for (const name of requiredSpecifics) {
    if (!payload.itemSpecifics[name]) error('REQUIRED_SPECIFIC_MISSING', `Required item specific is missing: ${name}.`);
  }

  const variations = payload.variations ?? [];
  if (variations.length > 0) {
    const expectedNames = Object.keys(payload.variationSpecificsSet ?? {}).sort();
    if (expectedNames.length === 0) error('VARIATION_SET_MISSING', 'VariationSpecificsSet is missing.');
    const skus = new Set<string>();
    const combos = new Set<string>();
    for (const variation of variations) {
      if (!variation.sku) error('VARIATION_SKU_MISSING', 'A variation SKU is missing.');
      if (skus.has(variation.sku)) error('VARIATION_DUPLICATE_SKU', `Duplicate variation SKU: ${variation.sku}.`);
      skus.add(variation.sku);
      const names = Object.keys(variation.specifics).sort();
      if (names.join('|') !== expectedNames.join('|')) error('VARIATION_NAME_MISMATCH', `Variation ${variation.sku} does not include every variation name.`);
      if (Object.entries(variation.specifics).some(([name, value]) => !name || !value)) error('VARIATION_EMPTY_SPECIFIC', `Variation ${variation.sku} has an empty name or value.`);
      const key = comboKey(variation.specifics);
      if (combos.has(key)) error('VARIATION_DUPLICATE_COMBO', `Duplicate variation combination: ${key}.`);
      combos.add(key);
      if (variation.quantity < 0) error('VARIATION_QUANTITY_INVALID', `Variation ${variation.sku} has invalid quantity.`);
      if (variation.startPrice <= 0) error('VARIATION_PRICE_INVALID', `Variation ${variation.sku} has invalid price.`);
    }
    if (payload.variationPictures && !expectedNames.includes(payload.variationPictures.variationSpecificName)) {
      error('VARIATION_PICTURE_KEY_MISMATCH', 'Variation picture key is not included in variation specifics.');
    }
  } else {
    if (!payload.sku) error('SKU_MISSING', 'Single-SKU listing requires SKU.');
    if ((payload.quantity ?? -1) < 0) error('QUANTITY_INVALID', 'Quantity is invalid.');
    if ((payload.startPrice ?? 0) <= 0) error('PRICE_INVALID', 'StartPrice is invalid.');
  }

  if (Object.values(payload.itemSpecifics).some((value) => !sanitizeText(value, 1000))) warning('EMPTY_SPECIFIC_REMOVED', 'One or more item specifics had empty values.');
  return issues;
}

export function buildTradingXml(callName: 'VerifyAddFixedPriceItem' | 'AddFixedPriceItem', payload: EbayTradingPayload): string {
  const itemSpecificsXml = Object.entries(payload.itemSpecifics)
    .filter(([name, value]) => name && value)
    .map(([name, value]) => `<NameValueList><Name>${escapeXml(name)}</Name><Value>${escapeXml(value)}</Value></NameValueList>`)
    .join('');
  const pictureXml = payload.images.slice(0, 12).map((url) => `<PictureURL>${escapeXml(url)}</PictureURL>`).join('');
  const variationsXml = payload.variations?.length ? buildVariationsXml(payload) : '';
  const singleSkuXml = !payload.variations?.length ? `
    <SKU>${escapeXml(payload.sku ?? '')}</SKU>
    <Quantity>${Math.max(0, Math.floor(payload.quantity ?? 0))}</Quantity>
    <StartPrice currencyID="${payload.currency}">${money(payload.startPrice ?? 0)}</StartPrice>` : '';

  return `<?xml version="1.0" encoding="utf-8"?>
<${callName}Request xmlns="urn:ebay:apis:eBLBaseComponents">
  <ErrorLanguage>en_US</ErrorLanguage>
  <WarningLevel>High</WarningLevel>
  <Item>
    <Title>${escapeXml(payload.title)}</Title>
    <PrimaryCategory><CategoryID>${escapeXml(payload.categoryId)}</CategoryID></PrimaryCategory>
    <CategoryMappingAllowed>true</CategoryMappingAllowed>
    <ConditionID>${escapeXml(payload.conditionId)}</ConditionID>
    <Country>${payload.country}</Country>
    <Currency>${payload.currency}</Currency>
    <DispatchTimeMax>${payload.dispatchTimeMax}</DispatchTimeMax>
    <ListingDuration>${payload.listingDuration}</ListingDuration>
    <ListingType>${payload.listingType}</ListingType>
    <Location>${escapeXml(payload.location)}</Location>
    <PostalCode>${escapeXml(payload.postalCode)}</PostalCode>
    <Description><![CDATA[${safeCdata(payload.descriptionHtml)}]]></Description>
    <Site>US</Site>${singleSkuXml}
    <ReturnPolicy>
      <ReturnsAcceptedOption>${payload.returnPolicy.returnsAcceptedOption}</ReturnsAcceptedOption>
      <RefundOption>${payload.returnPolicy.refundOption}</RefundOption>
      <ReturnsWithinOption>${payload.returnPolicy.returnsWithinOption}</ReturnsWithinOption>
      <ShippingCostPaidByOption>${payload.returnPolicy.shippingCostPaidByOption}</ShippingCostPaidByOption>
    </ReturnPolicy>
    <ShippingDetails>
      <ShippingType>${payload.shippingDetails.shippingType}</ShippingType>
      <ShippingServiceOptions>
        <ShippingServicePriority>1</ShippingServicePriority>
        <ShippingService>${escapeXml(payload.shippingDetails.shippingService)}</ShippingService>
        <ShippingServiceCost currencyID="${payload.currency}">${money(payload.shippingDetails.shippingServiceCost)}</ShippingServiceCost>
        <ShippingServiceAdditionalCost currencyID="${payload.currency}">0.00</ShippingServiceAdditionalCost>
      </ShippingServiceOptions>
    </ShippingDetails>
    <PictureDetails>${pictureXml}</PictureDetails>
    <ItemSpecifics>${itemSpecificsXml}</ItemSpecifics>
    ${variationsXml}
  </Item>
</${callName}Request>`;
}

function buildVariationsXml(payload: EbayTradingPayload): string {
  const variations = payload.variations ?? [];
  const variationXml = variations.map((variation) => {
    const specifics = Object.entries(variation.specifics)
      .map(([name, value]) => `<NameValueList><Name>${escapeXml(name)}</Name><Value>${escapeXml(value)}</Value></NameValueList>`)
      .join('');
    return `<Variation><SKU>${escapeXml(variation.sku)}</SKU><Quantity>${Math.max(0, Math.floor(variation.quantity))}</Quantity><StartPrice currencyID="${payload.currency}">${money(variation.startPrice)}</StartPrice><VariationSpecifics>${specifics}</VariationSpecifics></Variation>`;
  }).join('');
  const setXml = Object.entries(payload.variationSpecificsSet ?? {})
    .map(([name, values]) => `<NameValueList><Name>${escapeXml(name)}</Name>${values.map((value) => `<Value>${escapeXml(value)}</Value>`).join('')}</NameValueList>`)
    .join('');
  const pictures = payload.variationPictures;
  const picturesXml = pictures ? `<Pictures><VariationSpecificName>${escapeXml(pictures.variationSpecificName)}</VariationSpecificName>${pictures.sets.map((set) => `<VariationSpecificPictureSet><VariationSpecificValue>${escapeXml(set.value)}</VariationSpecificValue>${set.urls.slice(0, 12).map((url) => `<PictureURL>${escapeXml(url)}</PictureURL>`).join('')}</VariationSpecificPictureSet>`).join('')}</Pictures>` : '';
  return `<Variations>${variationXml}<VariationSpecificsSet>${setXml}</VariationSpecificsSet>${picturesXml}</Variations>`;
}

export function parseTradingResponse(callName: string, requestXml: string, responseXml: string, mode: 'preflight' | 'live'): EbayAttemptResult {
  const ack = firstTag(responseXml, 'Ack') as EbayAttemptResult['ack'] || 'NotRun';
  return {
    attemptedAt: new Date().toISOString(),
    mode,
    ack,
    itemId: firstTag(responseXml, 'ItemID') || undefined,
    requestXml,
    responseXml,
    errors: errorMessages(responseXml, 'Error'),
    warnings: errorMessages(responseXml, 'Warning'),
    fees: parseFees(responseXml),
  };
}

export function parseLatestTradingLog(logPath = 'database/logs/ebay-trading.log'): EbayAttemptResult | undefined {
  if (!existsSync(logPath)) return undefined;
  const lines = readFileSync(logPath, 'utf8').trim().split(/\r?\n/).filter(Boolean);
  const latest = lines.at(-1);
  if (!latest) return undefined;
  const parsed = JSON.parse(latest) as Record<string, unknown>;
  return parseTradingResponse(String(parsed.call ?? 'TradingAPI'), String(parsed.request_xml ?? ''), String(parsed.response_xml ?? ''), 'preflight');
}

export function archiveAndClearTradingLog(logPath = 'database/logs/ebay-trading.log'): string | undefined {
  if (!existsSync(logPath)) return undefined;
  const current = readFileSync(logPath, 'utf8');
  if (!current.trim()) return undefined;
  const archivePath = `${logPath}.${new Date().toISOString().replace(/[:.]/g, '-')}.bak`;
  mkdirSync(dirname(archivePath), { recursive: true });
  renameSync(logPath, archivePath);
  writeFileSync(logPath, '');
  return archivePath;
}

export function unsafeReason(title: string, description = ''): string | undefined {
  const haystack = `${title} ${description}`.toLowerCase();
  const hit = UNSAFE_WORDS.find((word) => haystack.includes(word));
  return hit ? `Unsafe/restricted keyword detected: ${hit}` : undefined;
}

export function escapeXml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

function safeCdata(value: string): string {
  return value.replace(/\]\]>/g, ']]]]><![CDATA[>');
}

function money(value: number): string {
  return Math.max(0, value).toFixed(2);
}

function firstTag(xml: string, tag: string): string {
  return allBlocks(xml, tag).map(decodeXml)[0] ?? '';
}

function allBlocks(xml: string, tag: string): string[] {
  return [...xml.matchAll(new RegExp(`<${tag}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${tag}>`, 'g'))].map((match) => match[1] ?? '');
}

function decodeXml(value: string): string {
  return value.replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'");
}

function errorMessages(xml: string, severity: 'Error' | 'Warning'): string[] {
  return allBlocks(xml, 'Errors')
    .filter((block) => firstTag(block, 'SeverityCode') === severity)
    .map((block) => firstTag(block, 'LongMessage') || firstTag(block, 'ShortMessage'))
    .filter(Boolean);
}

function parseFees(xml: string): Array<{ name: string; amount: number; currency: string }> {
  return allBlocks(xml, 'Fee').map((block) => ({
    name: firstTag(block, 'Name'),
    amount: Number(firstTag(block, 'Fee') || firstTag(block, 'Amount') || 0),
    currency: 'USD',
  })).filter((fee) => fee.name && Number.isFinite(fee.amount));
}
