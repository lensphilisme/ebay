# CJ to eBay Family Automation

Private TypeScript/Expo automation console for moving CJ Dropshipping products into eBay listing drafts, pricing them safely, and recommending listing optimizations from real marketplace signals.

## Why There Is Still a Server

The browser should never receive eBay client secrets, refresh tokens, CJ tokens, or webhook credentials. This project keeps those in `.env` and exposes only safe status flags and automation actions through the TypeScript server.

After a web export, the same TypeScript server can serve the frontend and API from one URL.

## Commands

Install:

```bash
npm install
```

Development UI:

```bash
npm run web
```

Expo Go on phone:

```bash
npm run phone
```

API/server:

```bash
npm run api
```

One URL production-style run:

```bash
npm run build:web
npm run start
```

Then open:

```text
http://localhost:8787
```

## Current API Routes

- `GET /api/health`
- `GET /api/settings/status`
- `GET /api/integrations/health`
- `GET /api/ebay/oauth/start`
- `GET /api/cj/categories`
- `GET /api/cj/warehouses`
- `POST /api/cj/search`
- `POST /api/cj/freight`
- `POST /api/ebay/market-research`
- `POST /api/drafts/build`
- `POST /api/optimizer/recommend`
- `GET /api/rules`
- `GET /api/logs`

## Environment

Copy `.env.example` to `.env`, then fill eBay and CJ credentials. The Settings screen reads only safe true/false status from the server.

## Strategy

The optimizer does not blindly “end after X days.” It evaluates listing age together with views, clicks, CTR, sales, CJ stock, CJ cost changes, and competitor price movement.

Examples:

- Low views after several days: rewrite title, fill item specifics, validate category.
- Views with low clicks: change first image and title hook.
- Clicks with no sales: improve description, trust details, and test price above break-even.
- No sales after 30 days: full creative refresh.
- Poor exposure after 45 days: research again, change angle/category, or end.
- Fast early sales: raise price carefully and monitor conversion.

## Current Real-Data Status

- CJ credentials are read from `.env` and category/warehouse API calls work.
- eBay credentials are read from `.env`; refresh-token auth is used before stale access tokens.
- The optimizer scans eBay Inventory API listings first, then requests eBay Analytics traffic for listing IDs. If no REST Inventory listings exist yet, it returns a clear warning instead of asking you to type fake data.
