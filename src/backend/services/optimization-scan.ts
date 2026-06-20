import { EbayClient } from '@/backend/integrations/ebay/client';
import { recommendOptimization } from '@/backend/services/optimizer';
import type { AutomationMode, EbayListingSnapshot, ListingPerformance, ListingTrafficSnapshot, OptimizationScanResult } from '@/shared/types';

export async function scanEbayListingsForOptimization(options: {
  ebay: EbayClient;
  days?: number;
  limit?: number;
  mode?: AutomationMode;
}): Promise<OptimizationScanResult> {
  const days = options.days ?? 30;
  const limit = options.limit ?? 25;
  const mode = options.mode ?? 'approval';
  const warnings: string[] = [];

  let listings: EbayListingSnapshot[] = [];
  try {
    listings = await withTimeout(options.ebay.getActiveListings(limit), 60000, 'Timed out while fetching eBay inventory/offers.');
    const imageTargets = listings.filter((listing) => !listing.imageUrl).slice(0, 60);
    await Promise.all(imageTargets.map(async (listing) => {
      const image = await withTimeout(options.ebay.getListingImage(listing.listingId), 12000, 'Timed out while fetching listing image.').catch(() => undefined);
      if (image) listing.imageUrl = image;
    }));
  } catch (error) {
    warnings.push(error instanceof Error ? error.message : 'Could not fetch eBay inventory/offers.');
  }

  const listingIds = listings.map((listing) => listing.listingId).filter(Boolean);
  let traffic: ListingTrafficSnapshot[] = [];

  if (listingIds.length === 0) {
    warnings.push('No active eBay listing IDs were returned by the Inventory API. Create/publish listings through Inventory API or add Trading API sync for legacy listings.');
  } else {
    try {
      for (const batch of chunks(listingIds, 200)) {
        const rows = await withTimeout(options.ebay.getTrafficReport(batch, days), 45000, 'Timed out while fetching eBay Analytics traffic report.');
        traffic.push(...rows);
      }
    } catch (error) {
      warnings.push(`Traffic report unavailable: ${error instanceof Error ? error.message : 'unknown error'}`);
    }
  }

  const recommendations = listings.map((listing) => {
    const trafficRow = traffic.find((row) => row.listingId === listing.listingId);
    const lifetimeSold = listing.quantitySold;
    const windowTransactions = trafficRow?.transactions;
    const performance: ListingPerformance = {
      listingId: listing.listingId,
      title: listing.title,
      daysLive: days,
      impressions: trafficRow?.impressions,
      views: trafficRow?.views ?? 0,
      clicks: undefined,
      clicksEstimated: trafficRow?.clicksEstimated,
      clickDataAvailable: false,
      watchers: listing.watchers ?? 0,
      sales: Math.max(windowTransactions ?? 0, lifetimeSold),
      trafficTransactions: windowTransactions,
      lifetimeSold,
      trafficAvailable: Boolean(trafficRow),
      currentPrice: listing.price,
      landedCost: 0,
      cjStock: listing.quantityAvailable,
      cjCostChanged: false,
      competitorPriceDropped: false,
    };

    return {
      listing,
      performance,
      recommendation: recommendOptimization(performance, mode),
    };
  });

  return {
    scannedAt: new Date().toISOString(),
    windowDays: days,
    listings,
    traffic,
    recommendations,
    warnings,
  };
}

function chunks<T>(items: T[], size: number): T[][] {
  const result: T[][] = [];
  for (let index = 0; index < items.length; index += size) result.push(items.slice(index, index + size));
  return result;
}

async function withTimeout<T>(promise: Promise<T>, timeoutMs: number, message: string): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      promise,
      new Promise<T>((_, reject) => {
        timer = setTimeout(() => reject(new Error(message)), timeoutMs);
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}
