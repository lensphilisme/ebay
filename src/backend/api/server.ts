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

const cj = new CjClient();
const ebay = new EbayClient();
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
      return send(
        res,
        200,
        await cj.getProductDetail({
          pid: url.searchParams.get('pid') ?? undefined,
          productSku: url.searchParams.get('productSku') ?? undefined,
          variantSku: url.searchParams.get('variantSku') ?? undefined,
          countryCode: url.searchParams.get('countryCode') ?? undefined,
        })
      );
    }

    if (req.method === 'POST' && pathname === '/cj/search') {
      const filters = await readJson(req);
      const data = await cj.searchProducts(filters);
      automationStore.addAuditLog({ actor: 'family_user', action: 'cj.search_products', targetType: 'cj_product', reason: 'Manual CJ product discovery search.', after: filters });
      return send(res, 200, data);
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

    if (req.method === 'GET' && pathname === '/ebay/listings') {
      const limit = Number(url.searchParams.get('limit') ?? 10);
      return send(res, 200, { listings: await ebay.getActiveListings(limit) });
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
