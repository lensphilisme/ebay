import type { EbayComparableListing, MarketComparison } from '@/shared/types';

function median(values: number[]): number {
  if (values.length === 0) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function tokenSet(value: string): Set<string> {
  return new Set(value.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').split(/\s+/).filter((part) => part.length > 2));
}

export function titleSimilarity(a: string, b: string): number {
  const left = tokenSet(a);
  const right = tokenSet(b);
  if (left.size === 0 || right.size === 0) return 0;
  const intersection = [...left].filter((token) => right.has(token)).length;
  const union = new Set([...left, ...right]).size;
  return intersection / union;
}

export function compareMarketListings(cjTitle: string, comparables: EbayComparableListing[]): MarketComparison {
  const query = cjTitle.slice(0, 75);
  const scored = comparables.map((listing) => ({ listing, similarity: titleSimilarity(cjTitle, listing.title), totalPrice: listing.price + listing.shippingCost }));
  const relevant = scored.filter((entry) => entry.similarity >= 0.22 && entry.totalPrice > 0);
  const prices = relevant.map((entry) => entry.totalPrice);
  const med = median(prices);
  const withoutOutliers = relevant.filter((entry) => med === 0 || (entry.totalPrice >= med * 0.55 && entry.totalPrice <= med * 1.8));
  const rejectedOutliers = relevant.filter((entry) => !withoutOutliers.includes(entry)).map((entry) => entry.listing);
  const reasonablePrices = withoutOutliers.map((entry) => entry.totalPrice);
  const average = reasonablePrices.length ? reasonablePrices.reduce((sum, price) => sum + price, 0) / reasonablePrices.length : 0;
  const confidenceScore = Math.min(1, (withoutOutliers.length / 12) * 0.55 + (withoutOutliers.reduce((sum, entry) => sum + entry.similarity, 0) / Math.max(withoutOutliers.length, 1)) * 0.45);

  return {
    query,
    comparables: withoutOutliers.map((entry) => entry.listing),
    averageMarketPrice: round(average),
    medianMarketPrice: round(median(reasonablePrices)),
    lowestReasonablePrice: round(Math.min(...reasonablePrices, 0)),
    highestReasonablePrice: round(Math.max(...reasonablePrices, 0)),
    recommendedListingPrice: round(confidenceScore > 0.75 ? median(reasonablePrices) || average : average),
    confidenceScore: round(confidenceScore),
    rejectedOutliers,
    reasons: [
      `Used first ${query.length} characters of the CJ title as the initial eBay query.`,
      `Accepted ${withoutOutliers.length} comparable listings and rejected ${rejectedOutliers.length} outliers.`,
      confidenceScore < 0.45 ? 'Weak comparable set: block auto-publish and require manual approval.' : 'Comparable set is strong enough for draft pricing guidance.',
    ],
  };
}

function round(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.round(value * 100) / 100;
}
