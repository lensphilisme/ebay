import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { existsSync, createReadStream } from 'node:fs';
import { extname, join, resolve } from 'node:path';
import { getConfig } from '@/backend/config/env';
import { automationStore } from '@/backend/database/store';
import { CjClient } from '@/backend/integrations/cj/client';
import { EbayClient } from '@/backend/integrations/ebay/client';
import { compareMarketListings } from '@/backend/services/market-research';
import { defaultAutomationRules, canPublishDraft } from '@/backend/services/rules-engine';
import { recommendOptimization } from '@/backend/services/optimizer';
import { buildListingDraft } from '@/backend/services/listing-builder';
import { scanEbayListingsForOptimization } from '@/backend/services/optimization-scan';
import { createAIProvider } from '@/backend/services/ai/provider';
import { CjEbayListingEngine } from '@/backend/services/ebay-listing-engine/engine';
import { createMarketplaceExport, type MarketplaceExportFormat, type MarketplaceExportTarget } from '@/backend/services/marketplace-exporter';
import type { CjProduct, CjVariant, FreightQuote } from '@/shared/types';

const cj = new CjClient();
const ebay = new EbayClient();
const listingEngine = new CjEbayListingEngine({ cj, ebay });
const staticRoot = resolve(process.cwd(), 'dist');

async function readJson(req: IncomingMessage): Promise<Record<string, unknown>> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) chunks.push(Buffer.from(chunk));
  const raw = Buffer.concat(chunks).toString('utf8');
  return raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
}

function send(res: ServerResponse, status: number, data: unknown): void {
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
  });
  res.end(JSON.stringify(data, null, 2));
}

function routePath(pathname: string): string {
  return pathname.startsWith('/api/') ? pathname.slice(4) : pathname;
}

function sendStatic(res: ServerResponse, pathname: string): boolean {
  if (!existsSync(staticRoot)) return false;
  const safePath = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
  const filePath = resolve(staticRoot, safePath);
  if (!filePath.startsWith(staticRoot)) return false;
  const finalPath = existsSync(filePath) ? filePath : join(staticRoot, 'index.html');
  if (!existsSync(finalPath)) return false;

  const contentType = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
  }[extname(finalPath)] ?? 'application/octet-stream';

  res.writeHead(200, { 'Content-Type': contentType });
  createReadStream(finalPath).pipe(res);
  return true;
}

function envStatus() {
  const config = getConfig();
  return {
    ebay: {
      envKeys: ['EBAY_CLIENT_ID', 'EBAY_CLIENT_SECRET', 'EBAY_DEV_ID', 'EBAY_RUNAME', 'EBAY_USER_REFRESH_TOKEN', 'EBAY_USER_ACCESS_TOKEN or EBAY_USER_TOKEN', 'EBAY_APP_ACCESS_TOKEN or EBAY_APP_TOKEN', 'EBAY_WEBHOOK_ENDPOINT'],
      environment: config.ebay.environment,
      marketplaceId: config.ebay.marketplaceId,
      hasClientId: Boolean(config.ebay.clientId),
      hasClientSecret: Boolean(config.ebay.clientSecret),
      hasDevId: Boolean(config.ebay.devId),
      hasRuname: Boolean(config.ebay.ruName),
      hasUserAccessToken: Boolean(config.ebay.userAccessToken),
      hasUserRefreshToken: Boolean(config.ebay.userRefreshToken),
      hasAppToken: Boolean(config.ebay.appToken),
      hasWebhookEndpoint: Boolean(process.env.EBAY_WEBHOOK_ENDPOINT),
    },
    cj: {
      envKeys: ['CJ_ACCESS_TOKEN', 'CJ_API_KEY', 'CJ_EMAIL', 'CJ_PASSWORD', 'CJ_OPEN_API_BASE'],
      hasAccessToken: Boolean(config.cj.accessToken),
      hasApiKey: Boolean(config.cj.apiKey),
      hasEmail: Boolean(config.cj.email),
      openApiBase: config.cj.openApiBase,
    },
    ai: {
      envKeys: ['AI_PROVIDER', 'AI_API_KEY', 'AI_MODEL'],
      provider: config.ai.provider,
      hasApiKey: Boolean(config.ai.apiKey),
      model: config.ai.model,
    },
  };
}

function numberFrom(value: unknown, fallback = 0): number {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function imageUrlsFromQueueItem(item: Record<string, unknown>): string[] {
  const urls = new Set<string>();
  if (typeof item.image === 'string' && item.image) urls.add(item.image);
  const raw = item.raw && typeof item.raw === 'object' ? item.raw as Record<string, unknown> : {};
  for (const value of Object.values(raw)) {
    if (typeof value === 'string' && /^https?:\/\/.+\.(jpg|jpeg|png|webp)(\?|$)/i.test(value)) urls.add(value);
    if (Array.isArray(value)) {
      value.filter((entry): entry is string => typeof entry === 'string' && /^https?:\/\//i.test(entry)).forEach((url) => urls.add(url));
    }
  }
  return [...urls].slice(0, 20);
}

function queueItemToBuilderInput(item: Record<string, unknown>, activeListings: Awaited<ReturnType<EbayClient['getActiveListings']>>) {
  const raw = item.raw && typeof item.raw === 'object' ? item.raw as Record<string, unknown> : {};
  const productId = String(item.productId ?? item.id ?? raw.id ?? raw.pid ?? raw.productId ?? '');
  const variantId = item.variantId ? String(item.variantId) : undefined;
  const productCost = numberFrom(item.productCost ?? raw.nowPrice ?? raw.sellPrice ?? raw.price);
  const shippingCost = numberFrom(item.shippingCost);
  const product: CjProduct = {
    id: productId,
    title: String(item.title ?? raw.nameEn ?? raw.productNameEn ?? raw.productName ?? 'CJ product'),
    categoryId: item.categoryId ? String(item.categoryId) : raw.categoryId ? String(raw.categoryId) : undefined,
    price: productCost,
    weight: item.weight != null ? numberFrom(item.weight) : undefined,
    imageUrls: imageUrlsFromQueueItem(item),
    raw,
  };
  const variant: CjVariant | undefined = variantId ? {
    id: variantId,
    productId,
    sku: item.sku ? String(item.sku) : undefined,
    price: productCost,
    inventory: numberFrom(item.inventory, 1),
    weight: item.weight != null ? numberFrom(item.weight) : undefined,
    attributes: item.variant ? { Variant: String(item.variant) } : {},
    raw: { variantName: item.variant },
  } : undefined;
  const freight: FreightQuote = {
    productId,
    variantId,
    destinationCountry: 'US',
    shippingCost,
    raw: { source: 'bulk_queue' },
  };
  return {
    product,
    variant,
    freight,
    duplicateContext: {
      existingCjProductIds: [],
      existingCjVariantIds: [],
      existingSkus: activeListings.map((listing) => listing.sku).filter((sku): sku is string => Boolean(sku)),
      existingTitles: activeListings.map((listing) => listing.title),
      existingImageUrls: activeListings.map((listing) => listing.imageUrl).filter((url): url is string => Boolean(url)),
    },
  };
}

function queueProductId(item: Record<string, unknown>): string {
  const raw = item.raw && typeof item.raw === 'object' ? item.raw as Record<string, unknown> : {};
  return String(item.productId ?? item.pid ?? item.id ?? raw.pid ?? raw.productId ?? raw.id ?? '').split(':')[0];
}

function exportTarget(value: unknown): MarketplaceExportTarget | undefined {
  return value === 'ebay' || value === 'facebook' || value === 'tiktok' ? value : undefined;
}

function exportFormat(value: unknown): MarketplaceExportFormat {
  return value === 'xls' || value === 'xlsx' ? value : 'csv';
}

async function route(req: IncomingMessage, res: ServerResponse): Promise<void> {
  if (req.method === 'OPTIONS') return send(res, 204, {});
  const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);
  const pathname = routePath(url.pathname);

  try {
    if (req.method === 'GET' && pathname === '/health') {
      return send(res, 200, { ok: true, service: 'cj-ebay-automation', singleUrl: existsSync(staticRoot), time: new Date().toISOString() });
    }

    if (req.method === 'GET' && pathname === '/settings/status') {
      return send(res, 200, envStatus());
    }

    if (req.method === 'GET' && pathname === '/integrations/health') {
      const [cjHealth, ebayHealth] = await Promise.all([cj.healthCheck(), ebay.healthCheck()]);
      automationStore.addJobLog({ jobName: 'integration_health_check', status: 'succeeded', message: 'Checked CJ and eBay integrations.', metadata: {} });
      return send(res, 200, { integrations: [cjHealth, ebayHealth] });
    }

    if (req.method === 'GET' && pathname === '/ebay/oauth/start') {
      return send(res, 200, { url: ebay.getAuthorizationUrl() });
    }

    if (req.method === 'GET' && pathname === '/cj/categories') {
      const parentId = url.searchParams.get('parentId') ?? undefined;
      return send(res, 200, await cj.getCategoryTree(parentId));
    }

    if (req.method === 'GET' && pathname === '/cj/warehouses') {
      return send(res, 200, await cj.getWarehouses());
    }

    if (req.method === 'GET' && pathname === '/cj/product') {
      const detailPromise = cj.getProductDetail({
        pid: url.searchParams.get('pid') ?? undefined,
        productSku: url.searchParams.get('productSku') ?? undefined,
        variantSku: url.searchParams.get('variantSku') ?? undefined,
        countryCode: url.searchParams.get('countryCode') ?? undefined,
      });
      return send(res, 200, await timeoutValue(detailPromise, 12000, { warning: 'CJ product detail did not respond quickly. Showing search-result data only.' }));
    }

    if (req.method === 'POST' && pathname === '/cj/search') {
      const filters = await readJson(req);
      const data = await cj.searchProducts(filters);
      automationStore.addAuditLog({ actor: 'family_user', action: 'cj.search_products', targetType: 'cj_product', reason: 'Manual CJ product discovery search.', after: filters });
      return send(res, 200, data);
    }

    if (req.method === 'POST' && pathname === '/cj/export-marketplace') {
      const body = await readJson(req);
      const target = exportTarget(body.marketplace);
      if (!target) return send(res, 400, { error: 'marketplace must be ebay, facebook, or tiktok.' });
      const items = Array.isArray(body.items) ? body.items.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')) : [];
      if (items.length === 0) return send(res, 400, { error: 'Select at least one CJ product to export.' });
      const countryCode = String(body.countryCode ?? 'US');
      const sources = await Promise.all(items.slice(0, 500).map(async (item) => {
        const productId = queueProductId(item);
        const detail = productId
          ? await timeoutValue(cj.getProductDetail({ pid: productId, countryCode }), 12000, undefined as unknown)
          : undefined;
        const listing = target === 'ebay' && productId
          ? await timeoutValue(listingEngine.listSingleCJProduct(productId, { live: false, preflight: false, countryCode }), 25000, undefined as unknown)
          : undefined;
        return { item, detail, listing };
      }));
      const result = createMarketplaceExport(target, sources, exportFormat(body.format));
      automationStore.addJobLog({
        jobName: 'cj_marketplace_export',
        status: 'succeeded',
        message: `Created ${target} marketplace export for ${result.rows} CJ products.`,
        metadata: { target, rows: result.rows, warnings: result.warnings },
      });
      return send(res, 200, result);
    }

    if (req.method === 'POST' && pathname === '/cj/freight') {
      const body = await readJson(req);
      return send(res, 200, await cj.calculateFreight(body as never));
    }

    if (req.method === 'POST' && pathname === '/ebay/market-research') {
      const body = await readJson(req);
      const title = String(body.title ?? '');
      const comparables = await ebay.searchComparableListings(title.slice(0, 75), 15);
      return send(res, 200, compareMarketListings(title, comparables));
    }

    if (req.method === 'POST' && pathname === '/drafts/build') {
      const body = await readJson(req);
      const draft = await buildListingDraft(body as never);
      const publishGuard = canPublishDraft(draft, defaultAutomationRules);
      automationStore.addAuditLog({ actor: 'automation', action: 'listing_draft.create', targetType: 'listing_draft', targetId: draft.id, reason: draft.auditReason, after: draft });
      return send(res, 200, { draft, publishGuard });
    }

    if (req.method === 'POST' && pathname === '/drafts/from-queue') {
      const body = await readJson(req);
      const item = body.item && typeof body.item === 'object' ? body.item as Record<string, unknown> : body;
      const activeListings = await timeoutValue(ebay.getActiveListings(250), 15000, []);
      const draftInput = queueItemToBuilderInput(item, activeListings);
      const title = draftInput.product.title;
      const comparables = await timeoutValue(ebay.searchComparableListings(title.slice(0, 75), 15), 12000, []);
      const marketComparison = comparables.length > 0 ? compareMarketListings(title, comparables) : undefined;
      const draft = await buildListingDraft({ ...draftInput, marketComparison });
      const publishGuard = canPublishDraft(draft, defaultAutomationRules);
      automationStore.addAuditLog({
        actor: 'automation',
        action: 'listing_draft.create_from_queue',
        targetType: 'listing_draft',
        targetId: draft.id,
        reason: draft.auditReason,
        after: { draft, publishGuard, queueItemId: item.id },
      });
      automationStore.addJobLog({
        jobName: 'queue_create_draft',
        status: 'succeeded',
        message: `Created listing draft for queued CJ product ${draft.cjProductId}.`,
        metadata: { draftId: draft.id, duplicateStatus: draft.duplicateDecision.status, publishAllowed: publishGuard.allowed },
      });
      return send(res, 200, { draft, publishGuard });
    }

    if (req.method === 'POST' && pathname === '/ebay/drafts/from-queue') {
      const body = await readJson(req);
      const item = body.item && typeof body.item === 'object' ? body.item as Record<string, unknown> : body;
      const productId = queueProductId(item);
      if (!productId) return send(res, 400, { error: 'Queued item is missing a CJ product id.' });
      const result = await listingEngine.createEbayInventoryDraft(productId, {
        countryCode: String(body.countryCode ?? 'US'),
      });
      automationStore.addJobLog({
        jobName: 'queue_create_ebay_draft',
        status: result.status === 'passed' ? 'succeeded' : 'failed',
        message: `Created eBay Inventory draft for queued CJ product ${productId}: ${result.status}.`,
        metadata: { productId, offerIds: result.offerIds, inventoryItemGroupKey: result.inventoryItemGroupKey, errors: result.errors },
      });
      return send(res, result.status === 'passed' ? 200 : 207, result);
    }

    if (req.method === 'POST' && pathname === '/ebay/drafts/bulk-from-queue') {
      const body = await readJson(req);
      const items = Array.isArray(body.items) ? body.items.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')) : [];
      const productIds = [...new Set(items.map(queueProductId).filter(Boolean))];
      if (productIds.length === 0) return send(res, 400, { error: 'Select at least one queued CJ product to draft on eBay.' });
      const result = await listingEngine.createBulkEbayInventoryDrafts({
        productIds,
        countryCode: String(body.countryCode ?? 'US'),
      });
      automationStore.addJobLog({
        jobName: 'queue_bulk_create_ebay_drafts',
        status: result.failed > 0 ? 'failed' : 'succeeded',
        message: `Bulk eBay draft creation processed ${result.total} queued CJ products; ${result.failed} failed.`,
        metadata: { productIds, passed: result.passed, failed: result.failed, skipped: result.skipped },
      });
      return send(res, result.failed > 0 ? 207 : 200, result);
    }

    if (req.method === 'POST' && pathname === '/ebay/drafts/publish-bulk') {
      const body = await readJson(req);
      const offerIds = Array.isArray(body.offerIds) ? body.offerIds.map((value) => String(value).trim()).filter(Boolean) : [];
      const inventoryItemGroupKeys = Array.isArray(body.inventoryItemGroupKeys) ? body.inventoryItemGroupKeys.map((value) => String(value).trim()).filter(Boolean) : [];
      if (offerIds.length === 0 && inventoryItemGroupKeys.length === 0) return send(res, 400, { error: 'No eBay draft offer IDs or inventory item group keys were supplied.' });
      const result = await listingEngine.publishEbayInventoryDrafts({ offerIds, inventoryItemGroupKeys });
      automationStore.addJobLog({
        jobName: 'queue_bulk_publish_ebay_drafts',
        status: result.failed > 0 ? 'failed' : 'succeeded',
        message: `Published eBay Inventory drafts; ${result.failed} publish batch(es) failed.`,
        metadata: { offerIds, inventoryItemGroupKeys, passed: result.passed, failed: result.failed },
      });
      return send(res, result.failed > 0 ? 207 : 200, result);
    }

    if (req.method === 'POST' && pathname === '/ebay/list/from-queue') {
      const body = await readJson(req);
      const item = body.item && typeof body.item === 'object' ? body.item as Record<string, unknown> : body;
      const productId = queueProductId(item);
      if (!productId) return send(res, 400, { error: 'Queued item is missing a CJ product id.' });
      const result = await listingEngine.listSingleCJProduct(productId, {
        live: true,
        preflight: true,
        countryCode: String(body.countryCode ?? 'US'),
      });
      automationStore.addJobLog({
        jobName: 'queue_list_live',
        status: result.status === 'passed' ? 'succeeded' : 'failed',
        message: `Live eBay listing attempt for queued CJ product ${productId}: ${result.status}.`,
        metadata: { productId, itemId: result.ebayAttempt.itemId, errors: result.errors },
      });
      return send(res, result.status === 'passed' ? 200 : 207, result);
    }

    if (req.method === 'POST' && pathname === '/ebay/list/bulk-from-queue') {
      const body = await readJson(req);
      const items = Array.isArray(body.items) ? body.items.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')) : [];
      const productIds = [...new Set(items.map(queueProductId).filter(Boolean))];
      if (productIds.length === 0) return send(res, 400, { error: 'Select at least one queued CJ product to list.' });
      const result = await listingEngine.listBulkCJProducts({
        productIds,
        live: true,
        preflight: true,
        countryCode: String(body.countryCode ?? 'US'),
      });
      automationStore.addJobLog({
        jobName: 'queue_bulk_list_live',
        status: result.failed > 0 ? 'failed' : 'succeeded',
        message: `Bulk live eBay listing processed ${result.total} queued CJ products; ${result.failed} failed.`,
        metadata: { productIds, passed: result.passed, failed: result.failed, skipped: result.skipped },
      });
      return send(res, result.failed > 0 ? 207 : 200, result);
    }

    if (req.method === 'POST' && pathname === '/ai/listing-suggestions') {
      const body = await readJson(req);
      const ai = createAIProvider();
      const raw = (body.raw && typeof body.raw === 'object' ? body.raw : {}) as Record<string, unknown>;
      const title = String(body.title ?? raw.productNameEn ?? raw.nameEn ?? '');
      const description = String(body.description ?? raw.description ?? '');
      const itemSpecifics = await ai.extractItemSpecifics({ title, description, raw });
      const improvedTitle = await ai.generateTitle({ sourceTitle: title, itemSpecifics, maxLength: 80 });
      const improvedDescription = await ai.generateDescription({
        title: improvedTitle,
        bullets: [
          'Clear product-focused offer built from CJ product data.',
          'Includes variant, material, size, package, and compatibility details when available.',
          'Price must include CJ product cost, CJ logistics freight, eBay fees, ad buffer, and profit.',
        ],
        itemSpecifics,
      });
      const categoryName = String(raw.categoryName ?? raw.threeCategoryName ?? '');
      const isCarPart = /car|auto|vehicle|motor|engine|ford|chevy|bmw|mercedes|wheel|tire|exhaust|headlight|brake/i.test(`${title} ${categoryName}`);
      return send(res, 200, {
        improvedTitle,
        improvedDescription,
        itemSpecifics,
        mainImageStrategy: isCarPart
          ? 'Car-part listing: keep the main image factual, clear, uncropped, and compatibility-safe. Do not generate lifestyle embellishment.'
          : 'Non-car-part listing: use a bright product-first main image, clean background, high contrast, clear scale/context, and avoid misleading edits. AI enhancement can propose a cleaner click-focused design but must not change the actual product.',
      });
    }

    if (req.method === 'POST' && pathname === '/optimizer/recommend') {
      const body = await readJson(req);
      return send(res, 200, recommendOptimization(body as never, defaultAutomationRules.automationMode));
    }

    if ((req.method === 'POST' || req.method === 'GET') && pathname === '/optimizer/scan') {
      const scanStartedAt = Date.now();
      console.log(`[optimizer_scan] start method=${req.method}`);
      const body = req.method === 'POST' ? await readJson(req) : {};
      console.log(`[optimizer_scan] body_read ms=${Date.now() - scanStartedAt}`);
      const result = await scanEbayListingsForOptimization({
        ebay,
        days: Number(body.days ?? url.searchParams.get('days') ?? 30),
        limit: Number(body.limit ?? url.searchParams.get('limit') ?? 25),
        mode: defaultAutomationRules.automationMode,
      });
      console.log(`[optimizer_scan] done ms=${Date.now() - scanStartedAt} listings=${result.listings.length}`);
      automationStore.addJobLog({
        jobName: 'optimizer_scan',
        status: 'succeeded',
        message: `Scanned ${result.listings.length} eBay listings and produced ${result.recommendations.length} recommendations.`,
        metadata: { days: result.windowDays, warnings: result.warnings },
      });
      return send(res, 200, result);
    }

    if ((req.method === 'POST' || req.method === 'GET') && pathname === '/optimizer/auto-run') {
      const body = req.method === 'POST' ? await readJson(req) : {};
      const result = await scanEbayListingsForOptimization({
        ebay,
        days: Number(body.days ?? url.searchParams.get('days') ?? 30),
        limit: Number(body.limit ?? url.searchParams.get('limit') ?? 250),
        mode: defaultAutomationRules.automationMode,
      });
      const executionAllowed = defaultAutomationRules.automationMode === 'full_auto' && !defaultAutomationRules.dryRun;
      const actions: Array<Record<string, unknown>> = [];
      for (const item of result.recommendations) {
        const quantityTopUp = item.listing.quantityAvailable < 5 ? 5 : undefined;
        const planned = {
          listingId: item.listing.listingId,
          sku: item.listing.sku,
          title: item.listing.title,
          recommendation: item.recommendation.action,
          reason: item.recommendation.reason,
          quantityTopUp,
          executed: false,
          execution: executionAllowed ? 'eligible' : 'dry_run_or_not_full_auto',
        };
        if (executionAllowed && quantityTopUp != null) {
          await ebay.reviseInventoryQuantity({ listingId: item.listing.listingId, sku: item.listing.sku, quantity: quantityTopUp });
          planned.executed = true;
          planned.execution = 'quantity_revised_to_5';
        }
        actions.push(planned);
      }
      automationStore.addJobLog({
        jobName: 'optimizer_auto_run',
        status: 'succeeded',
        message: `Autonomous optimizer analyzed ${result.listings.length} listings and prepared ${actions.length} actions.`,
        metadata: { executionAllowed, dryRun: defaultAutomationRules.dryRun, mode: defaultAutomationRules.automationMode },
      });
      return send(res, 200, {
        ...result,
        executionAllowed,
        dryRun: defaultAutomationRules.dryRun,
        mode: defaultAutomationRules.automationMode,
        actions,
      });
    }

    if (req.method === 'GET' && pathname === '/ebay/listings') {
      const limit = Number(url.searchParams.get('limit') ?? 250);
      return send(res, 200, { listings: await ebay.getActiveListings(limit) });
    }

    if (req.method === 'POST' && pathname === '/ebay/listings/end-bulk') {
      const body = await readJson(req);
      const listingIds = Array.isArray(body.listingIds)
        ? body.listingIds.map((value) => String(value).trim()).filter(Boolean)
        : [];
      const reason = isEndingReason(body.reason) ? body.reason : 'NotAvailable';
      const confirm = String(body.confirm ?? '').trim();
      const note = String(body.note ?? '').trim();
      const uniqueListingIds = [...new Set(listingIds)].slice(0, 100);

      if (uniqueListingIds.length === 0) return send(res, 400, { error: 'Select at least one active listing to end.' });
      if (confirm !== `END ${uniqueListingIds.length}`) {
        return send(res, 400, { error: `Type END ${uniqueListingIds.length} to confirm bulk ending.` });
      }

      const results: Array<Record<string, unknown>> = [];
      for (const listingId of uniqueListingIds) {
        try {
          const responseXml = await ebay.endFixedPriceItem(listingId, reason);
          results.push({ listingId, status: 'ended', responseXml });
          automationStore.addAuditLog({
            actor: 'manual',
            action: 'ebay_listing.end',
            targetType: 'ebay_listing',
            targetId: listingId,
            reason: note || `Manual bulk end with reason ${reason}.`,
            after: { reason, source: 'active_listing_manual_bulk_end' },
          });
        } catch (error) {
          results.push({ listingId, status: 'failed', error: error instanceof Error ? error.message : 'Unknown eBay end listing error.' });
        }
      }

      const failed = results.filter((result) => result.status === 'failed');
      automationStore.addJobLog({
        jobName: 'manual_bulk_end_listings',
        status: failed.length ? 'failed' : 'succeeded',
        message: `Manual bulk end processed ${results.length} listings; ${failed.length} failed.`,
        metadata: { reason, note, failed: failed.length },
      });
      return send(res, failed.length ? 207 : 200, { results, ended: results.length - failed.length, failed: failed.length });
    }

    if (req.method === 'GET' && pathname === '/dashboard') {
      const [cjHealth, ebayHealth] = await Promise.all([cj.healthCheck(), ebay.healthCheck()]);
      let scan;
      try {
        scan = await scanEbayListingsForOptimization({ ebay, days: 30, limit: 25, mode: defaultAutomationRules.automationMode });
      } catch (error) {
        scan = { listings: [], recommendations: [], warnings: [error instanceof Error ? error.message : 'Optimization scan failed.'] };
      }
      return send(res, 200, {
        integrations: [cjHealth, ebayHealth],
        ebay: {
          activeListings: Array.isArray(scan.listings) ? scan.listings.length : 0,
          optimizationRecommendations: Array.isArray(scan.recommendations) ? scan.recommendations.length : 0,
          warnings: scan.warnings ?? [],
        },
        automation: {
          mode: defaultAutomationRules.automationMode,
          dryRun: defaultAutomationRules.dryRun,
        },
      });
    }

    if (req.method === 'GET' && pathname === '/rules') {
      return send(res, 200, {
        ...defaultAutomationRules,
        strategy: [
          { signal: 'Low views after 5 days', action: 'Rewrite title, fill item specifics, validate category. Do not drop price first.' },
          { signal: 'Views but CTR below 1.5%', action: 'Change first image and title hook. Generate a better image concept only where truthful and category-safe.' },
          { signal: 'Clicks but no sales', action: 'Improve description, shipping/returns trust, then test 2-5% price reduction above break-even.' },
          { signal: 'No sales after 30 days', action: 'Full creative refresh: title, first image, description, item specifics, controlled price move.' },
          { signal: 'No exposure after 45 days', action: 'Research market again, change category/angle, or end listing.' },
          { signal: 'Fast sales in first 7 days', action: 'Raise price 2-4% and monitor conversion for 72 hours.' },
        ],
      });
    }

    if (req.method === 'GET' && pathname === '/logs') {
      return send(res, 200, { auditLogs: automationStore.getAuditLogs(), jobLogs: automationStore.getJobLogs() });
    }

    if (req.method === 'GET' && !url.pathname.startsWith('/api/') && sendStatic(res, url.pathname)) return;

    return send(res, 404, { error: 'Route not found' });
  } catch (error) {
    automationStore.addJobLog({ jobName: 'api_request', status: 'failed', message: error instanceof Error ? error.message : 'Unknown error', metadata: { path: pathname } });
    return send(res, 500, { error: error instanceof Error ? error.message : 'Unknown error' });
  }
}

const port = getConfig().apiPort;
createServer((req, res) => void route(req, res)).listen(port, () => {
  console.log(`CJ/eBay automation listening on http://localhost:${port}`);
  if (!existsSync(staticRoot)) {
    console.log('No dist folder found yet. Run npm run build:web to serve the frontend from this same URL.');
  }
});

async function timeoutValue<T>(promise: Promise<T>, ms: number, fallback: T): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      promise,
      new Promise<T>((resolve) => {
        timer = setTimeout(() => resolve(fallback), ms);
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}

function isEndingReason(value: unknown): value is 'NotAvailable' | 'Incorrect' | 'LostOrBroken' | 'OtherListingError' | 'SellToHighBidder' {
  return ['NotAvailable', 'Incorrect', 'LostOrBroken', 'OtherListingError', 'SellToHighBidder'].includes(String(value));
}
