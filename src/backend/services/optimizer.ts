import type { AutomationMode, ListingPerformance, OptimizationRecommendation } from '@/shared/types';

export function recommendOptimization(performance: ListingPerformance, mode: AutomationMode = 'approval'): OptimizationRecommendation {
  const requiresApproval = mode !== 'full_auto';
  const clicksKnown = performance.clickDataAvailable === true && typeof performance.clicks === 'number';
  const ctr = clicksKnown ? performance.clicks! / Math.max(performance.views, 1) : undefined;
  const conversionRate = clicksKnown ? performance.sales / Math.max(performance.clicks!, 1) : undefined;

  if (performance.cjStock <= 0) {
    return recommendation(performance.listingId, 'pause_for_stock', 'critical', mode, requiresApproval, 'CJ stock is unavailable. Stop exposure before overselling.', ['Pause or end the listing', 'Record stock snapshot and CJ product ID', 'Queue restock check before relist']);
  }
  if (performance.cjCostChanged) {
    return recommendation(performance.listingId, 'reduce_price', 'critical', mode, requiresApproval, 'CJ cost or freight changed. Recalculate price before the next order.', ['Recalculate landed cost', 'Raise price if margin is below rule', 'Add audit note explaining cost change']);
  }
  if (performance.competitorPriceDropped && performance.sales === 0) {
    return recommendation(performance.listingId, 'reduce_price', 'warning', mode, requiresApproval, 'Competitor price dropped and this listing has not converted. Protect margin but test a controlled price move.', ['Compare current price against median market price', 'Reduce 2-4% only if still above break-even', 'Do not chase outliers']);
  }
  if (!performance.trafficAvailable) {
    if (performance.sales > 0) {
      return recommendation(performance.listingId, 'increase_price', 'info', mode, requiresApproval, 'Sales exist but eBay Analytics did not return traffic data for this window. Do not treat missing traffic as zero exposure.', ['Keep listing active', 'Review price after sold count updates', 'Retry analytics scan with a longer window']);
    }
    return recommendation(performance.listingId, 'none', 'info', mode, false, 'Traffic data is unavailable for this listing window. Wait for Analytics data or scan a longer window before optimizing.', ['Retry 30-90 day traffic scan', 'Do not end based on missing traffic alone']);
  }
  if (!clicksKnown && performance.sales > 0) {
    return recommendation(performance.listingId, 'increase_price', 'info', mode, requiresApproval, 'This listing has sales, but eBay did not provide source-confirmed click count. Keep it active and do not end it from CTR math.', ['Keep listing active', 'Review price and inventory', 'Use impressions/views only for search exposure checks']);
  }
  if (performance.daysLive >= 5 && performance.views < 15) {
    return recommendation(performance.listingId, 'improve_title_specifics', 'warning', mode, requiresApproval, 'Low views means eBay is not surfacing the listing. The first fix is search relevance, not price.', ['AI rewrite title using exact buyer keywords', 'Add missing item specifics', 'Check category fit', 'Avoid changing price until exposure improves']);
  }
  if (clicksKnown && performance.views >= 50 && ctr != null && ctr < 0.015) {
    return recommendation(performance.listingId, 'change_image_title', 'warning', mode, requiresApproval, 'People see the listing but do not click. Improve the first image and title promise before changing the description.', ['Choose brighter product-focused main image', 'Generate a non-misleading click-focused image concept', 'Rewrite title opening 45 characters', 'Keep car-parts images factual with no lifestyle embellishment']);
  }
  if (clicksKnown && performance.clicks! >= 12 && performance.sales === 0 && conversionRate === 0) {
    return recommendation(performance.listingId, 'reduce_price', 'warning', mode, requiresApproval, 'Clicks without sales means the offer is not convincing after the buyer opens it.', ['Improve description above the fold', 'Add trust details and package contents', 'Reduce price 2-5% if still profitable', 'Check shipping promise and returns']);
  }
  if (performance.sales === 0 && performance.daysLive >= 45 && performance.views < 40) {
    return recommendation(performance.listingId, 'rewrite_relist', 'critical', mode, true, 'After 45 days with poor exposure, the listing needs a new market angle or should be ended.', ['Research comparables again', 'Change category if confidence is weak', 'Rewrite title and specifics', 'Relist only if duplicate and margin checks pass']);
  }
  if (performance.sales === 0 && performance.daysLive >= 30) {
    return recommendation(performance.listingId, 'rewrite_relist', 'warning', mode, requiresApproval, 'After 30 days with no sales, do a full creative and pricing refresh.', ['Rewrite title', 'Change first image', 'Strengthen description', 'Reduce price 3-5% if market supports it']);
  }
  if (performance.sales === 0 && performance.daysLive >= 14) {
    return recommendation(performance.listingId, 'reduce_price', 'warning', mode, requiresApproval, 'Two weeks with no sales is a controlled price-test point, but never go below break-even.', ['Reduce price 2-3%', 'Keep margin above rule minimum', 'Schedule another check in 7 days']);
  }
  if (performance.sales >= 3 && performance.daysLive <= 7) {
    return recommendation(performance.listingId, 'increase_price', 'info', mode, requiresApproval, 'Fast early sales usually mean the price is too low or the product is hot.', ['Increase price 2-4%', 'Keep promoted ad rate stable', 'Watch conversion for 72 hours']);
  }

  return recommendation(performance.listingId, 'none', 'info', mode, false, 'No optimization action needed yet. Keep collecting impressions, views, clicks, sales, stock, and competitor price data.', []);
}

function recommendation(
  listingId: string,
  action: OptimizationRecommendation['action'],
  severity: OptimizationRecommendation['severity'],
  mode: AutomationMode,
  requiresApproval: boolean,
  reason: string,
  preview: string[]
): OptimizationRecommendation {
  return { listingId, action, severity, mode, requiresApproval, reason, preview };
}
