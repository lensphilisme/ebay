export type AutomationMode = 'approval' | 'semi_auto' | 'full_auto';
export type IntegrationProvider = 'ebay' | 'cj' | 'ai';
export type IntegrationStatus = 'connected' | 'not_connected' | 'degraded' | 'error';

export interface IntegrationHealth {
  provider: IntegrationProvider;
  status: IntegrationStatus;
  environment: 'sandbox' | 'production' | 'local';
  checkedAt: string;
  message: string;
  canRead: boolean;
  canWrite: boolean;
}

export interface CjProductSearchFilters {
  keyword?: string;
  categoryId?: string;
  countryCode?: string;
  isWarehouse?: boolean;
  minInventory?: number;
  maxInventory?: number;
  minPrice?: number;
  maxPrice?: number;
  productFlag?: number;
  orderBy?: number;
  sort?: 'asc' | 'desc';
  addMarkStatus?: number;
  features?: string[];
  shippingCountry?: string;
  productWeightMax?: number;
  minimumRating?: number;
  zonePlatform?: string;
  excludeAlreadyListed?: boolean;
  excludeDuplicates?: boolean;
  pageNum?: number;
  pageSize?: number;
}

export interface CjProduct {
  id: string;
  title: string;
  categoryId?: string;
  supplierId?: string;
  price: number;
  weight?: number;
  imageUrls: string[];
  raw: Record<string, unknown>;
}

export interface CjVariant {
  id: string;
  productId: string;
  sku?: string;
  price: number;
  weight?: number;
  inventory: number;
  attributes: Record<string, string>;
  raw: Record<string, unknown>;
}

export interface FreightQuote {
  productId: string;
  variantId?: string;
  sourceCountry?: string;
  destinationCountry: string;
  shippingCost: number;
  deliveryDays?: string;
  logisticsName?: string;
  raw?: unknown;
}

export interface EbayComparableListing {
  itemId: string;
  title: string;
  categoryId?: string;
  price: number;
  shippingCost: number;
  condition?: string;
  sellerUsername?: string;
  url?: string;
  raw?: unknown;
}

export interface MarketComparison {
  query: string;
  comparables: EbayComparableListing[];
  averageMarketPrice: number;
  medianMarketPrice: number;
  lowestReasonablePrice: number;
  highestReasonablePrice: number;
  recommendedListingPrice: number;
  confidenceScore: number;
  rejectedOutliers: EbayComparableListing[];
  reasons: string[];
}

export interface ProfitCalculation {
  landedCost: number;
  marketplaceBufferPercent: number;
  breakEvenPrice: number;
  targetProfit: number;
  targetPrice: number;
  estimatedFees: number;
  estimatedProfit: number;
  marginPercent: number;
  cappedByMarket: boolean;
  explanation: string[];
}

export interface DuplicateSignal {
  source: 'cj_product_id' | 'cj_variant_id' | 'normalized_title' | 'image' | 'dimensions' | 'sku' | 'supplier_similarity' | 'ebay_active_title';
  score: number;
  reason: string;
}

export interface DuplicateDecision {
  status: 'clear' | 'warning' | 'blocked';
  riskScore: number;
  signals: DuplicateSignal[];
  explanation: string;
}

export interface ImageScore {
  url: string;
  score: number;
  reasons: string[];
  cropPreview: {
    mode: 'contain' | 'cover';
    focalPoint: 'center' | 'top' | 'left' | 'right';
  };
  isRecommendedMain: boolean;
  aiEnhancementStatus: 'not_configured' | 'queued' | 'complete';
}

export interface ListingDraft {
  id: string;
  cjProductId: string;
  cjVariantId?: string;
  title: string;
  subtitle?: string;
  description: string;
  bulletFeatures: string[];
  itemSpecifics: Record<string, string>;
  categoryId?: string;
  condition: string;
  brand?: string;
  model?: string;
  quantity: number;
  sku: string;
  images: ImageScore[];
  price: number;
  profit: ProfitCalculation;
  marketComparison?: MarketComparison;
  duplicateDecision: DuplicateDecision;
  approvalStatus: 'pending' | 'approved' | 'rejected';
  actionPreview: string[];
  auditReason: string;
}

export interface AutomationRules {
  feePercentage: number;
  adPercentage: number;
  minimumProfit: number;
  maximumProfit: number;
  priceDropSchedule: Array<{ afterDays: number; dropPercent: number }>;
  titleRewriteAfterDays: number;
  imageChangeAfterDays: number;
  endListingAfterDays: number;
  autoListCategories: string[];
  automationMode: AutomationMode;
  dryRun: boolean;
}

export interface ListingPerformance {
  listingId: string;
  title: string;
  daysLive: number;
  views: number;
  clicks?: number;
  clicksEstimated?: number;
  clickDataAvailable?: boolean;
  watchers: number;
  sales: number;
  trafficTransactions?: number;
  lifetimeSold?: number;
  impressions?: number;
  trafficAvailable?: boolean;
  currentPrice: number;
  landedCost: number;
  cjStock: number;
  cjCostChanged: boolean;
  competitorPriceDropped: boolean;
}

export interface OptimizationRecommendation {
  listingId: string;
  action: 'improve_title_specifics' | 'change_image_title' | 'reduce_price' | 'rewrite_relist' | 'end_listing' | 'pause_for_stock' | 'increase_price' | 'none';
  severity: 'info' | 'warning' | 'critical';
  mode: AutomationMode;
  requiresApproval: boolean;
  reason: string;
  preview: string[];
}

export interface EbayListingSnapshot {
  listingId: string;
  offerId?: string;
  sku?: string;
  title: string;
  status: string;
  price: number;
  quantityAvailable: number;
  quantitySold: number;
  watchers?: number;
  imageUrl?: string;
  viewUrl?: string;
  marketplaceId?: string;
  createdAt?: string;
  raw: unknown;
}

export interface ListingTrafficSnapshot {
  listingId: string;
  impressions: number;
  views: number;
  clickThroughRate: number;
  salesConversionRate: number;
  transactions: number;
  clicksEstimated?: number;
  raw: unknown;
}

export interface OptimizationScanResult {
  scannedAt: string;
  windowDays: number;
  listings: EbayListingSnapshot[];
  traffic: ListingTrafficSnapshot[];
  recommendations: Array<{
    listing: EbayListingSnapshot;
    performance: ListingPerformance;
    recommendation: OptimizationRecommendation;
  }>;
  warnings: string[];
}
