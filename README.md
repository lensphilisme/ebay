# eBay API Starter (PHP)

Production-ready starter for:
- eBay marketplace account deletion webhook verification (GET challenge)
- eBay webhook acknowledgment + event logging (POST)
- eBay Notification API smoke test endpoint
- eBay OAuth dashboard with full Authorization Code scope set

## Project Structure

- `index.php` main router
- `ebay.php` compatibility entrypoint for direct webhook path
- `src/Controllers` request handlers (MVC controller layer)
- `src/Services` API/hash business logic
- `src/Repositories` persistence layer (SQLite)
- `src/Services/EbayOAuthService.php` OAuth URL + token exchange
- `src/Controllers/EbayDashboardController.php` management dashboard UI
- `database/schema.sql` SQL schema file
- `scripts/smoke_test.php` CLI smoke test script

## Setup

1. Copy `.env.example` to `.env`
2. Fill values in `.env`:
   - `EBAY_CLIENT_SECRET`
   - `EBAY_RUNAME` (your Production RuName from eBay app settings)
   - `APP_BASE_URL` (your public base URL used for callback path)
   - `EBAY_APP_TOKEN`
   - `EBAY_WEBHOOK_ENDPOINT` (exact URL used in eBay portal)
3. Make sure Apache/WAMP serves this folder.

## Webhook URL

Use:

`https://<your-domain-or-ngrok>/ebay/ebay.php`

This endpoint supports:
- `GET ?challenge_code=...` -> returns `challengeResponse`
- `POST` -> stores payload in SQLite and returns acknowledgment JSON

## Routes

- `GET /ebay/ebay.php`
- `POST /ebay/ebay.php`
- `GET /ebay/api_subscriptions.php` (tests Notification subscriptions API using `EBAY_APP_TOKEN`)
- `GET /ebay/api_action.php?name=<action>&token_source=user|app` (seller action cards JSON)
- `GET /ebay/dashboard.php` (OAuth + management dashboard)
- `GET /ebay/oauth_start.php` (redirects to eBay consent)
- `GET /ebay/oauth_callback.php` (Authorization Code callback handler)

## Quick Tests

Challenge check:

`https://<host>/ebay/ebay.php?challenge_code=123`

Subscription API check:

`https://<host>/ebay/api_subscriptions.php`

Dashboard:

`https://<host>/ebay/dashboard.php`

Seller action test:

`https://<host>/ebay/api_action.php?name=orders&token_source=user`

Available actions:
- `inventory_items`
- `offers`
- `orders`
- `finances_transactions`
- `account_privileges`
- `fulfillment_policies`
- `payment_policies`
- `return_policies`
- `marketing_campaigns`
- `seller_stores`
- `notification_subscriptions`

CLI smoke test:

`php scripts/smoke_test.php`

## Security Notes

- Keep `.env` out of git.
- Rotate credentials if shared publicly.
- Move from ngrok to a stable HTTPS domain for production webhooks.
