const fs = require('node:fs');
const path = require('node:path');

const reportDir = path.resolve('database/qa');
const reports = fs
  .readdirSync(reportDir)
  .filter((file) => /^bulk-.*\.json$/.test(file))
  .map((file) => ({ file, modifiedAt: fs.statSync(path.join(reportDir, file)).mtimeMs }))
  .sort((a, b) => b.modifiedAt - a.modifiedAt);

if (reports.length === 0) {
  console.log('No bulk reports found.');
  process.exit(0);
}

const latest = reports[0].file;
const report = JSON.parse(fs.readFileSync(path.join(reportDir, latest), 'utf8'));

console.log(`REPORT=${latest}`);
for (const item of report.results ?? []) {
  const payload = item.payload ?? {};
  const variations = Array.isArray(payload.variations) ? payload.variations : [];
  const prices = variations.length ? variations.map((variant) => Number(variant.startPrice)) : [Number(payload.startPrice ?? 0)];
  const priceMin = Math.min(...prices);
  const priceMax = Math.max(...prices);
  const sample = variations
    .slice(0, 4)
    .map((variant) => {
      const specifics = Object.entries(variant.specifics ?? {})
        .map(([name, value]) => `${name}=${value}`)
        .join(',');
      return `${variant.sku}[${variant.quantity}] $${variant.startPrice} {${specifics}}`;
    })
    .join(' || ');

  console.log(
    [
      `PID=${item.cjProductId}`,
      `variants=${item.variantCount}`,
      `images=${item.imageCount}`,
      `keys=${(item.variantKeys ?? []).join('|')}`,
      `category=${item.selectedEbayCategory}`,
      `status=${item.status}`,
      `ack=${item.ebayAttempt?.ack}`,
      `itemId=${item.ebayAttempt?.itemId ?? ''}`,
      `errors=${(item.errors ?? []).join('; ')}`,
    ].join(' '),
  );
  console.log(
    `  priceRange=${priceMin}-${priceMax} ${
      sample || `singleSku=${payload.sku} qty=${payload.quantity}`
    }`,
  );
}
