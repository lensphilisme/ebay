<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\EbayConfig;
use App\Repositories\SqliteTokenRepository;
use App\Services\EbayApiClient;
use App\Services\EbayTradingService;
use Throwable;

final class EbayApiController
{
    public function __construct(
        private readonly EbayApiClient $client,
        private readonly SqliteTokenRepository $tokenRepository,
        private readonly EbayConfig $config,
        private readonly EbayTradingService $tradingService
    )
    {
    }

    public function subscriptions(): void
    {
        try {
            $result = $this->client->get('/commerce/notification/v1/subscription');
            $this->json($result, $result['status'] ?? 200);
        } catch (Throwable $throwable) {
            $this->json([
                'status' => 500,
                'error' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function action(string $actionName): void
    {
        $actions = $this->actionMap();
        if (!isset($actions[$actionName])) {
            $this->json(['error' => 'Unknown action: ' . $actionName], 400);
            return;
        }

        $action = $actions[$actionName];
        $tokenSource = (string) ($_GET['token_source'] ?? 'user');
        $resolvedTokenSource = $tokenSource;
        $token = $this->resolveToken($tokenSource, $resolvedTokenSource);

        if ($token === '') {
            $this->json([
                'error' => 'No token available. Complete OAuth to save a user token, or set a valid EBAY_APP_TOKEN.',
                'token_source' => $tokenSource,
                'hint' => 'Try token_source=app for app-token compatible endpoints.',
            ], 400);
            return;
        }

        try {
            if (isset($action['handler']) && $action['handler'] === 'trading_active_listings') {
                $page = max(1, (int) ($_GET['page'] ?? '1'));
                $entriesPerPage = max(1, min(100, (int) ($_GET['limit'] ?? '20')));
                $body = $this->tradingService->getActiveListings($token, $page, $entriesPerPage);
                $this->json([
                    'status' => 200,
                    'action' => $actionName,
                    'token_source' => $tokenSource,
                    'resolved_token_source' => $resolvedTokenSource,
                    'body' => $body,
                ], 200);
                return;
            }

            $result = $this->client->request(
                (string) $action['method'],
                (string) $action['path'],
                $token,
                (array) ($action['query'] ?? []),
                (array) ($action['headers'] ?? [])
            );
            $result['action'] = $actionName;
            $result['token_source'] = $tokenSource;
            $result['resolved_token_source'] = $resolvedTokenSource;
            $this->json($result, (int) ($result['status'] ?? 200));
        } catch (Throwable $throwable) {
            $this->json([
                'status' => 500,
                'error' => $throwable->getMessage(),
                'action' => $actionName,
            ], 500);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function actionMap(): array
    {
        return [
            'inventory_items' => [
                'method' => 'GET',
                'path' => '/sell/inventory/v1/inventory_item',
                'query' => ['limit' => '25'],
            ],
            'offers' => [
                'method' => 'GET',
                'path' => '/sell/inventory/v1/offer',
                'query' => ['limit' => '25'],
            ],
            'orders' => [
                'method' => 'GET',
                'path' => '/sell/fulfillment/v1/order',
                'query' => ['limit' => '25'],
            ],
            'finances_transactions' => [
                'method' => 'GET',
                'path' => '/sell/finances/v1/transaction',
                'query' => ['limit' => '25'],
            ],
            'account_privileges' => [
                'method' => 'GET',
                'path' => '/sell/account/v1/privilege',
            ],
            'fulfillment_policies' => [
                'method' => 'GET',
                'path' => '/sell/account/v1/fulfillment_policy',
            ],
            'payment_policies' => [
                'method' => 'GET',
                'path' => '/sell/account/v1/payment_policy',
            ],
            'return_policies' => [
                'method' => 'GET',
                'path' => '/sell/account/v1/return_policy',
            ],
            'marketing_campaigns' => [
                'method' => 'GET',
                'path' => '/sell/marketing/v1/ad_campaign',
                'query' => ['limit' => '20'],
            ],
            'seller_stores' => [
                'method' => 'GET',
                'path' => '/sell/stores/v1/store',
            ],
            'notification_subscriptions' => [
                'method' => 'GET',
                'path' => '/commerce/notification/v1/subscription',
            ],
            'traditional_active_listings' => [
                'handler' => 'trading_active_listings',
            ],
            'inventory_locations' => [
                'method' => 'GET',
                'path' => '/sell/inventory/v1/location',
                'query' => ['limit' => '20'],
            ],
            'payment_disputes' => [
                'method' => 'GET',
                'path' => '/sell/fulfillment/v1/payment_dispute_summary',
                'query' => ['limit' => '10'],
            ],
        ];
    }

    private function resolveToken(string $tokenSource, string &$resolvedTokenSource): string
    {
        if ($tokenSource === 'app') {
            $resolvedTokenSource = 'app';
            return $this->config->appToken;
        }

        if ($this->config->userToken !== '') {
            $resolvedTokenSource = 'env_user';
            return $this->config->userToken;
        }

        $latest = $this->tokenRepository->latest();
        if (is_array($latest) && isset($latest['access_token'])) {
            $resolvedTokenSource = 'user';
            return (string) $latest['access_token'];
        }

        if ($this->config->appToken !== '') {
            $resolvedTokenSource = 'app_fallback';
            return $this->config->appToken;
        }

        $resolvedTokenSource = 'none';
        return '';
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
