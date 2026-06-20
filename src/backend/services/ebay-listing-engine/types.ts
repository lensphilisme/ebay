export type ListingAttemptMode = 'build_only' | 'preflight' | 'live';

export interface NormalizedVariant {
  cjVariantId: string;
  rawSku: string;
  cleanSku: string;
  price: number;
  cost: number;
  quantity: number;
  attributes: Record<string, string>;
  imageUrls: string[];
  weight?: number;
  dimensions?: { length?: number; width?: number; height?: number };
  shippingCost: number;
  calculatedEbayPrice: number;
  isValid: boolean;
  invalidReason?: string;
  rawDataReference: unknown;
}

export interface NormalizedCJProduct {
  cjProductId: string;
  source: 'cj';
  title: string;
  cleanTitle: string;
  descriptionHtml: string;
  cleanDescriptionHtml: string;
  cjCategoryId?: string;
  cjCategoryPath?: string;
  ebayCategoryId?: string;
  brand: string;
  conditionId: string;
  productImages: string[];
  itemSpecifics: Record<string, string>;
  variants: NormalizedVariant[];
  shippingData: { countryCode: string; dispatchTimeMax: number; shippingService: string; postalCode: string };
  baseCost: number;
  calculatedPrice: number;
  currency: 'USD';
  sourceUrl?: string;
  rawDataReference: unknown;
  warnings: string[];
  skipReasons: string[];
}

export interface EbayAspectRequirement {
  name: string;
  required: boolean;
  values: string[];
  variationEnabled: boolean;
}

export interface EbayTradingVariation {
  sku: string;
  quantity: number;
  startPrice: number;
  specifics: Record<string, string>;
}

export interface EbayTradingPayload {
  title: string;
  categoryId: string;
  conditionId: string;
  country: 'US';
  currency: 'USD';
  postalCode: string;
  location: string;
  dispatchTimeMax: number;
  listingDuration: 'GTC';
  listingType: 'FixedPriceItem';
  descriptionHtml: string;
  itemSpecifics: Record<string, string>;
  images: string[];
  sku?: string;
  quantity?: number;
  startPrice?: number;
  variations?: EbayTradingVariation[];
  variationSpecificsSet?: Record<string, string[]>;
  variationPictures?: { variationSpecificName: string; sets: Array<{ value: string; urls: string[] }> };
  returnPolicy: {
    returnsAcceptedOption: 'ReturnsAccepted';
    refundOption: 'MoneyBack';
    returnsWithinOption: 'Days_30';
    shippingCostPaidByOption: 'Buyer';
  };
  shippingDetails: {
    shippingType: 'Flat';
    shippingService: string;
    shippingServiceCost: number;
  };
  debug: {
    cjProductId: string;
    visualVariationKey?: string;
    warnings: string[];
  };
}

export interface PreflightIssue {
  code: string;
  severity: 'error' | 'warning';
  message: string;
}

export interface EbayAttemptResult {
  attemptedAt: string;
  mode: ListingAttemptMode;
  ack: 'Success' | 'Warning' | 'Failure' | 'Skipped' | 'NotRun';
  itemId?: string;
  requestXml?: string;
  responseXml?: string;
  errors: string[];
  warnings: string[];
  fees: Array<{ name: string; amount: number; currency: string }>;
}

export interface ListingEngineResult {
  cjProductId: string;
  title: string;
  productType: string;
  variantKeys: string[];
  variantCount: number;
  imageCount: number;
  selectedEbayCategory?: string;
  payload?: EbayTradingPayload;
  requestXml?: string;
  preflight: PreflightIssue[];
  ebayAttempt: EbayAttemptResult;
  status: 'passed' | 'failed' | 'skipped';
  errors: string[];
  warnings: string[];
}

export interface BulkListingResult {
  startedAt: string;
  finishedAt: string;
  liveMode: boolean;
  total: number;
  passed: number;
  failed: number;
  skipped: number;
  results: ListingEngineResult[];
}

export interface EbayInventoryDraftResult {
  cjProductId: string;
  title: string;
  status: 'passed' | 'failed' | 'skipped';
  selectedEbayCategory?: string;
  offerIds: string[];
  inventoryItemGroupKey?: string;
  skuKeys: string[];
  errors: string[];
  warnings: string[];
  raw?: unknown;
}

export interface BulkEbayInventoryDraftResult {
  startedAt: string;
  finishedAt: string;
  total: number;
  passed: number;
  failed: number;
  skipped: number;
  results: EbayInventoryDraftResult[];
}
