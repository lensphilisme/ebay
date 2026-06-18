import { createAIProvider, type AIProvider } from '@/backend/services/ai/provider';
import { detectDuplicate, type DuplicateContext } from '@/backend/services/duplicate-detection';
import { scoreImages } from '@/backend/services/image-scoring';
import { calculateProfit } from '@/backend/services/pricing';
import type { CjProduct, CjVariant, FreightQuote, ListingDraft, MarketComparison } from '@/shared/types';

export interface ListingBuilderInput {
  product: CjProduct;
  variant?: CjVariant;
  freight: FreightQuote;
  marketComparison?: MarketComparison;
  duplicateContext: Omit<DuplicateContext, 'product' | 'variant'>;
  desiredProfit?: number;
  aiProvider?: AIProvider;
}

export async function buildListingDraft(input: ListingBuilderInput): Promise<ListingDraft> {
  const ai = input.aiProvider ?? createAIProvider();
  const itemSpecifics = await ai.extractItemSpecifics({ title: input.product.title, raw: input.product.raw });
  const title = await ai.generateTitle({ sourceTitle: input.product.title, itemSpecifics, maxLength: 80 });
  const bulletFeatures = createBullets(input.product, input.variant);
  const description = await ai.generateDescription({ title, bullets: bulletFeatures, itemSpecifics });
  const duplicateDecision = detectDuplicate({ product: input.product, variant: input.variant, ...input.duplicateContext });
  const profit = calculateProfit({
    productCost: input.variant?.price ?? input.product.price,
    shippingCost: input.freight.shippingCost,
    desiredProfit: input.desiredProfit,
    marketComparison: input.marketComparison,
  });

  return {
    id: crypto.randomUUID(),
    cjProductId: input.product.id,
    cjVariantId: input.variant?.id,
    title,
    description,
    bulletFeatures,
    itemSpecifics,
    categoryId: input.product.categoryId,
    condition: 'New',
    brand: itemSpecifics.Brand,
    model: itemSpecifics.Model,
    quantity: Math.max(0, input.variant?.inventory ?? 1),
    sku: input.variant?.sku ?? `CJ-${input.product.id}`,
    images: scoreImages(input.product.imageUrls),
    price: profit.targetPrice,
    profit,
    marketComparison: input.marketComparison,
    duplicateDecision,
    approvalStatus: 'pending',
    actionPreview: [
      `Create eBay inventory item for SKU ${input.variant?.sku ?? `CJ-${input.product.id}`}.`,
      `Draft offer at $${profit.targetPrice.toFixed(2)} with estimated profit $${profit.estimatedProfit.toFixed(2)}.`,
      duplicateDecision.status === 'blocked' ? 'Block publishing until duplicate warning is resolved.' : 'Route draft to approval queue.',
    ],
    auditReason: 'Listing draft generated from CJ product data, market comparison, duplicate checks, and pricing rules.',
  };
}

function createBullets(product: CjProduct, variant?: CjVariant): string[] {
  const bullets = [
    'Brand-new item sourced from CJ Dropshipping supplier data.',
    'Marketplace-ready listing with CJ product and variant mapping retained.',
    'Price includes product cost, freight, eBay fee buffer, and promoted listing buffer.',
  ];
  if (variant?.inventory != null) bullets.push(`${variant.inventory} units reported in CJ inventory for the selected variant.`);
  if (product.weight) bullets.push(`Product weight: ${product.weight}.`);
  return bullets;
}
