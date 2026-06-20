import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { CjEbayListingEngine } from '@/backend/services/ebay-listing-engine/engine';
import { buildTradingXml } from '@/backend/services/ebay-listing-engine/utils';

const engine = new CjEbayListingEngine();

async function main(): Promise<void> {
  const results = [];
  for (const fixture of fixtures()) {
    results.push(await engine.processFetchedProduct(fixture.id, fixture.fetched, { preflight: false, live: false }));
  }

  const complex = results.find((result) => result.cjProductId === 'FIXTURE-COLOR-SIZE-IMG');
  assert(complex?.status === 'passed', 'Complex color+size fixture should pass local preflight.');
  assert(Boolean(complex?.payload?.variations?.length), 'Complex fixture should build variations.');
  assert(Boolean(complex?.payload?.variationSpecificsSet?.Color), 'Complex fixture should include Color in VariationSpecificsSet.');
  assert(complex?.payload?.variationPictures?.variationSpecificName === 'Color', 'Complex fixture should use Color for image switching.');

  const shoe = results.find((result) => result.cjProductId === 'FIXTURE-SINGLE-SHOE');
  assert(shoe?.payload?.itemSpecifics.Color === 'Red', 'Single shoe fixture should put Color in ItemSpecifics.');
  assert(shoe?.payload?.itemSpecifics['US Shoe Size'] === '40', 'Single shoe fixture should put US Shoe Size in ItemSpecifics.');

  const skipped = results.find((result) => result.cjProductId === 'FIXTURE-UNSAFE');
  assert(skipped?.status === 'skipped', 'Unsafe fixture should be skipped, not listed.');

  for (const result of results.filter((entry) => entry.payload)) {
    const xml = buildTradingXml('VerifyAddFixedPriceItem', result.payload!);
    assert(!xml.includes('<Name></Name>'), `${result.cjProductId} should not emit empty Name tags.`);
    assert(!xml.includes('<Value></Value>'), `${result.cjProductId} should not emit empty Value tags.`);
    assert(!xml.includes('<PictureDetails></PictureDetails>'), `${result.cjProductId} should include pictures.`);
  }

  const report = {
    generatedAt: new Date().toISOString(),
    total: results.length,
    passed: results.filter((result) => result.status === 'passed').length,
    failed: results.filter((result) => result.status === 'failed').length,
    skipped: results.filter((result) => result.status === 'skipped').length,
    results: results.map((result) => ({
      cjProductId: result.cjProductId,
      productTitle: result.title,
      productType: result.productType,
      variantKeys: result.variantKeys,
      variantCount: result.variantCount,
      imageCount: result.imageCount,
      selectedEbayCategory: result.selectedEbayCategory,
      preflightResult: result.preflight,
      liveListingResult: 'not enabled in fixture QA',
      ebayItemId: result.ebayAttempt.itemId,
      errorsFound: result.errors,
      finalStatus: result.status,
    })),
  };
  if (!existsSync('database/qa')) mkdirSync('database/qa', { recursive: true });
  writeFileSync('database/qa/fixture-qa-report.json', JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
}

function fixtures() {
  const productImage = 'https://cf.cjdropshipping.com/fixture/product.jpg';
  return [
    {
      id: 'FIXTURE-SIMPLE',
      fetched: {
        detail: { pid: 'FIXTURE-SIMPLE', productNameEn: 'Kitchen Storage Organizer Rack', productSku: 'CJSIMPLE001', categoryName: 'Home / Kitchen Storage', sellPrice: 12.5, productImageSet: [productImage], description: '<p>Storage organizer.</p>', totalInventoryNum: 8 },
        variants: [],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-COLOR',
      fetched: {
        detail: { pid: 'FIXTURE-COLOR', productNameEn: 'Portable Water Bottle', productSku: 'CJCOLOR001', productKeyEn: 'Color', categoryName: 'Home / Kitchen', sellPrice: 4, productImageSet: [productImage] },
        variants: [
          { vid: 'V1', variantSku: 'CJCOLOR001-Black', variantKey: 'Black', variantSellPrice: 4, totalInventoryNum: 6, variantImage: 'https://cf.cjdropshipping.com/fixture/black.jpg' },
          { vid: 'V2', variantSku: 'CJCOLOR001-Blue', variantKey: 'Blue', variantSellPrice: 4.2, totalInventoryNum: 5, variantImage: 'https://cf.cjdropshipping.com/fixture/blue.jpg' },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-SIZE',
      fetched: {
        detail: { pid: 'FIXTURE-SIZE', productNameEn: 'Stretch Storage Cover', productSku: 'CJSIZE001', productKeyEn: 'Size', categoryName: 'Home / Storage', sellPrice: 3, productImageSet: [productImage] },
        variants: [
          { vid: 'V1', variantSku: 'CJSIZE001-S', variantKey: 'S', variantSellPrice: 3, totalInventoryNum: 8 },
          { vid: 'V2', variantSku: 'CJSIZE001-M', variantKey: 'M', variantSellPrice: 3.2, totalInventoryNum: 8 },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-COLOR-SIZE-IMG',
      fetched: {
        detail: { pid: 'FIXTURE-COLOR-SIZE-IMG', productNameEn: 'Women Walking Shoes Red Blue Size Options', productSku: 'CJSHOE001', productKeyEn: 'Color,Size', categoryName: 'Shoes / Women Shoes', sellPrice: 9, productImageSet: [productImage] },
        variants: [
          { vid: 'R40', variantSku: 'CJSHOE001-Red-40', variantKey: 'Red-40', variantSellPrice: 9, totalInventoryNum: 3, variantImage: 'https://cf.cjdropshipping.com/fixture/red.jpg' },
          { vid: 'R41', variantSku: 'CJSHOE001-Red-41', variantKey: 'Red-41', variantSellPrice: 9, totalInventoryNum: 4, variantImage: 'https://cf.cjdropshipping.com/fixture/red-2.jpg' },
          { vid: 'B40', variantSku: 'CJSHOE001-Blue-40', variantKey: 'Blue-40', variantSellPrice: 9.5, totalInventoryNum: 2, variantImage: 'https://cf.cjdropshipping.com/fixture/blue.jpg' },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-STYLE',
      fetched: {
        detail: { pid: 'FIXTURE-STYLE', productNameEn: 'Desk Lamp Modern Classic Style', productSku: 'CJSTYLE001', productKeyEn: 'Color,Style', categoryName: 'Home Decor', sellPrice: 7, productImageSet: [productImage] },
        variants: [
          { vid: 'S1', variantSku: 'CJSTYLE001-White-Modern', variantKey: 'White-Modern', variantSellPrice: 7, totalInventoryNum: 5, variantImage: 'https://cf.cjdropshipping.com/fixture/white-modern.jpg' },
          { vid: 'S2', variantSku: 'CJSTYLE001-Black-Classic', variantKey: 'Black-Classic', variantSellPrice: 7, totalInventoryNum: 5, variantImage: 'https://cf.cjdropshipping.com/fixture/black-classic.jpg' },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-SHIPS-FROM',
      fetched: {
        detail: { pid: 'FIXTURE-SHIPS-FROM', productNameEn: 'Pet Grooming Brush', productSku: 'CJSHIP001', productKeyEn: 'Color,Ships From', categoryName: 'Pet Supplies', sellPrice: 5, productImageSet: [productImage] },
        variants: [
          { vid: 'US', variantSku: 'CJSHIP001-Green-US', variantKey: 'Green-US Warehouse', variantSellPrice: 5, totalInventoryNum: 2 },
          { vid: 'CN', variantSku: 'CJSHIP001-Green-CN', variantKey: 'Green-China Warehouse', variantSellPrice: 4.5, totalInventoryNum: 9 },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-PLUG-COLOR',
      fetched: {
        detail: { pid: 'FIXTURE-PLUG-COLOR', productNameEn: 'USB Night Light Plug Type Options', productSku: 'CJPLUG001', productKeyEn: 'Plug Type,Color', categoryName: 'Home Lighting', sellPrice: 6, productImageSet: [productImage] },
        variants: [
          { vid: 'EUW', variantSku: 'CJPLUG001-EU-White', variantKey: 'EU Plug-White', variantSellPrice: 6, totalInventoryNum: 7 },
          { vid: 'USB', variantSku: 'CJPLUG001-US-Black', variantKey: 'US Plug-Black', variantSellPrice: 6, totalInventoryNum: 7 },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-MESSY',
      fetched: {
        detail: { pid: 'FIXTURE-MESSY', productNameEn: '  Smart   Cable\u00a0Organizer !!!  ', productSku: 'CJMES001', productKeyEn: '颜色/规格', categoryName: 'Phone Accessories', sellPrice: 2.5, productImage: '["https://cf.cjdropshipping.com/fixture/messy.jpg"]' },
        variants: [
          { vid: 'M1', variantSku: 'CJ MES 001 / Black / 1m', variantKey: 'Black/1m', variantSellPrice: 2.5, totalInventoryNum: 12 },
          { vid: 'M2', variantSku: 'CJ MES 001 / White / 2m', variantKey: 'White/2m', variantSellPrice: 2.9, totalInventoryNum: 12 },
        ],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-SINGLE-SHOE',
      fetched: {
        detail: { pid: 'FIXTURE-SINGLE-SHOE', productNameEn: 'Women Running Shoe Red Size 40', productSku: 'CJONE001', productKeyEn: 'Color,Size', categoryName: 'Shoes / Women Shoes', sellPrice: 8, productImageSet: [productImage] },
        variants: [{ vid: 'ONE', variantSku: 'CJONE001-Red-40', variantKey: 'Red-40', variantSellPrice: 8, totalInventoryNum: 2, variantImage: 'https://cf.cjdropshipping.com/fixture/red-shoe.jpg' }],
        inventory: undefined,
      },
    },
    {
      id: 'FIXTURE-UNSAFE',
      fetched: {
        detail: { pid: 'FIXTURE-UNSAFE', productNameEn: 'Tactical Self Defense Tool', productSku: 'CJUNSAFE001', categoryName: 'Outdoor', sellPrice: 5, productImageSet: [productImage], totalInventoryNum: 3 },
        variants: [],
        inventory: undefined,
      },
    },
  ];
}

function assert(condition: unknown, message: string): void {
  if (!condition) throw new Error(message);
}

void main().catch((error) => {
  console.error(error instanceof Error ? error.stack ?? error.message : error);
  process.exitCode = 1;
});
