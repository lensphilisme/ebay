import { getConfig } from '@/backend/config/env';
import { CjClient } from '@/backend/integrations/cj/client';
import { EbayClient } from '@/backend/integrations/ebay/client';
import { createAIProvider, type AIProvider } from '@/backend/services/ai/provider';
import { calculateProfit } from '@/backend/services/pricing';
import type {
  BulkListingResult,
  BulkEbayInventoryDraftResult,
  EbayAspectRequirement,
  EbayAttemptResult,
  EbayInventoryDraftResult,
  EbayTradingPayload,
  EbayTradingVariation,
  ListingEngineResult,
  NormalizedCJProduct,
  NormalizedVariant,
  PreflightIssue,
} from '@/backend/services/ebay-listing-engine/types';
import {
  buildTradingXml,
  buildVariationSpecificsSet,
  cleanSku,
  cleanTitle,
  comboKey,
  inferAttributeName,
  normalizeAttributeName,
  normalizeAttributeValue,
  parseTradingResponse,
  preflightPayload,
  sanitizeHtml,
  sanitizeText,
  selectVisualVariationKey,
  tokenizeVariantKey,
  unsafeReason,
  validImageUrls,
} from '@/backend/services/ebay-listing-engine/utils';

export interface ListingEngineOptions {
  cj?: CjClient;
  ebay?: EbayClient;
  aiProvider?: AIProvider;
  countryCode?: string;
  live?: boolean;
  preflight?: boolean;
  maxProducts?: number;
  productIds?: string[];
  useFixtureData?: boolean;
}

interface FetchedCJProduct {
  detail: unknown;
  variants: unknown;
  inventory: unknown;
}

const FALLBACK_CATEGORY_RULES: Array<{ pattern: RegExp; categoryId: string; label: string }> = [
  { pattern: /shoe\s+(?:cabinet|rack|storage|shelf|organizer)|(?:cabinet|rack|storage|shelf|organizer).*shoe/i, categoryId: '3197', label: 'Furniture / storage' },
  { pattern: /shoe|sneaker|boot|sandal/i, categoryId: '95672', label: 'Shoes' },
  { pattern: /bag|backpack|purse|wallet/i, categoryId: '169291', label: 'Bags' },
  { pattern: /phone|case|charger|cable|usb/i, categoryId: '9394', label: 'Cell Phone Accessories' },
  { pattern: /kitchen|cook|bottle|cup|mug/i, categoryId: '20625', label: 'Kitchen Tools' },
  { pattern: /pet|dog|cat/i, categoryId: '20742', label: 'Pet Supplies' },
  { pattern: /car|auto|vehicle|tire|wheel|compressor|inflator|headlight/i, categoryId: '9884', label: 'Car & Truck Parts' },
  { pattern: /toy|game|model/i, categoryId: '220', label: 'Toys & Hobbies' },
  { pattern: /home|garden|storage|organizer|decor/i, categoryId: '11700', label: 'Home & Garden' },
  { pattern: /tool|drill|repair/i, categoryId: '631', label: 'Tools' },
  { pattern: /clothing|shirt|dress|pants|jacket/i, categoryId: '11450', label: 'Clothing' },
];

export class CjEbayListingEngine {
  private readonly cj: CjClient;
  private readonly ebay?: EbayClient;
  private readonly ai: AIProvider;

  constructor(options: { cj?: CjClient; ebay?: EbayClient; aiProvider?: AIProvider } = {}) {
    this.cj = options.cj ?? new CjClient();
    this.ebay = options.ebay;
    this.ai = options.aiProvider ?? createAIProvider();
  }

  async fetchCJProduct(productId: string, countryCode = 'US'): Promise<FetchedCJProduct> {
    const detail = await this.cj.getProductDetail({ pid: productId, countryCode });
    const variants = await this.cj.getProductVariants({ pid: productId, countryCode }).catch(() => undefined);
    const inventory = await this.cj.queryInventory({ pid: productId }).catch(() => undefined);
    return { detail, variants, inventory };
  }

  async searchCJProducts(limit = 10, countryCode = 'US'): Promise<string[]> {
    const raw = await this.cj.searchProducts({ pageNum: 1, pageSize: Math.min(Math.max(limit, 1), 100), countryCode, minInventory: 1 });
    return flattenRows(raw).map((row) => String(row.pid ?? row.productId ?? row.id ?? '')).filter(Boolean).slice(0, limit);
  }

  normalizeCJProduct(fetched: FetchedCJProduct, countryCode = 'US'): NormalizedCJProduct {
    const product = unwrapData(fetched.detail);
    const pid = String(product.pid ?? product.id ?? product.productId ?? '');
    const title = String(product.productNameEn ?? product.productName ?? product.nameEn ?? product.name ?? 'CJ product');
    const description = String(product.description ?? product.productDescription ?? '');
    const productImages = validImageUrls([
      product.bigImage,
      product.productImage,
      product.productImageSet,
      product.productImageList,
      product.images,
      product.image,
    ]);
    const rawVariants = normalizeVariantRows(product, fetched.variants);
    const inventoryByVid = inventoryByVariantId(fetched.inventory);
    const declaredNames = declaredVariantNames(product);
    const warnings: string[] = [];
    const parsedVariants = rawVariants.length > 0
      ? rawVariants.map((variant, index) => this.normalizeVariant(product, variant, index, declaredNames, inventoryByVid, countryCode))
      : [this.syntheticVariant(product, productImages, countryCode)];
    const productCost = positiveNumber(product.sellPrice ?? product.productSellPrice ?? product.nowPrice ?? product.price, 0);
    const repaired = repairVariantsForEbay(parsedVariants, productCost);
    warnings.push(...repaired.warnings);
    const validVariants = repaired.variants;

    const baseCost = positiveNumber(product.sellPrice ?? product.productSellPrice ?? product.nowPrice ?? product.price ?? validVariants[0]?.cost, 0);
    const unsafe = unsafeReason(title, description);
    const skipReasons = unsafe ? [unsafe] : [];

    return {
      cjProductId: pid,
      source: 'cj',
      title,
      cleanTitle: cleanTitle(title || pid),
      descriptionHtml: description,
      cleanDescriptionHtml: sanitizeHtml(description || `<p>${sanitizeText(title, 500)}</p>`),
      cjCategoryId: stringOrUndefined(product.categoryId),
      cjCategoryPath: stringOrUndefined(product.categoryName ?? product.categoryPath),
      brand: inferBrand(product),
      conditionId: '1000',
      productImages,
      itemSpecifics: {},
      variants: validVariants,
      shippingData: { countryCode, dispatchTimeMax: 3, shippingService: 'UPSGround', postalCode: envText('EBAY_DEFAULT_POSTAL_CODE', '10001') },
      baseCost,
      calculatedPrice: 0,
      currency: 'USD',
      sourceUrl: pid ? `https://app.cjdropshipping.com/product-detail.html?id=${encodeURIComponent(pid)}` : undefined,
      rawDataReference: { product, variants: rawVariants, inventory: fetched.inventory },
      warnings,
      skipReasons,
    };
  }

  async mapCJCategoryToEbayCategory(product: NormalizedCJProduct): Promise<{ categoryId?: string; warnings: string[] }> {
    const warnings: string[] = [];
    if (this.ebay) {
      try {
        const suggestions = await this.ebay.getCategorySuggestions(product.cleanTitle);
        const first = suggestions[0];
        if (first?.categoryId) return { categoryId: first.categoryId, warnings: [`Mapped by eBay taxonomy suggestion: ${first.categoryName ?? first.categoryId}.`] };
      } catch (error) {
        warnings.push(`eBay taxonomy category suggestion unavailable: ${error instanceof Error ? error.message : 'unknown error'}`);
      }
    }

    const haystack = `${product.cleanTitle} ${product.cjCategoryPath ?? ''}`;
    const fallback = FALLBACK_CATEGORY_RULES.find((rule) => rule.pattern.test(haystack));
    if (fallback) return { categoryId: fallback.categoryId, warnings: [...warnings, `Mapped by local rule: ${fallback.label}.`] };
    return { warnings: [...warnings, 'No safe eBay category mapping found.'] };
  }

  async loadEbayAspectRequirements(categoryId: string): Promise<EbayAspectRequirement[]> {
    if (!this.ebay || !categoryId) return [];
    try {
      return await this.ebay.getItemAspectsForCategory(categoryId);
    } catch {
      return [];
    }
  }

  async buildEbayItemSpecifics(product: NormalizedCJProduct, variationKeys: string[], aspects: EbayAspectRequirement[]): Promise<{ specifics: Record<string, string>; issues: PreflightIssue[] }> {
    const specifics: Record<string, string> = {
      Brand: product.brand || 'Unbranded',
      Type: inferType(product),
      Source: 'CJ Dropshipping',
      'CJ PID': product.cjProductId,
    };
    const issues: PreflightIssue[] = [];
    const variationKeySet = new Set(variationKeys);

    for (const variant of product.variants.length === 1 ? product.variants : []) {
      for (const [name, value] of Object.entries(variant.attributes)) {
        if (value) specifics[name] = value;
      }
    }

    const raw = product.rawDataReference && typeof product.rawDataReference === 'object'
      ? product.rawDataReference as Record<string, unknown>
      : {};
    const aiSpecifics = await this.ai.extractEbayCategorySpecifics({
      title: product.cleanTitle,
      description: product.cleanDescriptionHtml,
      raw,
      categoryId: product.ebayCategoryId ?? '',
      categoryName: product.cjCategoryPath,
      aspects,
      existingSpecifics: specifics,
      variationKeys,
    }).catch(() => ({} as Record<string, string>));
    for (const [name, value] of Object.entries(aiSpecifics)) {
      const aspect = aspects.find((entry) => entry.name.toLowerCase() === name.toLowerCase());
      const canonicalName = aspect?.name ?? name;
      if (!canonicalName || variationKeySet.has(canonicalName)) continue;
      if (canonicalName.toLowerCase() === 'brand' && (!product.brand || product.brand === 'Unbranded')) continue;
      const cleanValue = sanitizeText(value, 500);
      if (cleanValue) specifics[canonicalName] = aspect ? normalizeAspectValue(cleanValue, aspect) : cleanValue;
    }

    for (const aspect of aspects) {
      if (variationKeySet.has(aspect.name)) continue;
      const current = specifics[aspect.name];
      if (current) {
        const saferCurrent = aspect.name.toLowerCase() === 'type' && typeLooksIncompatible(product, current)
          ? fallbackSpecificValue(product, aspect) || current
          : current;
        specifics[aspect.name] = normalizeAspectValue(saferCurrent, aspect);
        continue;
      }
      const inferred = inferSpecific(product, aspect.name);
      if (inferred) {
        specifics[aspect.name] = normalizeAspectValue(inferred, aspect);
        continue;
      } else {
        const optional = safeOptionalSpecificValue(product, aspect);
        if (optional) {
          specifics[aspect.name] = normalizeAspectValue(optional, aspect);
          continue;
        }
      }
      if (aspect.required) {
        const fallback = fallbackSpecificValue(product, aspect);
        if (fallback) {
          specifics[aspect.name] = normalizeAspectValue(fallback, aspect);
        } else {
          issues.push({ code: 'REQUIRED_SPECIFIC_MISSING', severity: 'error', message: `Required item specific cannot be safely inferred: ${aspect.name}.` });
        }
      }
    }

    return {
      specifics: Object.fromEntries(Object.entries(specifics).map(([name, value]) => [sanitizeText(name, 65), sanitizeText(value, 500)]).filter(([name, value]) => name && value)),
      issues,
    };
  }

  calculateEbayPrices(product: NormalizedCJProduct): NormalizedCJProduct {
    const config = getConfig().listing;
    const variants = product.variants.map((variant) => {
      const profit = calculateProfit({
        productCost: variant.cost,
        shippingCost: variant.shippingCost,
        desiredProfit: config.minProfitUsd,
        feePercent: config.ebayFeeBufferPercent,
        adPercent: config.paymentFeeBufferPercent,
      });
      const markedUp = roundToRule(Math.max(profit.targetPrice, (variant.cost + variant.shippingCost) * (1 + config.markupPercent / 100)), config.roundTo);
      return { ...variant, calculatedEbayPrice: Math.max(markedUp, profit.breakEvenPrice) };
    });
    const calculatedPrice = variants.length ? Math.min(...variants.map((variant) => variant.calculatedEbayPrice)) : 0;
    return { ...product, variants, calculatedPrice };
  }

  async buildEbayTradingPayload(product: NormalizedCJProduct): Promise<{ product: NormalizedCJProduct; payload?: EbayTradingPayload; preflight: PreflightIssue[] }> {
    const category = await this.mapCJCategoryToEbayCategory(product);
    const categoryId = category.categoryId;
    const warnings = [...product.warnings, ...category.warnings];
    if (!categoryId) {
      return { product: { ...product, warnings, skipReasons: [...product.skipReasons, 'No safe eBay category was mapped.'] }, preflight: [{ code: 'CATEGORY_UNMAPPED', severity: 'error', message: 'No safe eBay category was mapped.' }] };
    }

    const priced = this.calculateEbayPrices({
      ...product,
      ebayCategoryId: categoryId,
      warnings,
      variants: product.variants.map((variant) => ({
        ...variant,
        attributes: normalizeVariantAttributeMap(variant.attributes, categoryId),
      })),
    });
    const multiVariant = priced.variants.length >= 2;
    const variationKeys = multiVariant ? stableVariationKeys(priced.variants, categoryId).slice(0, 3) : [];
    const aspects = await this.loadEbayAspectRequirements(categoryId);
    const { specifics, issues } = await this.buildEbayItemSpecifics(priced, variationKeys, aspects);
    const images = validImageUrls([priced.productImages, ...priced.variants.flatMap((variant) => variant.imageUrls)]).slice(0, 12);
    const common = {
      title: priced.cleanTitle,
      categoryId,
      conditionId: priced.conditionId,
      country: 'US' as const,
      currency: 'USD' as const,
      postalCode: priced.shippingData.postalCode,
      location: envText('EBAY_DEFAULT_LOCATION', 'New York, New York'),
      dispatchTimeMax: priced.shippingData.dispatchTimeMax,
      listingDuration: 'GTC' as const,
      listingType: 'FixedPriceItem' as const,
      descriptionHtml: priced.cleanDescriptionHtml,
      itemSpecifics: specifics,
      images,
      returnPolicy: {
        returnsAcceptedOption: 'ReturnsAccepted' as const,
        refundOption: 'MoneyBack' as const,
        returnsWithinOption: 'Days_30' as const,
        shippingCostPaidByOption: 'Buyer' as const,
      },
      shippingDetails: { shippingType: 'Flat' as const, shippingService: priced.shippingData.shippingService, shippingServiceCost: 0 },
    };

    let payload: EbayTradingPayload;
    if (!multiVariant) {
      const variant = priced.variants[0];
      payload = {
        ...common,
        sku: variant?.cleanSku ?? cleanSku(priced.cjProductId, `CJ-${priced.cjProductId}`),
        quantity: Math.min(variant?.quantity ?? 0, getConfig().listing.maxListingQuantity),
        startPrice: variant?.calculatedEbayPrice ?? priced.calculatedPrice,
        debug: { cjProductId: priced.cjProductId, warnings },
      };
    } else {
      const variations: EbayTradingVariation[] = priced.variants.map((variant) => ({
        sku: variant.cleanSku,
        quantity: Math.min(variant.quantity, getConfig().listing.maxListingQuantity),
        startPrice: variant.calculatedEbayPrice,
        specifics: Object.fromEntries(variationKeys.map((key) => [key, variant.attributes[key] ?? '']).filter(([, value]) => value)),
      }));
      const variationSpecificsSet = buildVariationSpecificsSet(variations);
      const selectedVisualKey = selectVisualVariationKey(priced.variants);
      const visualKey = selectedVisualKey && variationKeys.includes(selectedVisualKey)
        ? selectedVisualKey
        : variationKeys.find((key) => key !== 'Size' && key !== 'US Shoe Size') ?? variationKeys[0];
      payload = {
        ...common,
        variations,
        variationSpecificsSet,
        variationPictures: visualKey ? buildVariationPictures(visualKey, priced.variants, priced.productImages) : undefined,
        debug: { cjProductId: priced.cjProductId, visualVariationKey: visualKey, warnings },
      };
    }

    const requiredSpecificNames = aspects.filter((aspect) => aspect.required && !variationKeys.includes(aspect.name)).map((aspect) => aspect.name);
    const preflight = [...issues, ...preflightPayload(payload, requiredSpecificNames)];
    return { product: { ...priced, itemSpecifics: specifics }, payload, preflight };
  }

  async listSingleCJProduct(cjProductId: string, options: ListingEngineOptions = {}): Promise<ListingEngineResult> {
    const countryCode = options.countryCode ?? 'US';
    const fetched = await this.fetchCJProduct(cjProductId, countryCode);
    return this.processFetchedProduct(cjProductId, fetched, options);
  }

  async processFetchedProduct(cjProductId: string, fetched: FetchedCJProduct, options: ListingEngineOptions = {}): Promise<ListingEngineResult> {
    const product = this.normalizeCJProduct(fetched, options.countryCode ?? 'US');
    const built = await this.buildEbayTradingPayload(product);
    const resultBase = {
      cjProductId,
      title: built.product.cleanTitle,
      productType: inferType(built.product),
      variantKeys: stableVariationKeys(built.product.variants, built.product.ebayCategoryId ?? ''),
      variantCount: built.product.variants.length,
      imageCount: built.payload?.images.length ?? built.product.productImages.length,
      selectedEbayCategory: built.product.ebayCategoryId,
      payload: built.payload,
      preflight: built.preflight,
      warnings: built.product.warnings,
    };

    if (built.product.skipReasons.length > 0 || !built.payload) {
      return {
        ...resultBase,
        ebayAttempt: skippedAttempt(built.product.skipReasons),
        status: 'skipped',
        errors: built.product.skipReasons,
      };
    }

    const blockingIssues = built.preflight.filter((issue) => issue.severity === 'error');
    if (blockingIssues.length > 0) {
      return {
        ...resultBase,
        requestXml: buildTradingXml('VerifyAddFixedPriceItem', built.payload),
        ebayAttempt: skippedAttempt(blockingIssues.map((issue) => issue.message)),
        status: 'failed',
        errors: blockingIssues.map((issue) => issue.message),
      };
    }

    const liveRequested = Boolean(options.live);
    const preflightRequested = options.preflight ?? getConfig().listing.preflightRequired;
    const callName = liveRequested ? 'AddFixedPriceItem' : 'VerifyAddFixedPriceItem';
    const requestXml = buildTradingXml(callName, built.payload);
    if (!this.ebay || (!liveRequested && !preflightRequested)) {
      return {
        ...resultBase,
        requestXml,
        ebayAttempt: notRunAttempt(liveRequested ? 'live' : 'build_only'),
        status: 'passed',
        errors: [],
      };
    }

    if (liveRequested && !getConfig().listing.liveListingEnabled) {
      return {
        ...resultBase,
        requestXml,
        ebayAttempt: skippedAttempt(['LIVE_EBAY_LISTING_ENABLED is not true.']),
        status: 'skipped',
        errors: ['LIVE_EBAY_LISTING_ENABLED is not true.'],
      };
    }

    const responseXml = liveRequested
      ? await this.ebay.addFixedPriceItem(requestXml)
      : await this.ebay.verifyAddFixedPriceItem(requestXml);
    const attempt = parseTradingResponse(callName, requestXml, responseXml, liveRequested ? 'live' : 'preflight');
    const feeErrors = validateFees(attempt);
    const errors = [...attempt.errors, ...feeErrors];

    return {
      ...resultBase,
      requestXml,
      ebayAttempt: { ...attempt, errors },
      status: errors.length > 0 || attempt.ack === 'Failure' ? 'failed' : 'passed',
      errors,
      warnings: [...resultBase.warnings, ...attempt.warnings],
    };
  }

  async listBulkCJProducts(options: ListingEngineOptions = {}): Promise<BulkListingResult> {
    const startedAt = new Date().toISOString();
    const ids = options.productIds?.length
      ? options.productIds
      : await this.searchCJProducts(options.maxProducts ?? 10, options.countryCode ?? 'US');
    const results: ListingEngineResult[] = [];
    for (const id of ids.slice(0, options.maxProducts ?? ids.length)) {
      try {
        results.push(await this.listSingleCJProduct(id, options));
      } catch (error) {
        results.push({
          cjProductId: id,
          title: '',
          productType: 'Unknown',
          variantKeys: [],
          variantCount: 0,
          imageCount: 0,
          preflight: [{ code: 'PRODUCT_PROCESSING_ERROR', severity: 'error', message: error instanceof Error ? error.message : 'Unknown product processing error.' }],
          ebayAttempt: skippedAttempt([error instanceof Error ? error.message : 'Unknown product processing error.']),
          status: 'failed',
          errors: [error instanceof Error ? error.message : 'Unknown product processing error.'],
          warnings: [],
        });
      }
    }
    const finishedAt = new Date().toISOString();
    return {
      startedAt,
      finishedAt,
      liveMode: Boolean(options.live),
      total: results.length,
      passed: results.filter((result) => result.status === 'passed').length,
      failed: results.filter((result) => result.status === 'failed').length,
      skipped: results.filter((result) => result.status === 'skipped').length,
      results,
    };
  }

  async createEbayInventoryDraft(cjProductId: string, options: ListingEngineOptions = {}): Promise<EbayInventoryDraftResult> {
    if (!this.ebay) {
      return inventoryDraftSkipped(cjProductId, ['eBay client is not configured.']);
    }

    const fetched = await this.fetchCJProduct(cjProductId, options.countryCode ?? 'US');
    const product = this.normalizeCJProduct(fetched, options.countryCode ?? 'US');
    const built = await this.buildEbayTradingPayload(product);
    const base = {
      cjProductId,
      title: built.product.cleanTitle,
      selectedEbayCategory: built.product.ebayCategoryId,
      offerIds: [] as string[],
      skuKeys: [] as string[],
      warnings: built.product.warnings,
    };

    if (built.product.skipReasons.length > 0 || !built.payload) {
      return { ...base, status: 'skipped', errors: built.product.skipReasons };
    }

    const blockingIssues = built.preflight.filter((issue) => issue.severity === 'error');
    if (blockingIssues.length > 0) {
      return { ...base, status: 'failed', errors: blockingIssues.map((issue) => issue.message) };
    }

    try {
      const result = await this.createInventoryDraftFromPayload(cjProductId, built.payload);
      return {
        ...base,
        status: 'passed',
        offerIds: result.offerIds,
        inventoryItemGroupKey: result.inventoryItemGroupKey,
        skuKeys: result.skuKeys,
        errors: [],
        warnings: [...base.warnings, ...result.warnings],
        raw: result.raw,
      };
    } catch (error) {
      return {
        ...base,
        status: 'failed',
        errors: [error instanceof Error ? error.message : 'Unknown eBay Inventory draft error.'],
      };
    }
  }

  async createBulkEbayInventoryDrafts(options: ListingEngineOptions = {}): Promise<BulkEbayInventoryDraftResult> {
    const startedAt = new Date().toISOString();
    const ids = options.productIds?.length
      ? options.productIds
      : await this.searchCJProducts(options.maxProducts ?? 10, options.countryCode ?? 'US');
    const results: EbayInventoryDraftResult[] = [];
    for (const id of ids.slice(0, options.maxProducts ?? ids.length)) {
      try {
        results.push(await this.createEbayInventoryDraft(id, options));
      } catch (error) {
        results.push(inventoryDraftSkipped(id, [error instanceof Error ? error.message : 'Unknown eBay Inventory draft error.']));
      }
    }
    const finishedAt = new Date().toISOString();
    return {
      startedAt,
      finishedAt,
      total: results.length,
      passed: results.filter((result) => result.status === 'passed').length,
      failed: results.filter((result) => result.status === 'failed').length,
      skipped: results.filter((result) => result.status === 'skipped').length,
      results,
    };
  }

  async publishEbayInventoryDrafts(input: { offerIds?: string[]; inventoryItemGroupKeys?: string[] }): Promise<{ publishedAt: string; total: number; passed: number; failed: number; results: Array<Record<string, unknown>> }> {
    if (!this.ebay) throw new Error('eBay client is not configured.');
    const offerIds = [...new Set((input.offerIds ?? []).map((value) => sanitizeText(value, 80)).filter(Boolean))];
    const groupKeys = [...new Set((input.inventoryItemGroupKeys ?? []).map((value) => sanitizeText(value, 80)).filter(Boolean))];
    const results: Array<Record<string, unknown>> = [];

    for (const groupKey of groupKeys) {
      try {
        const raw = await this.ebay.publishOfferByInventoryItemGroup(groupKey);
        results.push({ type: 'inventory_item_group', key: groupKey, status: 'passed', raw });
      } catch (error) {
        results.push({ type: 'inventory_item_group', key: groupKey, status: 'failed', error: error instanceof Error ? error.message : 'Unknown publish error.' });
      }
    }

    for (const batch of chunkStrings(offerIds, 25)) {
      try {
        const raw = await this.ebay.bulkPublishOffers(batch);
        results.push({ type: 'offer_batch', offerIds: batch, status: 'passed', raw });
      } catch (error) {
        results.push({ type: 'offer_batch', offerIds: batch, status: 'failed', error: error instanceof Error ? error.message : 'Unknown publish error.' });
      }
    }

    return {
      publishedAt: new Date().toISOString(),
      total: groupKeys.length + offerIds.length,
      passed: results.filter((result) => result.status === 'passed').length,
      failed: results.filter((result) => result.status === 'failed').length,
      results,
    };
  }

  private async createInventoryDraftFromPayload(cjProductId: string, payload: EbayTradingPayload): Promise<{ offerIds: string[]; inventoryItemGroupKey?: string; skuKeys: string[]; warnings: string[]; raw: unknown[] }> {
    if (!this.ebay) throw new Error('eBay client is not configured.');
    const policies = await this.ebay.getSellerPolicies();
    const warnings = [...policies.warnings];
    if (!policies.fulfillmentPolicyId || !policies.paymentPolicyId || !policies.returnPolicyId) {
      warnings.push('One or more eBay business policies were not found. Draft creation may fail until payment, return, and fulfillment policies exist.');
    }
    const merchantLocationKey = envText('EBAY_MERCHANT_LOCATION_KEY', envText('EBAY_INVENTORY_LOCATION_KEY', 'default'));
    const raw: unknown[] = [];
    const offerIds: string[] = [];
    const skuKeys: string[] = [];
    const variations = payload.variations ?? [];

    if (variations.length > 0) {
      const groupKey = cleanSku(`CJ-GROUP-${cjProductId}`, `CJ-GROUP-${Date.now()}`);
      for (const variation of variations) {
        const inventoryItem = inventoryItemFromPayload(payload, variation);
        raw.push(await this.ebay.createOrReplaceInventoryItem(variation.sku, inventoryItem));
        skuKeys.push(variation.sku);
      }

      raw.push(await this.ebay.createOrReplaceInventoryItemGroup(groupKey, inventoryItemGroupFromPayload(groupKey, payload, variations)));
      for (const variation of variations) {
        const offer = offerFromPayload(payload, variation.sku, variation.quantity, variation.startPrice, merchantLocationKey, policies);
        const offerResult = await this.ebay.createOrUpdateOfferForSku(variation.sku, offer);
        if (offerResult.offerId) offerIds.push(offerResult.offerId);
        raw.push(offerResult.raw);
      }
      return { offerIds, inventoryItemGroupKey: groupKey, skuKeys, warnings, raw };
    }

    const sku = payload.sku ?? cleanSku(cjProductId, `CJ-${cjProductId}`);
    raw.push(await this.ebay.createOrReplaceInventoryItem(sku, inventoryItemFromPayload(payload)));
    skuKeys.push(sku);
    const offer = offerFromPayload(payload, sku, payload.quantity ?? 0, payload.startPrice ?? 0, merchantLocationKey, policies);
    const offerResult = await this.ebay.createOrUpdateOfferForSku(sku, offer);
    if (offerResult.offerId) offerIds.push(offerResult.offerId);
    raw.push(offerResult.raw);
    return { offerIds, skuKeys, warnings, raw };
  }

  private normalizeVariant(product: Record<string, unknown>, variant: Record<string, unknown>, index: number, declaredNames: string[], inventoryByVid: Map<string, number>, countryCode: string): NormalizedVariant {
    const pid = String(product.pid ?? product.id ?? product.productId ?? '');
    const vid = String(variant.vid ?? variant.id ?? variant.variantId ?? `${pid}-variant-${index + 1}`);
    const rawSku = String(variant.variantSku ?? variant.sku ?? `${pid}-${index + 1}`);
    const tokens = [
      ...fieldValues(variant, ['variantValue1', 'variantValue2', 'variantValue3']),
      ...(fieldValues(variant, ['variantValue1', 'variantValue2', 'variantValue3']).length ? [] : tokenizeVariantKey(variant.variantKey ?? variant.variantNameEn ?? variant.variantName ?? rawSku.replace(String(product.productSku ?? pid), ''))),
    ];
    const attributes: Record<string, string> = {};
    tokens.forEach((token, tokenIndex) => {
      const name = declaredNames[tokenIndex] ? normalizeAttributeName(declaredNames[tokenIndex], '') : inferAttributeName(token, '');
      const value = normalizeAttributeValue(token);
      if (name && value && !attributes[name]) attributes[name] = value;
    });
    if (Object.keys(attributes).length === 0) {
      attributes.Specification = normalizeAttributeValue(variant.variantNameEn ?? variant.variantName ?? `Option ${index + 1}`);
    }
    const quantity = inventoryByVid.get(vid) ?? inventoryQuantity(variant) ?? 0;
    const cost = positiveNumber(variant.variantSellPrice ?? variant.sellPrice ?? variant.price ?? product.sellPrice ?? product.productSellPrice, 0);
    const images = validImageUrls([variant.variantImage, variant.variantImageUrl, variant.image, variant.variantImageSet]);
    const invalidReason = !rawSku ? 'Missing variant SKU.' : cost <= 0 ? 'Missing variant cost.' : Object.values(attributes).some((value) => !value) ? 'Empty variation value.' : undefined;
    return {
      cjVariantId: vid,
      rawSku,
      cleanSku: cleanSku(rawSku, `CJ-${pid}-${index + 1}`),
      price: cost,
      cost,
      quantity,
      attributes,
      imageUrls: images,
      weight: positiveNumber(variant.variantWeight ?? product.productWeight, 0) || undefined,
      dimensions: {
        length: positiveNumber(variant.variantLength, 0) || undefined,
        width: positiveNumber(variant.variantWidth, 0) || undefined,
        height: positiveNumber(variant.variantHeight, 0) || undefined,
      },
      shippingCost: 0,
      calculatedEbayPrice: 0,
      isValid: !invalidReason,
      invalidReason,
      rawDataReference: { variant, countryCode },
    };
  }

  private syntheticVariant(product: Record<string, unknown>, images: string[], countryCode: string): NormalizedVariant {
    const pid = String(product.pid ?? product.id ?? product.productId ?? product.productSku ?? 'CJ-PRODUCT');
    const cost = positiveNumber(product.sellPrice ?? product.productSellPrice ?? product.nowPrice ?? product.price, 0);
    const quantity = inventoryQuantity(product) ?? 0;
    return {
      cjVariantId: pid,
      rawSku: String(product.productSku ?? pid),
      cleanSku: cleanSku(product.productSku ?? pid, `CJ-${pid}`),
      price: cost,
      cost,
      quantity,
      attributes: {},
      imageUrls: images,
      weight: positiveNumber(product.productWeight ?? product.weight, 0) || undefined,
      shippingCost: 0,
      calculatedEbayPrice: 0,
      isValid: cost > 0,
      invalidReason: cost > 0 ? undefined : 'Missing product cost.',
      rawDataReference: { product, countryCode },
    };
  }
}

function buildVariationPictures(visualKey: string, variants: NormalizedVariant[], productImages: string[]): EbayTradingPayload['variationPictures'] {
  const sets = new Map<string, string[]>();
  for (const variant of variants) {
    const value = variant.attributes[visualKey];
    if (!value) continue;
    const urls = validImageUrls([variant.imageUrls.length ? variant.imageUrls : productImages]).slice(0, 12);
    if (urls.length === 0) continue;
    sets.set(value, [...new Set([...(sets.get(value) ?? []), ...urls])]);
  }
  if (sets.size === 0) return undefined;
  return { variationSpecificName: visualKey, sets: [...sets.entries()].map(([value, urls]) => ({ value, urls })) };
}

function validateFees(attempt: EbayAttemptResult): string[] {
  const config = getConfig().listing;
  const maxFee = Math.max(config.maxAllowedInsertionFee, config.maxAllowedListingFee);
  return attempt.fees
    .filter((fee) => fee.amount > maxFee)
    .map((fee) => `Unexpected eBay fee ${fee.name}: ${fee.amount.toFixed(2)} ${fee.currency}.`);
}

function stableVariationKeys(variants: NormalizedVariant[], categoryId: string): string[] {
  const counts = new Map<string, number>();
  for (const variant of variants) {
    for (const key of Object.keys(variant.attributes)) {
      const normalized = normalizeAttributeName(key, categoryId);
      counts.set(normalized, (counts.get(normalized) ?? 0) + 1);
    }
  }
  const keys = [...counts.entries()].filter(([, count]) => count === variants.length).map(([key]) => key);
  const preferred = ['Color', 'Size', 'US Shoe Size', 'Style', 'Pattern', 'Model', 'Plug Type', 'Material', 'Type', 'Ships From', 'Capacity', 'Quantity', 'Specification'];
  return preferred.filter((key) => keys.includes(key)).concat(keys.filter((key) => !preferred.includes(key)));
}

function normalizeVariantAttributeMap(attributes: Record<string, string>, categoryId: string): Record<string, string> {
  const normalized: Record<string, string> = {};
  for (const [name, value] of Object.entries(attributes)) {
    const key = normalizeAttributeName(name, categoryId);
    if (key && value && !normalized[key]) {
      normalized[key] = value;
    }
  }
  return normalized;
}

function repairVariantsForEbay(variants: NormalizedVariant[], productCost: number): { variants: NormalizedVariant[]; warnings: string[] } {
  const warnings: string[] = [];
  let repaired = variants.map((variant, index) => {
    const attributes = Object.fromEntries(
      Object.entries(variant.attributes)
        .map(([name, value]) => [normalizeAttributeName(name), normalizeAttributeValue(value)] as const)
        .filter(([name, value]) => name && value),
    );
    if (Object.keys(attributes).length === 0) {
      attributes.Specification = variantLabel(variant, index);
      warnings.push(`${variant.cleanSku}: missing variation attributes repaired with Specification.`);
    }

    const cost = variant.cost > 0 ? variant.cost : productCost;
    if (variant.cost <= 0 && productCost > 0) {
      warnings.push(`${variant.cleanSku}: missing variant cost repaired from product cost.`);
    }

    const fallbackSku = `CJ-${variant.cjVariantId || index + 1}`;
    const sku = cleanSku(variant.cleanSku || variant.rawSku || variant.cjVariantId, fallbackSku);
    return {
      ...variant,
      cleanSku: sku,
      price: cost,
      cost,
      quantity: Math.max(0, Math.floor(Number.isFinite(variant.quantity) ? variant.quantity : 0)),
      attributes,
      isValid: cost > 0,
      invalidReason: cost > 0 ? undefined : 'Missing product or variant cost.',
    };
  });

  const skuCounts = new Map<string, number>();
  repaired = repaired.map((variant) => {
    const count = (skuCounts.get(variant.cleanSku) ?? 0) + 1;
    skuCounts.set(variant.cleanSku, count);
    if (count === 1) return variant;
    const uniqueSku = cleanSku(`${variant.cleanSku}-${count}`, `CJ-${variant.cjVariantId}-${count}`);
    warnings.push(`${variant.cleanSku}: duplicate SKU repaired as ${uniqueSku}.`);
    return { ...variant, cleanSku: uniqueSku };
  });

  if (hasDuplicateCombos(repaired)) {
    warnings.push('Duplicate variation combinations repaired with unique Specification values.');
    repaired = repaired.map((variant, index) => ({
      ...variant,
      attributes: { ...variant.attributes, Specification: variantLabel(variant, index) },
    }));
  }

  const comboCounts = new Map<string, number>();
  repaired = repaired.map((variant, index) => {
    const combo = comboKey(variant.attributes);
    const count = (comboCounts.get(combo) ?? 0) + 1;
    comboCounts.set(combo, count);
    if (count === 1) return variant;
    return {
      ...variant,
      attributes: { ...variant.attributes, Specification: `${variantLabel(variant, index)} ${count}`.slice(0, 65) },
    };
  });

  return { variants: repaired, warnings };
}

function hasDuplicateCombos(variants: NormalizedVariant[]): boolean {
  const seen = new Set<string>();
  for (const variant of variants) {
    const key = comboKey(variant.attributes);
    if (seen.has(key)) return true;
    seen.add(key);
  }
  return false;
}

function variantLabel(variant: NormalizedVariant, index: number): string {
  const raw = sanitizeText(variant.rawSku || variant.cjVariantId || `Option ${index + 1}`, 65);
  const compact = raw
    .replace(/^CJ[A-Z0-9]+[-_]?/i, '')
    .replace(/[-_]+/g, ' ')
    .trim();
  return normalizeAttributeValue(compact || `Option ${index + 1}`) || `Option ${index + 1}`;
}

function normalizeVariantRows(product: Record<string, unknown>, variantsResponse: unknown): Record<string, unknown>[] {
  const direct = Array.isArray(product.variants) ? product.variants : [];
  const external = flattenRows(variantsResponse);
  const seen = new Set<string>();
  return [...direct, ...external].filter((row): row is Record<string, unknown> => {
    if (!row || typeof row !== 'object') return false;
    const record = row as Record<string, unknown>;
    const key = variantRowIdentity(record, product);
    if (!key) return true;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function variantRowIdentity(record: Record<string, unknown>, product: Record<string, unknown>): string {
  const stable = [
    ['vid', record.vid],
    ['variantId', record.variantId],
    ['variantSku', record.variantSku],
    ['sku', record.sku],
    ['variantKey', record.variantKey],
  ]
    .map(([field, value]) => [field, sanitizeText(value, 200).toLowerCase()] as const)
    .find(([, value]) => Boolean(value));
  if (stable) return `${stable[0]}:${stable[1]}`;

  const productIds = [product.pid, product.id, product.productId, product.productSku]
    .map((value) => sanitizeText(value, 200).toLowerCase())
    .filter(Boolean);
  const genericId = sanitizeText(record.id, 200).toLowerCase();
  if (genericId && !productIds.includes(genericId)) return `id:${genericId}`;

  const name = sanitizeText(record.variantNameEn ?? record.variantName, 200).toLowerCase();
  return name ? `name:${name}` : '';
}

function declaredVariantNames(product: Record<string, unknown>): string[] {
  const raw = product.productKeyEn ?? product.productKey ?? product.variantKey;
  const values = Array.isArray(raw) ? raw : tokenizeVariantKey(raw);
  return values.flatMap((name) => String(name).split(/[,+/&|]/)).map((name) => normalizeAttributeName(name)).filter(Boolean);
}

function inventoryByVariantId(raw: unknown): Map<string, number> {
  const map = new Map<string, number>();
  const data = unwrapData(raw);
  const entries = Array.isArray(data.variantInventories) ? data.variantInventories : [];
  for (const entry of entries) {
    if (!entry || typeof entry !== 'object') continue;
    const record = entry as Record<string, unknown>;
    const vid = String(record.vid ?? '');
    const quantity = inventoryQuantity(record.inventory) ?? 0;
    if (vid) map.set(vid, quantity);
  }
  return map;
}

function inventoryQuantity(raw: unknown): number | undefined {
  if (raw == null) return undefined;
  if (typeof raw === 'number') return Math.max(0, raw);
  if (Array.isArray(raw)) {
    const totals = raw.map(inventoryQuantity).filter((value): value is number => value != null);
    return totals.length ? totals.reduce((sum, value) => sum + value, 0) : undefined;
  }
  if (typeof raw === 'object') {
    const row = raw as Record<string, unknown>;
    for (const key of ['totalInventoryNum', 'totalInventory', 'storageNum', 'inventory', 'warehouseInventoryNum']) {
      const value = Number(row[key]);
      if (Number.isFinite(value)) return Math.max(0, value);
    }
    if (Array.isArray(row.stock)) return inventoryQuantity(row.stock);
    if (Array.isArray(row.inventories)) return inventoryQuantity(row.inventories);
    if (Array.isArray(row.inventory)) return inventoryQuantity(row.inventory);
    const cj = Number(row.cjInventory ?? row.cjInventoryNum ?? 0);
    const factory = Number(row.factoryInventory ?? row.factoryInventoryNum ?? 0);
    if (cj || factory) return Math.max(0, cj + factory);
  }
  return undefined;
}

function flattenRows(raw: unknown): Record<string, unknown>[] {
  const data = unwrapData(raw);
  if (Array.isArray(data)) return data.filter((row): row is Record<string, unknown> => Boolean(row && typeof row === 'object'));
  for (const key of ['list', 'content', 'records', 'rows', 'products', 'variants', 'data']) {
    const value = (data as Record<string, unknown>)[key];
    if (Array.isArray(value)) return value.filter((row): row is Record<string, unknown> => Boolean(row && typeof row === 'object'));
    if (value && typeof value === 'object') {
      const nested = flattenRows(value);
      if (nested.length) return nested;
    }
  }
  return [];
}

function unwrapData(raw: unknown): Record<string, unknown> {
  if (!raw || typeof raw !== 'object') return {};
  const record = raw as Record<string, unknown>;
  if (record.data && typeof record.data === 'object' && !Array.isArray(record.data)) return record.data as Record<string, unknown>;
  return record;
}

function positiveNumber(value: unknown, fallback: number): number {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : fallback;
}

function fieldValues(row: Record<string, unknown>, keys: string[]): string[] {
  return keys.map((key) => sanitizeText(row[key], 80)).filter(Boolean);
}

function inferBrand(product: Record<string, unknown>): string {
  const brand = sanitizeText(product.brandName ?? product.brand ?? product.productBrand ?? product.manufacturer, 65);
  return brand && !/^\d+$/.test(brand) ? brand : 'Unbranded';
}

function inferType(product: NormalizedCJProduct): string {
  const title = product.cleanTitle.toLowerCase();
  if (/shoe\s+(?:cabinet|organizer|rack|storage|shelf)|(?:cabinet|organizer|rack|storage|shelf).*shoe/.test(title)) return 'Shoe Organizer';
  if (/\b(?:sneaker|running shoe|walking shoe|boot|sandal)\b/.test(title)) return 'Shoes';
  if (/\b(?:water bottle|bottle|mug|cup)\b/.test(title)) return 'Bottle';
  if (/\b(?:phone case|charger|usb cable|adapter)\b/.test(title)) return 'Cell Phone Accessory';
  const path = sanitizeText(product.cjCategoryPath, 120);
  if (path) return path.split(/[>/,]/).map((part) => part.trim()).filter(Boolean).at(-1) ?? 'General Product';
  return product.cleanTitle.split(/\s+/).slice(0, 3).join(' ') || 'General Product';
}

function inferSpecific(product: NormalizedCJProduct, name: string): string {
  const lower = name.toLowerCase();
  const firstVariant = product.variants[0];
  if (lower === 'brand') return product.brand || 'Unbranded';
  if (lower === 'type') return inferType(product);
  if (lower.includes('color')) return firstVariant?.attributes.Color ?? '';
  if (lower.includes('size')) return firstVariant?.attributes['US Shoe Size'] ?? firstVariant?.attributes.Size ?? '';
  if (lower.includes('material')) return firstVariant?.attributes.Material ?? '';
  if (lower.includes('style')) return firstVariant?.attributes.Style ?? '';
  if (lower.includes('model')) return firstVariant?.attributes.Model ?? '';
  if (lower === 'mpn') return 'Does not apply';
  return '';
}

function safeOptionalSpecificValue(product: NormalizedCJProduct, aspect: EbayAspectRequirement): string {
  const lower = aspect.name.toLowerCase();
  const firstVariant = product.variants[0];
  const dimensions = firstVariant?.dimensions;
  const weight = firstVariant?.weight;
  if (lower === 'brand' || lower === 'type' || lower === 'mpn') return inferSpecific(product, aspect.name);
  if (lower.includes('color')) return firstVariant?.attributes.Color ?? '';
  if (lower.includes('size')) return firstVariant?.attributes['US Shoe Size'] ?? firstVariant?.attributes.Size ?? '';
  if (lower.includes('material') || lower.includes('finish')) return firstVariant?.attributes.Material ?? '';
  if (lower.includes('style') || lower.includes('pattern')) return firstVariant?.attributes.Style ?? firstVariant?.attributes.Pattern ?? '';
  if (lower.includes('model')) return firstVariant?.attributes.Model ?? '';
  if (lower.includes('height')) return formatMillimeters(dimensions?.height);
  if (lower.includes('length') || lower.includes('depth')) return formatMillimeters(dimensions?.length);
  if (lower.includes('width')) return formatMillimeters(dimensions?.width);
  if (lower.includes('weight')) return weight && weight > 0 ? `${Math.round(weight)} g` : '';
  if (lower.includes('number of items')) return '1';
  return '';
}

function formatMillimeters(value?: number): string {
  if (!value || value <= 0) return '';
  if (value >= 100) return `${Math.round(value / 10)} cm`;
  return `${Math.round(value)} mm`;
}

function fallbackSpecificValue(product: NormalizedCJProduct, aspect: EbayAspectRequirement): string {
  const lower = aspect.name.toLowerCase();
  const values = aspect.values;
  const firstVariant = product.variants[0];
  const titleAndPath = `${product.cleanTitle} ${product.cjCategoryPath ?? ''}`.toLowerCase();
  const pick = (...candidates: string[]) => {
    for (const candidate of candidates.map((value) => sanitizeText(value, 500)).filter(Boolean)) {
      const exact = values.find((allowed) => allowed.toLowerCase() === candidate.toLowerCase());
      if (exact) return exact;
      if (!values.length) return candidate;
    }
    return '';
  };
  const pickContaining = (...needles: string[]) => values.find((allowed) => needles.some((needle) => allowed.toLowerCase().includes(needle))) ?? '';

  if (lower === 'brand') return pick(product.brand, 'Unbranded') || 'Unbranded';
  if (lower === 'type') return pick(inferType(product), product.cleanTitle.split(/\s+/).slice(0, 3).join(' '), 'General Product');
  if (lower.includes('color')) return pick(firstVariant?.attributes.Color ?? '', 'Multicolor', 'Multi-Color', 'Black', 'White');
  if (lower.includes('size')) return pick(firstVariant?.attributes['US Shoe Size'] ?? '', firstVariant?.attributes.Size ?? '', 'One Size');
  if (lower.includes('material')) return pick(firstVariant?.attributes.Material ?? '', inferFromText(titleAndPath, ['metal', 'steel', 'plastic', 'wood', 'cotton', 'polyester', 'leather', 'rubber', 'silicone']));
  if (lower.includes('style')) return pick(firstVariant?.attributes.Style ?? '', firstVariant?.attributes.Specification ?? '', 'Modern');
  if (lower.includes('model')) return pick(firstVariant?.attributes.Model ?? '', product.cjProductId);
  if (lower.includes('department')) {
    if (/women|girl/.test(titleAndPath)) return pickContaining('women') || pick('Women');
    if (/men|boy/.test(titleAndPath)) return pickContaining('men') || pick('Men');
    return pickContaining('unisex') || pick('Unisex Adults', 'Adults');
  }
  if (lower.includes('upper material')) return pick(firstVariant?.attributes.Material ?? '', 'Synthetic');
  if (lower.includes('closure')) return pickContaining('lace', 'slip', 'zip') || pick('Slip On');
  if (['mpn', 'manufacturer part number', 'part number', 'ean', 'upc', 'gtin'].includes(lower)) {
    return pick('Does not apply', 'Not Applicable', product.cjProductId);
  }

  const inferred = inferSpecific(product, aspect.name);
  if (inferred) return pick(inferred);
  return pick('Does not apply', 'Not Applicable', 'Unbranded') || values[0] || '';
}

function inferFromText(text: string, candidates: string[]): string {
  return candidates.find((candidate) => text.includes(candidate)) ?? '';
}

function typeLooksIncompatible(product: NormalizedCJProduct, value: string): boolean {
  const title = product.cleanTitle.toLowerCase();
  const specific = value.toLowerCase();
  const groups = [
    { product: /shoe\s+(?:cabinet|organizer|rack|storage|shelf)|(?:cabinet|organizer|rack|storage|shelf).*shoe/, value: /shoe|cabinet|organizer|rack|storage|shelf/ },
    { product: /\b(?:sneaker|running shoe|walking shoe|boot|sandal)\b/, value: /shoe|sneaker|boot|sandal/ },
    { product: /\b(?:water bottle|bottle|mug|cup)\b/, value: /bottle|mug|cup|drink/ },
    { product: /\b(?:phone case|charger|usb cable|adapter)\b/, value: /phone|case|charger|cable|adapter|usb/ },
  ];
  return groups.some((group) => group.product.test(title) && !group.value.test(specific));
}

function normalizeAspectValue(value: string, aspect: EbayAspectRequirement): string {
  const clean = sanitizeText(value, 500);
  const exact = aspect.values.find((allowed) => allowed.toLowerCase() === clean.toLowerCase());
  if (exact) return exact;
  if (aspect.name.toLowerCase() === 'brand') return clean || 'Unbranded';
  const closest = closestAspectValue(clean, aspect.values);
  if (closest) return closest;
  return aspect.values.length && aspect.required ? aspect.values[0] : clean;
}

function closestAspectValue(value: string, allowedValues: string[]): string {
  const tokens = meaningfulTokens(value);
  if (!tokens.length || !allowedValues.length) return '';
  let best = '';
  let bestScore = 0;
  for (const allowed of allowedValues) {
    const allowedTokens = meaningfulTokens(allowed);
    const score = tokens.filter((token) => allowedTokens.includes(token)).length;
    if (score > bestScore) {
      best = allowed;
      bestScore = score;
    }
  }
  return bestScore > 0 ? best : '';
}

function meaningfulTokens(value: string): string[] {
  const stop = new Set(['with', 'and', 'for', 'the', 'type', 'item', 'general', 'product']);
  return sanitizeText(value, 500).toLowerCase().split(/[^a-z0-9]+/).filter((token) => token.length > 2 && !stop.has(token));
}

function skippedAttempt(errors: string[]): EbayAttemptResult {
  return { attemptedAt: new Date().toISOString(), mode: 'build_only', ack: 'Skipped', errors, warnings: [], fees: [] };
}

function notRunAttempt(mode: 'build_only' | 'live'): EbayAttemptResult {
  return { attemptedAt: new Date().toISOString(), mode, ack: 'NotRun', errors: [], warnings: [], fees: [] };
}

function inventoryDraftSkipped(cjProductId: string, errors: string[]): EbayInventoryDraftResult {
  return {
    cjProductId,
    title: '',
    status: 'skipped',
    offerIds: [],
    skuKeys: [],
    errors,
    warnings: [],
  };
}

function inventoryItemFromPayload(payload: EbayTradingPayload, variation?: EbayTradingVariation): Record<string, unknown> {
  const variationImages = variation ? payload.variationPictures?.sets.find((set) => variation.specifics[payload.variationPictures?.variationSpecificName ?? ''] === set.value)?.urls : undefined;
  return {
    availability: {
      shipToLocationAvailability: {
        quantity: Math.max(0, Math.floor(variation?.quantity ?? payload.quantity ?? 0)),
      },
    },
    condition: 'NEW',
    product: {
      title: payload.title,
      description: sanitizeText(payload.descriptionHtml.replace(/<[^>]+>/g, ' '), 4000),
      aspects: inventoryAspects({ ...payload.itemSpecifics, ...(variation?.specifics ?? {}) }),
      imageUrls: validImageUrls([variationImages, payload.images]).slice(0, 12),
    },
  };
}

function inventoryItemGroupFromPayload(groupKey: string, payload: EbayTradingPayload, variations: EbayTradingVariation[]): Record<string, unknown> {
  const variationSpecificsSet = payload.variationSpecificsSet ?? buildVariationSpecificsSet(variations);
  return {
    inventoryItemGroupKey: groupKey,
    title: payload.title,
    description: sanitizeText(payload.descriptionHtml.replace(/<[^>]+>/g, ' '), 4000),
    imageUrls: payload.images.slice(0, 12),
    aspects: inventoryAspects(payload.itemSpecifics),
    variantSKUs: variations.map((variation) => variation.sku),
    variesBy: {
      aspectsImageVariesBy: payload.variationPictures?.variationSpecificName ? [payload.variationPictures.variationSpecificName] : [],
      specifications: Object.entries(variationSpecificsSet).map(([name, values]) => ({ name, values })),
    },
  };
}

function offerFromPayload(
  payload: EbayTradingPayload,
  sku: string,
  quantity: number,
  price: number,
  merchantLocationKey: string,
  policies: { fulfillmentPolicyId?: string; paymentPolicyId?: string; returnPolicyId?: string },
): Record<string, unknown> {
  const listingPolicies = Object.fromEntries(
    Object.entries({
      fulfillmentPolicyId: policies.fulfillmentPolicyId,
      paymentPolicyId: policies.paymentPolicyId,
      returnPolicyId: policies.returnPolicyId,
    }).filter(([, value]) => Boolean(value)),
  );
  return {
    sku,
    marketplaceId: getConfig().ebay.marketplaceId,
    format: 'FIXED_PRICE',
    availableQuantity: Math.max(0, Math.floor(quantity)),
    categoryId: payload.categoryId,
    listingDescription: payload.descriptionHtml,
    merchantLocationKey,
    pricingSummary: {
      price: {
        currency: payload.currency,
        value: moneyValue(price),
      },
    },
    listingPolicies,
  };
}

function inventoryAspects(values: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(
    Object.entries(values)
      .map(([name, value]) => [sanitizeText(name, 65), sanitizeText(value, 500)] as const)
      .filter(([name, value]) => name && value)
      .map(([name, value]) => [name, [value]]),
  );
}

function chunkStrings(items: string[], size: number): string[][] {
  const chunks: string[][] = [];
  for (let index = 0; index < items.length; index += size) chunks.push(items.slice(index, index + size));
  return chunks;
}

function moneyValue(value: number): string {
  return Math.max(0, value).toFixed(2);
}

function envText(key: string, fallback: string): string {
  return process.env[key]?.trim() || fallback;
}

function stringOrUndefined(value: unknown): string | undefined {
  const text = sanitizeText(value, 300);
  return text || undefined;
}

function roundToRule(value: number, roundTo: number): number {
  if (roundTo <= 0) return Math.round(value * 100) / 100;
  if (roundTo === 0.99) return Math.max(0.99, Math.floor(value) + 0.99);
  return Math.ceil(value / roundTo) * roundTo;
}
