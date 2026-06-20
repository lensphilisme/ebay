import { getConfig } from '@/backend/config/env';
import { EbayOAuthClient } from '@/backend/integrations/ebay/oauth';
import { httpRequest, isHttpError } from '@/backend/utils/http';
import { appendFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
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

  async updateOffer(offerId: string, offer: Record<string, unknown>): Promise<unknown> {
    return this.request('PUT', `/sell/inventory/v1/offer/${encodeURIComponent(offerId)}`, { body: offer });
  }

  async publishOffer(offerId: string): Promise<unknown> {
    return this.request('POST', `/sell/inventory/v1/offer/${encodeURIComponent(offerId)}/publish`);
  }

  async bulkPublishOffers(offerIds: string[]): Promise<unknown> {
    return this.request('POST', '/sell/inventory/v1/bulk_publish_offer', {
      body: { requests: offerIds.slice(0, 25).map((offerId) => ({ offerId })) },
    });
  }

  async createOrReplaceInventoryItemGroup(groupKey: string, group: Record<string, unknown>): Promise<unknown> {
    return this.request('PUT', `/sell/inventory/v1/inventory_item_group/${encodeURIComponent(groupKey)}`, { body: group });
  }

  async publishOfferByInventoryItemGroup(groupKey: string): Promise<unknown> {
    return this.request('POST', '/sell/inventory/v1/offer/publish_by_inventory_item_group', {
      body: { inventoryItemGroupKey: groupKey },
    });
  }

  async getSellerPolicies(): Promise<{ fulfillmentPolicyId?: string; paymentPolicyId?: string; returnPolicyId?: string; warnings: string[] }> {
    const marketplaceId = getConfig().ebay.marketplaceId;
    const warnings: string[] = [];
    const [fulfillment, payment, returns] = await Promise.all([
      this.request<{ fulfillmentPolicies?: Array<Record<string, unknown>> }>('GET', '/sell/account/v1/fulfillment_policy', { params: { marketplace_id: marketplaceId } }).catch((error) => {
        warnings.push(`Fulfillment policies unavailable: ${error instanceof Error ? error.message : 'unknown error'}`);
        return {};
      }),
      this.request<{ paymentPolicies?: Array<Record<string, unknown>> }>('GET', '/sell/account/v1/payment_policy', { params: { marketplace_id: marketplaceId } }).catch((error) => {
        warnings.push(`Payment policies unavailable: ${error instanceof Error ? error.message : 'unknown error'}`);
        return {};
      }),
      this.request<{ returnPolicies?: Array<Record<string, unknown>> }>('GET', '/sell/account/v1/return_policy', { params: { marketplace_id: marketplaceId } }).catch((error) => {
        warnings.push(`Return policies unavailable: ${error instanceof Error ? error.message : 'unknown error'}`);
        return {};
      }),
    ]);
    return {
      fulfillmentPolicyId: firstPolicyId(fulfillment.fulfillmentPolicies, 'fulfillmentPolicyId'),
      paymentPolicyId: firstPolicyId(payment.paymentPolicies, 'paymentPolicyId'),
      returnPolicyId: firstPolicyId(returns.returnPolicies, 'returnPolicyId'),
      warnings,
    };
  }

  async createOrUpdateOfferForSku(sku: string, offer: Record<string, unknown>): Promise<{ offerId?: string; action: 'created' | 'updated'; raw: unknown }> {
    const existing = (await this.getOffersForSku(sku)).find((entry) => String(entry.marketplaceId ?? '') === getConfig().ebay.marketplaceId);
    if (existing?.offerId) {
      const offerId = String(existing.offerId);
      const raw = await this.updateOffer(offerId, { ...offer, offerId });
      return { offerId, action: 'updated', raw };
    }
    const raw = await this.createOffer(offer);
    const offerId = raw && typeof raw === 'object' ? String((raw as Record<string, unknown>).offerId ?? '') : '';
    return { offerId: offerId || undefined, action: 'created', raw };
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

    const inventoryListings = snapshots.filter((listing) => listing.status === 'PUBLISHED' || listing.status === 'ACTIVE' || listing.listingId);
    if (inventoryListings.length > 0) return inventoryListings;

    return this.getTradingActiveListings(limit);
  }

  async getTradingActiveListings(limit = 25): Promise<EbayListingSnapshot[]> {
    const pageSize = Math.min(Math.max(limit, 1), 100);
    const target = Math.max(limit, 1);
    const listings: EbayListingSnapshot[] = [];
    let page = 1;
    let totalPages = 1;

    while (listings.length < target && page <= totalPages) {
    const xml = `<?xml version="1.0" encoding="utf-8"?>
<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ActiveList>
    <Sort>StartTime</Sort>
    <Pagination>
      <EntriesPerPage>${pageSize}</EntriesPerPage>
      <PageNumber>${page}</PageNumber>
    </Pagination>
  </ActiveList>
</GetMyeBaySellingRequest>`;
    const response = await this.tradingRequest('GetMyeBaySelling', xml);
    const ack = firstTag(response, 'Ack');
    if (ack === 'Failure') throw new Error(`Trading API GetMyeBaySelling failed: ${allTags(response, 'LongMessage').join(' ') || allTags(response, 'ShortMessage').join(' ')}`);
      totalPages = Number(firstTag(response, 'TotalNumberOfPages') || totalPages || 1);

      listings.push(...allBlocks(response, 'Item').map((item) => ({
      listingId: firstTag(item, 'ItemID'),
      sku: optionalTag(item, 'SKU'),
      title: decodeXml(firstTag(item, 'Title')),
      status: firstTag(item, 'ListingStatus') || 'Active',
      price: Number(firstTag(item, 'CurrentPrice') || firstTag(item, 'StartPrice') || 0),
      quantityAvailable: Math.max(0, Number(firstTag(item, 'Quantity') || 0) - Number(firstTag(item, 'QuantitySold') || 0)),
      quantitySold: Number(firstTag(item, 'QuantitySold') || 0),
      watchers: Number(firstTag(item, 'WatchCount') || 0),
      imageUrl: optionalTag(item, 'PictureURL'),
      viewUrl: optionalTag(item, 'ViewItemURL'),
      marketplaceId: getConfig().ebay.marketplaceId,
      createdAt: optionalTag(item, 'StartTime'),
      raw: {
        source: 'trading_api',
        itemId: firstTag(item, 'ItemID'),
        viewUrl: optionalTag(item, 'ViewItemURL'),
        image: optionalTag(item, 'PictureURL'),
      },
      })));
      page += 1;
    }

    return listings.filter((listing) => listing.listingId).slice(0, target);
  }

  async getListingImage(listingId: string): Promise<string | undefined> {
    const xml = `<?xml version="1.0" encoding="utf-8"?>
<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ItemID>${escapeXml(listingId)}</ItemID>
  <DetailLevel>ReturnAll</DetailLevel>
</GetItemRequest>`;
    const response = await this.tradingRequest('GetItem', xml);
    const ack = firstTag(response, 'Ack');
    if (ack === 'Failure') return undefined;
    return optionalTag(response, 'PictureURL') ?? optionalTag(response, 'ExternalPictureURL');
  }

  async reviseInventoryQuantity(args: { listingId: string; sku?: string; quantity: number }): Promise<unknown> {
    const skuXml = args.sku ? `<SKU>${escapeXml(args.sku)}</SKU>` : '';
    const xml = `<?xml version="1.0" encoding="utf-8"?>
<ReviseInventoryStatusRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <InventoryStatus>
    <ItemID>${escapeXml(args.listingId)}</ItemID>
    ${skuXml}
    <Quantity>${Math.max(0, Math.floor(args.quantity))}</Quantity>
  </InventoryStatus>
</ReviseInventoryStatusRequest>`;
    const response = await this.tradingRequest('ReviseInventoryStatus', xml);
    const ack = firstTag(response, 'Ack');
    if (ack === 'Failure') throw new Error(`Trading API ReviseInventoryStatus failed: ${allTags(response, 'LongMessage').join(' ') || allTags(response, 'ShortMessage').join(' ')}`);
    return { ack, warnings: allTags(response, 'LongMessage'), listingId: args.listingId, sku: args.sku, quantity: args.quantity };
  }

  async getCategorySuggestions(query: string): Promise<Array<{ categoryId: string; categoryName: string; categoryTreeNodeLevel?: number; raw: unknown }>> {
    const data = await this.request<{ categorySuggestions?: Array<Record<string, unknown>> }>(
      'GET',
      '/commerce/taxonomy/v1/category_tree/0/get_category_suggestions',
      { params: { q: query.slice(0, 350) } }
    );
    return (data.categorySuggestions ?? []).map((entry) => {
      const category = (entry.category ?? {}) as Record<string, unknown>;
      return {
        categoryId: String(category.categoryId ?? ''),
        categoryName: String(category.categoryName ?? ''),
        categoryTreeNodeLevel: Number(entry.categoryTreeNodeLevel ?? 0) || undefined,
        raw: entry,
      };
    }).filter((entry) => entry.categoryId);
  }

  async getItemAspectsForCategory(categoryId: string): Promise<Array<{ name: string; required: boolean; values: string[]; variationEnabled: boolean }>> {
    const data = await this.request<{ aspects?: Array<Record<string, unknown>> }>(
      'GET',
      '/commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category',
      { params: { category_id: categoryId } }
    );
    return (data.aspects ?? []).map((aspect) => {
      const constraint = (aspect.aspectConstraint ?? {}) as Record<string, unknown>;
      const values = Array.isArray(aspect.aspectValues) ? aspect.aspectValues as Array<Record<string, unknown>> : [];
      return {
        name: String(aspect.localizedAspectName ?? ''),
        required: Boolean(constraint.aspectRequired),
        values: values.map((value) => String(value.localizedValue ?? '')).filter(Boolean),
        variationEnabled: constraint.aspectEnabledForVariations !== false,
      };
    }).filter((aspect) => aspect.name);
  }

  async verifyAddFixedPriceItem(requestXml: string): Promise<string> {
    return this.tradingRequest('VerifyAddFixedPriceItem', requestXml);
  }

  async addFixedPriceItem(requestXml: string): Promise<string> {
    return this.tradingRequest('AddFixedPriceItem', requestXml);
  }

  async endFixedPriceItem(itemId: string, reason: 'NotAvailable' | 'Incorrect' | 'LostOrBroken' | 'OtherListingError' | 'SellToHighBidder' = 'NotAvailable'): Promise<string> {
    const xml = `<?xml version="1.0" encoding="utf-8"?>
<EndFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ItemID>${escapeXml(itemId)}</ItemID>
  <EndingReason>${reason}</EndingReason>
</EndFixedPriceItemRequest>`;
    return this.tradingRequest('EndFixedPriceItem', xml);
  }

  private async tradingRequest(callName: string, xml: string): Promise<string> {
    const config = getConfig().ebay;
    const token = await this.auth.getAccessToken();
    const endpoint = config.environment === 'sandbox' ? 'https://api.sandbox.ebay.com/ws/api.dll' : 'https://api.ebay.com/ws/api.dll';
    const response = await httpRequest<string>({
      method: 'POST',
      url: endpoint,
      body: xml,
      headers: {
        'Content-Type': 'text/xml',
        'X-EBAY-API-CALL-NAME': callName,
        'X-EBAY-API-SITEID': '0',
        'X-EBAY-API-COMPATIBILITY-LEVEL': '1271',
        'X-EBAY-API-IAF-TOKEN': token,
        'X-EBAY-API-DEV-NAME': config.devId,
        'X-EBAY-API-APP-NAME': config.clientId,
        'X-EBAY-API-CERT-NAME': config.clientSecret,
      },
      timeoutMs: 30000,
    });
    writeTradingLog(callName, xml, response.data, response.status);
    return response.data;
  }

  async getTrafficReport(listingIds: string[], days = 30): Promise<ListingTrafficSnapshot[]> {
    const config = getConfig().ebay;
    const end = new Date();
    end.setUTCDate(end.getUTCDate() - 1);
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - Math.max(days - 1, 0));
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
      const impressions = Number(values?.[0]?.value ?? 0);
      const clickThroughRate = Number(values?.[2]?.value ?? 0);
      return {
        listingId: String(dimensions?.[0]?.value ?? ''),
        impressions,
        views: Number(values?.[1]?.value ?? 0),
        clickThroughRate,
        salesConversionRate: Number(values?.[3]?.value ?? 0),
        transactions: Number(values?.[4]?.value ?? 0),
        clicksEstimated: estimateClicksFromCtr(impressions, clickThroughRate),
        raw: record,
      };
    });
  }
}

function firstPolicyId(rows: Array<Record<string, unknown>> | undefined, key: string): string | undefined {
  const active = (rows ?? []).find((row) => row.categoryTypes || row.name || row[key]);
  const id = active?.[key];
  return typeof id === 'string' && id ? id : undefined;
}

function estimateClicksFromCtr(impressions: number, clickThroughRate: number): number | undefined {
  if (!Number.isFinite(impressions) || !Number.isFinite(clickThroughRate) || impressions <= 0 || clickThroughRate <= 0) return undefined;
  return Math.round(impressions * (clickThroughRate > 1 ? clickThroughRate / 100 : clickThroughRate));
}

function allBlocks(xml: string, tag: string): string[] {
  return [...xml.matchAll(new RegExp(`<${tag}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${tag}>`, 'g'))].map((match) => match[1] ?? '');
}

function allTags(xml: string, tag: string): string[] {
  return allBlocks(xml, tag).map(decodeXml);
}

function firstTag(xml: string, tag: string): string {
  return allTags(xml, tag)[0] ?? '';
}

function optionalTag(xml: string, tag: string): string | undefined {
  return firstTag(xml, tag) || undefined;
}

function decodeXml(value: string): string {
  return value
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
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

function writeTradingLog(callName: string, requestXml: string, responseXml: string, httpStatus: number): void {
  const logPath = 'database/logs/ebay-trading.log';
  const ack = firstTag(responseXml, 'Ack');
  const warnings = allBlocks(responseXml, 'Errors')
    .filter((block) => firstTag(block, 'SeverityCode') === 'Warning')
    .map((block) => firstTag(block, 'LongMessage') || firstTag(block, 'ShortMessage'));
  const errors = allBlocks(responseXml, 'Errors')
    .filter((block) => firstTag(block, 'SeverityCode') === 'Error')
    .map((block) => firstTag(block, 'LongMessage') || firstTag(block, 'ShortMessage'));
  mkdirSync(dirname(logPath), { recursive: true });
  appendFileSync(logPath, JSON.stringify({
    time: new Date().toISOString(),
    call: callName,
    http_status: httpStatus,
    context: { ack, warnings, errors },
    request_xml: requestXml,
    response_xml: responseXml,
  }) + '\n');
}
