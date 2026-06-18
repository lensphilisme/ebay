import { getConfig } from '@/backend/config/env';
import { EbayOAuthClient } from '@/backend/integrations/ebay/oauth';
import { httpRequest, isHttpError } from '@/backend/utils/http';
import type { EbayComparableListing, EbayListingSnapshot, IntegrationHealth, ListingTrafficSnapshot } from '@/shared/types';

class RateLimitTracker {
  private timestamps: number[] = [];
  private readonly windowMs = 60000;
  private readonly maxRequests = 4500;

  record(): void {
    const now = Date.now();
    this.timestamps = this.timestamps.filter((stamp) => now - stamp < this.windowMs);
    if (this.timestamps.length >= this.maxRequests) throw new Error('Local eBay rate-limit guard blocked the request.');
    this.timestamps.push(now);
  }
}

export class EbayClient {
  private readonly auth = new EbayOAuthClient();
  private readonly rateLimit = new RateLimitTracker();

  private async request<T>(method: string, endpoint: string, options: { params?: Record<string, unknown>; body?: unknown; headers?: Record<string, string> } = {}): Promise<T> {
    const config = getConfig().ebay;
    this.rateLimit.record();
    const token = await this.auth.getAccessToken();

    try {
      const response = await httpRequest<T>({
        method,
        url: `${config.apiBaseUrl}${endpoint}`,
        params: options.params,
        body: options.body,
        headers: {
          Accept: 'application/json',
          'Accept-Language': 'en-US',
          'Content-Type': 'application/json',
          'X-EBAY-C-MARKETPLACE-ID': config.marketplaceId,
          Authorization: `Bearer ${token}`,
          ...options.headers,
        },
        timeoutMs: 30000,
      });
      return response.data;
    } catch (error) {
      if (isHttpError(error) && error.status === 429) {
        throw new Error(`eBay API rate limit exceeded. Retry after ${error.headers['retry-after'] ?? '60'} seconds.`);
      }
      if (isHttpError(error)) {
        throw new Error(`eBay API ${error.status ?? 'network'} error for ${endpoint}: ${describeEbayError(error.data)}`);
      }
      throw error;
    }
  }

  async healthCheck(): Promise<IntegrationHealth> {
    try {
      const config = getConfig().ebay;
      if (!config.clientId || !config.clientSecret) {
        throw new Error('Missing EBAY_CLIENT_ID or EBAY_CLIENT_SECRET.');
      }
      await this.request('GET', '/sell/account/v1/privilege');
      return {
        provider: 'ebay',
        status: 'connected',
        environment: getConfig().ebay.environment,
        checkedAt: new Date().toISOString(),
        message: 'eBay credentials can read seller privileges.',
        canRead: true,
        canWrite: Boolean(getConfig().ebay.userAccessToken || getConfig().ebay.userRefreshToken),
      };
    } catch (error) {
      return {
        provider: 'ebay',
        status: 'error',
        environment: getConfig().ebay.environment,
        checkedAt: new Date().toISOString(),
        message: error instanceof Error ? error.message : 'eBay health check failed.',
        canRead: false,
        canWrite: false,
      };
    }
  }

  async searchComparableListings(query: string, limit = 15): Promise<EbayComparableListing[]> {
    const data = await this.request<{ itemSummaries?: Array<Record<string, unknown>> }>('GET', '/buy/browse/v1/item_summary/search', {
      params: { q: query, limit: Math.min(limit, 15) },
    });

    return (data.itemSummaries ?? []).map((item) => ({
      itemId: String(item.itemId ?? ''),
      title: String(item.title ?? ''),
      categoryId: typeof item.categoryId === 'string' ? item.categoryId : undefined,
      price: Number((item.price as { value?: string } | undefined)?.value ?? 0),
      shippingCost: Number(((item.shippingOptions as Array<{ shippingCost?: { value?: string } }> | undefined)?.[0]?.shippingCost?.value) ?? 0),
      condition: typeof item.condition === 'string' ? item.condition : undefined,
      sellerUsername: typeof (item.seller as { username?: unknown } | undefined)?.username === 'string' ? String((item.seller as { username?: unknown }).username) : undefined,
      url: typeof item.itemWebUrl === 'string' ? item.itemWebUrl : undefined,
      raw: item,
    }));
  }

  async createOrReplaceInventoryItem(sku: string, inventoryItem: Record<string, unknown>): Promise<unknown> {
    return this.request('PUT', `/sell/inventory/v1/inventory_item/${encodeURIComponent(sku)}`, { body: inventoryItem });
  }

  async createOffer(offer: Record<string, unknown>): Promise<unknown> {
    return this.request('POST', '/sell/inventory/v1/offer', { body: offer });
  }

  async publishOffer(offerId: string): Promise<unknown> {
    return this.request('POST', `/sell/inventory/v1/offer/${encodeURIComponent(offerId)}/publish`);
  }

  getAuthorizationUrl(): string {
    return this.auth.getAuthorizationUrl(crypto.randomUUID());
  }

  async getInventoryItems(limit = 25): Promise<Array<Record<string, unknown>>> {
    const data = await this.request<{ inventoryItems?: Array<Record<string, unknown>> }>('GET', '/sell/inventory/v1/inventory_item', {
      params: { limit: String(Math.min(limit, 100)), offset: '0' },
    });
    return data.inventoryItems ?? [];
  }

  async getOffersForSku(sku: string): Promise<Array<Record<string, unknown>>> {
    const config = getConfig().ebay;
    const data = await this.request<{ offers?: Array<Record<string, unknown>> }>('GET', '/sell/inventory/v1/offer', {
      params: { sku, marketplace_id: config.marketplaceId, limit: '100', offset: '0' },
    });
    return data.offers ?? [];
  }

  async getActiveListings(limit = 25): Promise<EbayListingSnapshot[]> {
    const inventoryItems = await this.getInventoryItems(limit);
    const snapshots: EbayListingSnapshot[] = [];

    for (const item of inventoryItems) {
      const sku = String(item.sku ?? '');
      if (!sku) continue;
      const offers = await this.getOffersForSku(sku);
      for (const offer of offers) {
        const listing = offer.listing as Record<string, unknown> | undefined;
        const price = offer.pricingSummary as { price?: { value?: string } } | undefined;
        const product = item.product as Record<string, unknown> | undefined;
        const listingId = String(listing?.listingId ?? '');
        if (!listingId) continue;
        snapshots.push({
          listingId,
          offerId: typeof offer.offerId === 'string' ? offer.offerId : undefined,
          sku,
          title: String(product?.title ?? offer.listingDescription ?? sku),
          status: String(offer.status ?? listing?.listingStatus ?? 'UNKNOWN'),
          price: Number(price?.price?.value ?? 0),
          quantityAvailable: Number((offer.availableQuantity as number | undefined) ?? 0),
          quantitySold: Number((listing?.soldQuantity as number | undefined) ?? 0),
          marketplaceId: typeof offer.marketplaceId === 'string' ? offer.marketplaceId : undefined,
          raw: { inventoryItem: item, offer },
        });
      }
    }

    return snapshots.filter((listing) => listing.status === 'PUBLISHED' || listing.status === 'ACTIVE' || listing.listingId);
  }

  async getTrafficReport(listingIds: string[], days = 30): Promise<ListingTrafficSnapshot[]> {
    const config = getConfig().ebay;
    const end = new Date();
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - days);
    const formatDate = (date: Date) => date.toISOString().slice(0, 10).replace(/-/g, '');
    const filters = [
      listingIds.length > 0 ? `listing_ids:{${listingIds.join('|')}}` : undefined,
      `date_range:[${formatDate(start)}..${formatDate(end)}]`,
      `marketplace_ids:{${config.marketplaceId}}`,
    ].filter(Boolean);
    const filter = filters.join(',');
    const metric = [
      'TOTAL_IMPRESSION_TOTAL',
      'LISTING_VIEWS_TOTAL',
      'CLICK_THROUGH_RATE',
      'SALES_CONVERSION_RATE',
      'TRANSACTION',
    ].join(',');
    const data = await this.request<Record<string, unknown>>('GET', '/sell/analytics/v1/traffic_report', {
      params: {
        dimension: 'LISTING',
        filter,
        metric,
        sort: 'TOTAL_IMPRESSION_TOTAL',
      },
    });

    const records = Array.isArray(data.records) ? (data.records as Array<Record<string, unknown>>) : [];
    return records.map((record) => {
      const dimensions = record.dimensionValues as Array<{ value?: string }> | undefined;
      const values = record.metricValues as Array<{ value?: string | number }> | undefined;
      return {
        listingId: String(dimensions?.[0]?.value ?? ''),
        impressions: Number(values?.[0]?.value ?? 0),
        views: Number(values?.[1]?.value ?? 0),
        clickThroughRate: Number(values?.[2]?.value ?? 0),
        salesConversionRate: Number(values?.[3]?.value ?? 0),
        transactions: Number(values?.[4]?.value ?? 0),
        raw: record,
      };
    });
  }
}

function describeEbayError(data: unknown): string {
  if (!data) return 'No response body.';
  if (typeof data === 'string') return data.slice(0, 500);
  if (typeof data === 'object') {
    const record = data as Record<string, unknown>;
    if (typeof record.error_description === 'string') return record.error_description;
    if (typeof record.error === 'string') return record.error;
    const errors = Array.isArray(record.errors) ? record.errors : [];
    const first = errors[0] as Record<string, unknown> | undefined;
    if (first) {
      return String(first.longMessage ?? first.message ?? JSON.stringify(first));
    }
    return JSON.stringify(record).slice(0, 500);
  }
  return String(data);
}
