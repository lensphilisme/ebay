import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { CjClient } from '@/backend/integrations/cj/client';
import { EbayClient } from '@/backend/integrations/ebay/client';
import { CjEbayListingEngine } from '@/backend/services/ebay-listing-engine/engine';
import { archiveAndClearTradingLog, parseLatestTradingLog } from '@/backend/services/ebay-listing-engine/utils';

const command = process.argv[2] ?? 'help';
const args = parseArgs(process.argv.slice(3));

async function main(): Promise<void> {
  if (command === 'single') {
    const cjProductId = String(args.cjProductId ?? args.pid ?? '');
    if (!cjProductId) throw new Error('Missing --cjProductId=PRODUCT_ID.');
    const live = boolArg(args.live, false);
    const preflight = boolArg(args.preflight, true);
    const engine = new CjEbayListingEngine({ ebay: live || preflight ? new EbayClient() : undefined });
    const result = await engine.listSingleCJProduct(cjProductId, { live, preflight, countryCode: String(args.countryCode ?? 'US') });
    writeReport(`database/qa/single-${cjProductId}-${Date.now()}.json`, result);
    console.log(JSON.stringify(result, null, 2));
    process.exitCode = result.status === 'failed' ? 1 : 0;
    return;
  }

  if (command === 'bulk') {
    const live = boolArg(args.live, false);
    const preflight = boolArg(args.preflight, true);
    const productIds = typeof args.productIds === 'string' ? args.productIds.split(',').map((id) => id.trim()).filter(Boolean) : undefined;
    const engine = new CjEbayListingEngine({ ebay: live || preflight ? new EbayClient() : undefined });
    const result = await engine.listBulkCJProducts({
      live,
      preflight,
      productIds,
      maxProducts: numberArg(args.limit, 10),
      countryCode: String(args.countryCode ?? 'US'),
    });
    writeReport(`database/qa/bulk-${Date.now()}.json`, result);
    console.log(JSON.stringify(result, null, 2));
    process.exitCode = result.failed > 0 ? 1 : 0;
    return;
  }

  if (command === 'parse-log') {
    console.log(JSON.stringify(parseLatestTradingLog(String(args.path ?? 'database/logs/ebay-trading.log')) ?? { message: 'No log entries found.' }, null, 2));
    return;
  }

  if (command === 'clear-log') {
    const archive = archiveAndClearTradingLog(String(args.path ?? 'database/logs/ebay-trading.log'));
    console.log(JSON.stringify({ archivedTo: archive ?? null, activeLogCleared: Boolean(archive) }, null, 2));
    return;
  }

  if (command === 'end-test-listing') {
    const itemId = String(args.itemId ?? '');
    if (!itemId) throw new Error('Missing --itemId=EBAY_ITEM_ID.');
    const ebay = new EbayClient();
    const responseXml = await ebay.endFixedPriceItem(itemId);
    console.log(responseXml);
    return;
  }

  if (command === 'cj-search') {
    const cj = new CjClient();
    const result = await cj.searchProducts({
      pageNum: 1,
      pageSize: numberArg(args.limit, 10),
      countryCode: String(args.countryCode ?? 'US'),
      keyword: typeof args.keyword === 'string' ? args.keyword : undefined,
      minInventory: 1,
    });
    console.log(JSON.stringify(result, null, 2));
    return;
  }

  if (command === 'cj-inspect') {
    const cjProductId = String(args.cjProductId ?? args.pid ?? '');
    if (!cjProductId) throw new Error('Missing --cjProductId=PRODUCT_ID.');
    const cj = new CjClient();
    const [detail, variants, inventory] = await Promise.all([
      cj.getProductDetail({ pid: cjProductId, countryCode: String(args.countryCode ?? 'US') }),
      cj.getProductVariants({ pid: cjProductId, countryCode: String(args.countryCode ?? 'US') }).catch((error) => ({ error: error instanceof Error ? error.message : 'variant query failed' })),
      cj.queryInventory({ pid: cjProductId }).catch((error) => ({ error: error instanceof Error ? error.message : 'inventory query failed' })),
    ]);
    console.log(JSON.stringify({ detail, variants, inventory }, null, 2));
    return;
  }

  console.log(`Commands:
  single --cjProductId=PRODUCT_ID --live=false --preflight=true
  bulk --limit=10 --live=false --preflight=true
  parse-log
  clear-log
  end-test-listing --itemId=EBAY_ITEM_ID
  cj-search --limit=10 --keyword=shoes
  cj-inspect --cjProductId=PRODUCT_ID`);
}

function parseArgs(values: string[]): Record<string, string | boolean> {
  const parsed: Record<string, string | boolean> = {};
  for (const value of values) {
    const match = value.match(/^--([^=]+)(?:=(.*))?$/);
    if (!match) continue;
    parsed[match[1]] = match[2] ?? true;
  }
  return parsed;
}

function boolArg(value: unknown, fallback: boolean): boolean {
  if (value == null) return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function numberArg(value: unknown, fallback: number): number {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function writeReport(path: string, data: unknown): void {
  const dir = dirname(path);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
  writeFileSync(path, JSON.stringify(data, null, 2));
}

void main().catch((error) => {
  console.error(error instanceof Error ? error.stack ?? error.message : error);
  process.exitCode = 1;
});
