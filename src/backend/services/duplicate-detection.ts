import type { CjProduct, CjVariant, DuplicateDecision, DuplicateSignal } from '@/shared/types';
import { titleSimilarity } from '@/backend/services/market-research';

export interface DuplicateContext {
  product: CjProduct;
  variant?: CjVariant;
  existingCjProductIds: string[];
  existingCjVariantIds: string[];
  existingSkus: string[];
  existingTitles: string[];
  existingImageUrls: string[];
}

export function normalizeTitle(title: string): string {
  return title.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
}

export function detectDuplicate(context: DuplicateContext): DuplicateDecision {
  const signals: DuplicateSignal[] = [];
  const normalized = normalizeTitle(context.product.title);

  if (context.existingCjProductIds.includes(context.product.id)) {
    signals.push({ source: 'cj_product_id', score: 1, reason: 'Exact CJ product ID already exists.' });
  }
  if (context.variant && context.existingCjVariantIds.includes(context.variant.id)) {
    signals.push({ source: 'cj_variant_id', score: 1, reason: 'Exact CJ variant ID already exists.' });
  }
  if (context.variant?.sku && context.existingSkus.includes(context.variant.sku)) {
    signals.push({ source: 'sku', score: 0.95, reason: 'Variant SKU already exists in listing records.' });
  }

  const strongestTitle = Math.max(0, ...context.existingTitles.map((title) => titleSimilarity(normalized, normalizeTitle(title))));
  if (strongestTitle > 0.72) {
    signals.push({ source: 'normalized_title', score: strongestTitle, reason: `Existing title similarity is ${(strongestTitle * 100).toFixed(0)}%.` });
  }

  const imageMatch = context.product.imageUrls.some((url) => context.existingImageUrls.includes(url));
  if (imageMatch) {
    signals.push({ source: 'image', score: 0.85, reason: 'At least one product image URL matches an existing listing asset.' });
  }

  const riskScore = Math.max(0, ...signals.map((signal) => signal.score));
  const status = riskScore >= 0.82 ? 'blocked' : riskScore >= 0.55 ? 'warning' : 'clear';

  return {
    status,
    riskScore: Math.round(riskScore * 100) / 100,
    signals,
    explanation: signals.length ? signals.map((signal) => signal.reason).join(' ') : 'No duplicate signals crossed the warning threshold.',
  };
}
