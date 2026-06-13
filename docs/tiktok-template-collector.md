# TikTok Template Collector

The exporter can use any TikTok Seller Center XLSX templates found in `TIKTOK_SHOP_TEMPLATE_DIR`.

When a selected CJ product cannot be matched to a downloaded TikTok template, the exporter writes a missing-template request to:

```text
database/template-requests/tiktok-missing-categories.json
```

The collector opens TikTok Seller Center in an automation-controlled browser session and saves a page snapshot for the current Seller Center UI.

```powershell
.\tools\tiktok-template-collector.ps1
```

If it opens the TikTok login page, log in inside that controlled browser window, then run:

```powershell
.\tools\tiktok-template-collector.ps1 -ReuseRunningBrowser
```

Configured `.env` values:

```env
TIKTOK_SHOP_TEMPLATE_DIR=C:\Users\LensPc\Downloads
TIKTOK_TEMPLATE_REQUEST_QUEUE=database/template-requests/tiktok-missing-categories.json
TIKTOK_SELLER_CENTER_BULK_LISTING_URL=https://seller-us.tiktok.com/product/manage
TIKTOK_COLLECTOR_BROWSER_PROFILE=Default
```

Important:

- The collector uses the official Seller Center browser workflow.
- It does not publish products.
- It does not change account settings.
- It does not call hidden or reverse-engineered endpoints.
- If the controlled browser opens the TikTok login page, log in once in that browser session, then rerun the collector with `-ReuseRunningBrowser`.

Why this is demand-driven:

Downloading every TikTok category template is noisy and may hit Seller Center limits. The safer workflow is:

1. Export selected CJ products.
2. If a TikTok category template is missing, the exporter queues it.
3. Run the collector.
4. The collector downloads the needed official templates into `TIKTOK_SHOP_TEMPLATE_DIR`.
5. Re-run export; the exporter picks up the new templates automatically.
