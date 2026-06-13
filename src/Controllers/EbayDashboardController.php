<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\EbayConfig;
use App\Repositories\SqliteCjMappingRepository;
use App\Repositories\SqliteTokenRepository;
use App\Services\CjDropshippingService;
use App\Services\CjMarketplaceExportService;
use App\Services\CjOrderFulfillmentSync;
use App\Services\EbayApiClient;
use App\Services\EbayOAuthService;
use App\Services\EbayTradingService;
use App\Services\MarketplaceTemplateService;
use App\Support\Env;
use RuntimeException;
use Throwable;

final class EbayDashboardController
{
    /** @var array<string, bool> */
    private array $categoryUsShoeSizePreferenceCache = [];

    /** @var array<string, bool|null> */
    private array $ebayCategoryLeafStateCache = [];

    private ?string $ebayMarketPricingTokenCache = null;

    /** @var array<string, array<string, mixed>> */
    private array $ebayMarketPricingCache = [];

    private bool $ebayMarketPricingUnavailable = false;

    public function __construct(
        private readonly EbayConfig $config,
        private readonly EbayOAuthService $oauthService,
        private readonly SqliteTokenRepository $tokenRepository,
        private readonly EbayApiClient $apiClient,
        private readonly EbayTradingService $tradingService,
        private readonly CjDropshippingService $cjService,
        private readonly SqliteCjMappingRepository $cjMappingRepository
    ) {
    }

    public function home(): void
    {
        $latestToken = $this->loadLatestToken();
        $state = bin2hex(random_bytes(16));
        $_SESSION['ebay_oauth_state'] = $state;

        $consentUrl = '';
        $oauthError = null;
        try {
            $consentUrl = $this->oauthService->buildConsentUrl(EbayOAuthService::ALL_AUTH_CODE_SCOPES, $state);
        } catch (Throwable $throwable) {
            $oauthError = $throwable->getMessage();
        }

        $datasets = $this->loadDatasets();
        $selectedListing = $this->loadSelectedListing();
        $selectedOrder = $this->loadSelectedOrder();
        $flash = $this->consumeFlash();
        $recentExportFiles = $this->consumeRecentExportFiles();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHtml($latestToken, $datasets, $consentUrl, $oauthError, $selectedListing, $selectedOrder, $flash, $recentExportFiles);
    }

    public function manageAction(): void
    {
        $action = (string) ($_POST['manage_action'] ?? '');
        $redirect = (string) ($_POST['redirect'] ?? '/ebay/');

        try {
            $message = match ($action) {
                'create_regular_listing' => $this->handleCreateRegularListing(),
                'revise_regular_listing' => $this->handleReviseRegularListing(),
                'end_regular_listing' => $this->handleEndRegularListing(),
                'bulk_end_regular_listings' => $this->handleBulkEndRegularListings(),
                'create_shipping_fulfillment' => $this->handleCreateShippingFulfillment(),
                'issue_refund' => $this->handleIssueRefund(),
                'campaign_control' => $this->handleCampaignControl(),
                'offer_control' => $this->handleOfferControl(),
                'cj_authenticate' => $this->handleCjAuthenticate(),
                'cj_refresh' => $this->handleCjRefresh(),
                'import_cj_product_to_ebay' => $this->handleImportCjProductToEbay(),
                'bulk_import_cj_product_to_ebay' => $this->handleBulkImportCjProductToEbay(),
                'bulk_export_cj_marketplace_files' => $this->handleBulkExportCjMarketplaceFiles(),
                'bulk_sync_cj_inventory' => $this->handleBulkSyncCjInventory(),
                'manual_bulk_inventory_update' => $this->handleManualBulkInventoryUpdate(),
                'sync_ebay_orders_to_cj' => $this->handleSyncEbayOrdersToCj(),
                'sync_cj_orders_from_cj' => $this->handleSyncCjOrdersFromCj(),
                'sync_cj_tracking_to_ebay' => $this->handleSyncCjTrackingToEbay(),
                'sync_orders_two_way' => $this->handleSyncOrdersTwoWay(),
                'configure_cj_webhooks' => $this->handleConfigureCjWebhooks(),
                'update_ebay_prices_from_profit_rows' => $this->handleUpdateEbayPricesFromProfitRows(),
                default => throw new RuntimeException('Unknown management action.'),
            };
            if ($this->isAsyncRequest()) {
                $this->respondManageActionJson(true, $message, $redirect);
            }
            $_SESSION['ebay_flash'] = ['type' => 'ok', 'message' => $message];
        } catch (Throwable $throwable) {
            if ($this->isAsyncRequest()) {
                $this->respondManageActionJson(false, $throwable->getMessage(), $redirect, 400);
            }
            $_SESSION['ebay_flash'] = ['type' => 'warn', 'message' => $throwable->getMessage()];
        }

        header('Location: ' . $redirect);
        exit;
    }

    public function oauthStart(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['ebay_oauth_state'] = $state;
        $this->storeOAuthState($state);

        $url = $this->oauthService->buildConsentUrl(EbayOAuthService::ALL_AUTH_CODE_SCOPES, $state);
        header('Location: ' . $url);
        exit;
    }

    public function downloadExport(): void
    {
        $requested = trim((string) ($_GET['file'] ?? ''));
        if ($requested === '') {
            http_response_code(400);
            echo 'Missing export file.';
            return;
        }

        $exportDir = $this->resolveMarketplaceExportDirectory();
        $exportRoot = realpath($exportDir);
        if ($exportRoot === false) {
            http_response_code(404);
            echo 'Export directory is not available.';
            return;
        }

        $requested = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requested);
        $candidate = preg_match('/^[A-Za-z]:[\\\\\/]/', $requested) === 1 || str_starts_with($requested, DIRECTORY_SEPARATOR)
            ? $requested
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $requested;
        $realFile = realpath($candidate);
        if ($realFile === false || !is_file($realFile) || !str_starts_with(strtolower($realFile), strtolower($exportRoot . DIRECTORY_SEPARATOR))) {
            http_response_code(404);
            echo 'Export file was not found.';
            return;
        }

        $extension = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'csv' => 'text/csv; charset=utf-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string) filesize($realFile));
        header('Content-Disposition: attachment; filename="' . basename($realFile) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($realFile);
    }

    public function oauthCallback(): void
    {
        if (array_key_exists('ebaytkn', $_GET) || isset($_GET['tknexp']) || isset($_GET['username'])) {
            $legacyToken = trim((string) ($_GET['ebaytkn'] ?? ''));
            if ($legacyToken === '') {
                throw new RuntimeException('eBay redirected using the older Auth\'n\'Auth flow, but it did not return a usable token. For this app, use OAuth if possible, or generate a fresh user token again for this application.');
            }

            $this->persistUserTokenToEnv($legacyToken, null);
            $this->persistCurrentPublicBaseUrl();
            $_SESSION['ebay_flash'] = [
                'type' => 'ok',
                'message' => 'eBay returned a fresh Auth\'n\'Auth user token and it was saved into .env automatically.',
            ];
            header('Location: ' . $this->appUrl(['page' => 'dashboard', 'oauth' => 'success']));
            exit;
        }

        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');
        $expectedState = (string) ($_SESSION['ebay_oauth_state'] ?? '');
        $validState = $state !== '' && (
            ($expectedState !== '' && hash_equals($expectedState, $state))
            || $this->consumeStoredOAuthState($state)
        );

        if (!$validState) {
            throw new RuntimeException('Invalid OAuth state. Start OAuth from the same public URL that receives the callback.');
        }

        if ($code === '') {
            throw new RuntimeException('Missing OAuth code from eBay callback.');
        }

        $token = $this->oauthService->exchangeCodeForToken($code);
        $this->tokenRepository->save($token);
        $this->persistUserTokenToEnv((string) ($token['access_token'] ?? ''), isset($token['refresh_token']) ? (string) $token['refresh_token'] : null);
        $this->persistCurrentPublicBaseUrl();
        $_SESSION['ebay_flash'] = [
            'type' => 'ok',
            'message' => isset($token['refresh_token']) ? 'OAuth completed and both access/refresh tokens were saved into .env.' : 'OAuth completed and the access token was saved into .env.',
        ];

        header('Location: ' . $this->appUrl(['page' => 'dashboard', 'oauth' => 'success']));
        exit;
    }

    /** @return array<string, mixed>|null */
    private function loadLatestToken(): ?array
    {
        if ($this->config->userToken !== '') {
            return [
                'token_type' => 'Bearer',
                'expires_in' => 'unknown',
                'created_at' => 'Loaded from .env',
                'access_token' => $this->config->userToken,
                'source' => 'env_user',
            ];
        }

        $latestToken = $this->tokenRepository->latest();
        if (is_array($latestToken)) {
            $latestToken['source'] = 'stored_oauth';
            return $latestToken;
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadDatasets(): array
    {
        $map = [
            'account' => [
                'title' => 'Account',
                'method' => 'GET',
                'path' => '/sell/account/v1/privilege',
                'token_source' => 'user',
            ],
            'store' => [
                'title' => 'Store',
                'method' => 'GET',
                'path' => '/sell/stores/v1/store',
                'token_source' => 'user',
            ],
            'inventory' => [
                'title' => 'Inventory',
                'method' => 'GET',
                'path' => '/sell/inventory/v1/inventory_item',
                'query' => ['limit' => '25'],
                'token_source' => 'user',
            ],
            'offers' => [
                'title' => 'Offers',
                'method' => 'GET',
                'path' => '/sell/inventory/v1/offer',
                'query' => ['limit' => '25'],
                'token_source' => 'user',
            ],
            'orders' => [
                'title' => 'Orders',
                'method' => 'GET',
                'path' => '/sell/fulfillment/v1/order',
                'query' => ['limit' => '25'],
                'token_source' => 'user',
            ],
            'campaigns' => [
                'title' => 'Campaigns',
                'method' => 'GET',
                'path' => '/sell/marketing/v1/ad_campaign',
                'query' => ['limit' => '20'],
                'token_source' => 'user',
            ],
            'fulfillmentPolicies' => [
                'title' => 'Fulfillment Policies',
                'method' => 'GET',
                'path' => '/sell/account/v1/fulfillment_policy',
                'token_source' => 'user',
            ],
            'paymentPolicies' => [
                'title' => 'Payment Policies',
                'method' => 'GET',
                'path' => '/sell/account/v1/payment_policy',
                'token_source' => 'user',
            ],
            'returnPolicies' => [
                'title' => 'Return Policies',
                'method' => 'GET',
                'path' => '/sell/account/v1/return_policy',
                'token_source' => 'user',
            ],
            'notifications' => [
                'title' => 'Notifications',
                'method' => 'GET',
                'path' => '/commerce/notification/v1/subscription',
                'token_source' => 'app',
            ],
            'locations' => [
                'title' => 'Locations',
                'method' => 'GET',
                'path' => '/sell/inventory/v1/location',
                'query' => ['limit' => '20'],
                'token_source' => 'user',
            ],
            'paymentDisputes' => [
                'title' => 'Payment Disputes',
                'method' => 'GET',
                'path' => '/sell/fulfillment/v1/payment_dispute_summary',
                'query' => ['limit' => '10'],
                'token_source' => 'user',
            ],
        ];

        $datasets = [];
        foreach ($map as $key => $definition) {
            $datasets[$key] = $this->requestDataset($definition);
        }

        $tradingTokenSource = 'user';
        $tradingToken = $this->resolveToken('user', $tradingTokenSource);
        if ($tradingToken !== '') {
            $listingPage = max(1, (int) ($_GET['listing_page'] ?? 1));
            $listingPageSize = 5;
            try {
                $trading = $this->tradingService->getActiveListings($tradingToken, $listingPage, $listingPageSize, 'StartTime');
                $trading['cards'] = $this->buildTradingListingCards($tradingToken, (array) ($trading['listings'] ?? []));
                $trading['page'] = $listingPage;
                $trading['pageSize'] = $listingPageSize;
                $datasets['traditionalListings'] = [
                    'title' => 'Traditional Listings',
                    'ok' => true,
                    'status' => 200,
                    'body' => $trading,
                    'error' => '',
                    'token_source' => $tradingTokenSource,
                ];
            } catch (Throwable $throwable) {
                $datasets['traditionalListings'] = [
                    'title' => 'Traditional Listings',
                    'ok' => false,
                    'status' => 500,
                    'body' => [],
                    'error' => $throwable->getMessage(),
                    'token_source' => $tradingTokenSource,
                ];
            }
        } else {
            $datasets['traditionalListings'] = [
                'title' => 'Traditional Listings',
                'ok' => false,
                'status' => 400,
                'body' => [],
                'error' => 'No user token available for Trading API.',
                'token_source' => 'none',
            ];
        }

        return $datasets;
    }

    /** @param array<string, mixed> $definition */
    private function requestDataset(array $definition): array
    {
        $requestedTokenSource = (string) ($definition['token_source'] ?? 'user');
        $resolvedTokenSource = $requestedTokenSource;
        $token = $this->resolveToken($requestedTokenSource, $resolvedTokenSource);

        if ($token === '') {
            return [
                'title' => (string) ($definition['title'] ?? 'Unknown'),
                'ok' => false,
                'status' => 400,
                'body' => ['errors' => [['message' => 'No token available']]],
                'error' => 'No token available.',
                'token_source' => $resolvedTokenSource,
            ];
        }

        try {
            $result = $this->apiClient->request(
                (string) ($definition['method'] ?? 'GET'),
                (string) ($definition['path'] ?? '/'),
                $token,
                (array) ($definition['query'] ?? []),
                (array) ($definition['headers'] ?? [])
            );

            $status = (int) ($result['status'] ?? 500);
            $body = (array) ($result['body'] ?? []);

            return [
                'title' => (string) ($definition['title'] ?? 'Unknown'),
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $body,
                'error' => $this->extractError($body),
                'url' => (string) ($result['url'] ?? ''),
                'token_source' => $resolvedTokenSource,
            ];
        } catch (Throwable $throwable) {
            return [
                'title' => (string) ($definition['title'] ?? 'Unknown'),
                'ok' => false,
                'status' => 500,
                'body' => ['errors' => [['message' => $throwable->getMessage()]]],
                'error' => $throwable->getMessage(),
                'token_source' => $resolvedTokenSource,
            ];
        }
    }

    private function renderCompactDisclosure(string $title, string $body, bool $open = false): string
    {
        return '<details class="compact-disclosure"' . ($open ? ' open' : '') . '>
          <summary><span>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span></summary>
          <div class="compact-disclosure-body">' . $body . '</div>
        </details>';
    }

    /** @param array<string, mixed>|null $latestToken @param array<string, array<string, mixed>> $datasets @param array<string, mixed>|null $selectedListing @param array<string, mixed>|null $selectedOrder @param array<string, string>|null $flash @param list<array<string, mixed>> $recentExportFiles */
    private function renderHtml(?array $latestToken, array $datasets, string $consentUrl, ?string $oauthError, ?array $selectedListing, ?array $selectedOrder, ?array $flash, array $recentExportFiles = []): string
    {
        $page = $this->currentPage();
        $account = $datasets['account'] ?? [];
        $inventory = $datasets['inventory'] ?? [];
        $offers = $datasets['offers'] ?? [];
        $orders = $datasets['orders'] ?? [];
        $campaigns = $datasets['campaigns'] ?? [];
        $store = $datasets['store'] ?? [];
        $fulfillmentPolicies = $datasets['fulfillmentPolicies'] ?? [];
        $paymentPolicies = $datasets['paymentPolicies'] ?? [];
        $returnPolicies = $datasets['returnPolicies'] ?? [];
        $notifications = $datasets['notifications'] ?? [];
        $locations = $datasets['locations'] ?? [];
        $paymentDisputes = $datasets['paymentDisputes'] ?? [];
        $traditionalListings = $datasets['traditionalListings'] ?? [];

        $isConnected = ($account['ok'] ?? false) || ($notifications['ok'] ?? false);
        $connectionLabel = $isConnected ? 'Connected' : 'Needs attention';
        $connectionTone = $isConnected ? 'success' : 'warn';
        $tokenLabel = $this->tokenSourceLabel((string) ($latestToken['source'] ?? 'none'));
        $sellingLimit = $this->formatSellingLimit($account);
        $storeHeadline = $this->storeHeadline($store);
        $listingCount = (int) (($traditionalListings['body']['total'] ?? $inventory['body']['total'] ?? $inventory['body']['size'] ?? 0));
        $offerCount = (int) (($offers['body']['total'] ?? $offers['body']['size'] ?? 0));
        $runningCampaigns = $this->countRunningCampaigns((array) (($campaigns['body'] ?? [])['campaigns'] ?? []));

        $notices = '';
        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            $notices .= '<div class="notice ' . htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($recentExportFiles !== []) {
            $notices .= $this->renderExportDownloadNotice($recentExportFiles);
        }
        if ((string) ($_GET['oauth'] ?? '') === 'success') {
            $notices .= '<div class="notice ok">OAuth completed. A fresh user token is now available to the console.</div>';
        }
        if ($oauthError !== null) {
            $notices .= '<div class="notice warn">' . htmlspecialchars($oauthError, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $oauthButton = $consentUrl !== ''
            ? '<a class="button button-primary" href="' . htmlspecialchars($this->appUrl(['action' => 'oauth_start']), ENT_QUOTES, 'UTF-8') . '">Reconnect eBay</a>'
            : '<span class="button button-muted">OAuth setup incomplete</span>';

        $inventoryRows = $this->renderInventoryRows((array) (($inventory['body'] ?? [])['inventoryItems'] ?? []));
        $traditionalListingGallery = $this->renderTraditionalListingGallery(
            (array) (($traditionalListings['body'] ?? [])['cards'] ?? []),
            (string) ($traditionalListings['error'] ?? ''),
            (string) ($_GET['listing_id'] ?? '')
        );
        $locationRows = $this->renderLocationRows((array) (($locations['body'] ?? [])['locations'] ?? []));
        $offerRows = $this->renderOfferRows((array) (($offers['body'] ?? [])['offers'] ?? []));
        $orderRows = $this->renderOrderRows((array) (($orders['body'] ?? [])['orders'] ?? []));
        $campaignRows = $this->renderCampaignRows((array) (($campaigns['body'] ?? [])['campaigns'] ?? []));
        $policyBlocks = $this->renderPolicyBlocks($fulfillmentPolicies, $paymentPolicies, $returnPolicies);
        $notificationRows = $this->renderNotificationRows((array) (($notifications['body'] ?? [])['subscriptions'] ?? []));
        $paymentDisputeRows = $this->renderPaymentDisputeRows((array) (($paymentDisputes['body'] ?? [])['paymentDisputes'] ?? []));
        $integrationCards = $this->renderIntegrationCards();
        $storePanel = $this->renderStorePanel($store);
        $inventoryVsTradingNote = $this->renderInventoryVsTradingNote($inventory, $traditionalListings);
        $listingDetailPanel = $this->renderListingDetailPanel($selectedListing);
        $listingEditPanel = $this->renderListingActionPanel($selectedListing, 'detail');
        $listingCreatePanel = $this->renderListingActionPanel(null, 'create');
        $orderActionPanel = $this->renderOrderActionPanel($selectedOrder);
        $orderDetailPanel = $this->renderOrderDetailPanel($selectedOrder);
        $cjWorkspace = $this->loadCjWorkspace();
        $cjAuthPanel = $this->renderCjAuthPanel($cjWorkspace);
        $cjSearchPanel = $this->renderCjSearchPanel($cjWorkspace);
        $cjProductGrid = $this->renderCjProductGrid($cjWorkspace);
        $cjProductDetailPanel = $this->renderCjProductDetailPanel($cjWorkspace);
        $hasSelectedCjProduct = is_array($cjWorkspace['selectedProduct'] ?? null);
        $cjMappingPanel = $this->renderCjMappingPanel($cjWorkspace);
        $cjOrderPanel = $this->renderCjOrderPanel($cjWorkspace);
        $listingBulkPanel = $this->renderBulkListingActionPanel((array) (($traditionalListings['body'] ?? [])['cards'] ?? []));
        $traditionalListingPagination = $this->renderTraditionalListingPagination((array) ($traditionalListings['body'] ?? []));
        $activeListingsDropdown = $this->renderCompactDisclosure(
            'Active eBay listings',
            '<div style="margin-top:14px">' . $listingBulkPanel . '</div>
             <div style="margin-top:14px">' . $traditionalListingGallery . '</div>
             <div style="margin-top:14px">' . $traditionalListingPagination . '</div>'
        );
        $inventoryDropdown = $this->renderCompactDisclosure(
            'Inventory API data',
            $inventoryVsTradingNote . '
             <div style="margin-top:14px">' . $inventoryRows . '</div>
             <div style="margin-top:18px">
               <h3 style="margin-bottom:10px">Inventory locations</h3>
               <div style="margin-top:14px">' . $locationRows . '</div>
             </div>'
        );
        $cjAutomationDropdown = $this->renderCompactDisclosure(
            'CJ mappings and sync',
            '<div class="grid-2">
               <div>
                 <h3>Imported CJ listing maps</h3>
                 <div style="margin-top:14px">' . $cjMappingPanel . '</div>
               </div>
               <div>
                 <h3>CJ sync preview</h3>
                 <div style="margin-top:14px">' . $cjOrderPanel . '</div>
               </div>
             </div>'
        );

        $pageTitles = [
            'dashboard' => 'Dashboard',
            'listings' => 'Listings',
            'listing-detail' => 'Product detail',
            'create-listing' => 'Create listing',
            'offers' => 'Offers',
            'orders' => 'Orders',
            'marketing' => 'Marketing',
            'policies' => 'Policies',
            'notifications' => 'Notifications',
            'integrations' => 'Integrations',
        ];
        $pageTitle = $pageTitles[$page] ?? 'Dashboard';

        $dashboardCards = $this->renderWorkspaceCards([
            [
                'eyebrow' => 'Listings',
                'title' => $listingCount . ' live listings',
                'text' => 'Browse live items and open each product on its own detail page.',
                'href' => $this->appUrl(['page' => 'listings']),
                'cta' => 'Open listings',
            ],
            [
                'eyebrow' => 'Orders',
                'title' => ((int) (($orders['body']['total'] ?? $orders['body']['size'] ?? 0))) . ' recent orders',
                'text' => 'Inspect line items, shipments, and refunds in one focused operations page.',
                'href' => $this->appUrl(['page' => 'orders']),
                'cta' => 'Manage orders',
            ],
            [
                'eyebrow' => 'Campaigns',
                'title' => $runningCampaigns . ' campaigns running',
                'text' => 'Pause, resume, or end promoted listing campaigns without digging through filler.',
                'href' => $this->appUrl(['page' => 'marketing']),
                'cta' => 'Open marketing',
            ],
            [
                'eyebrow' => 'Offers',
                'title' => $offerCount . ' offers visible',
                'text' => 'Review Inventory API offers and publish or withdraw them from one screen.',
                'href' => $this->appUrl(['page' => 'offers']),
                'cta' => 'Manage offers',
            ],
            [
                'eyebrow' => 'Integrations',
                'title' => '3rd-party hub',
                'text' => 'Keep dropshipping, shipping, and automation launch points in one place.',
                'href' => $this->appUrl(['page' => 'integrations']),
                'cta' => 'Open integrations',
            ],
        ]);

        $pageContent = match ($page) {
            'listings' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Listings</h2>
                    </div>
                    <div class="actions"><a class="button button-primary" href="' . htmlspecialchars($this->appUrl(['page' => 'create-listing']), ENT_QUOTES, 'UTF-8') . '">Create listing</a></div>
                  </div>
                  <div class="section-body">
                    <div class="panel listing-primary-panel">
                      <h3>CJ product research</h3>
                      <div style="margin-top:14px">' . $cjSearchPanel . '</div>
                      <div style="margin-top:18px">' . $cjProductGrid . '</div>
                    </div>
                    ' . ($hasSelectedCjProduct ? '
                    <div class="panel" id="cj-product-detail-panel" style="margin-top:18px">
                      <h3>CJ product detail</h3>
                      <div style="margin-top:14px">' . $cjProductDetailPanel . '</div>
                    </div>' : '') . '
                    <div class="listing-dropdowns">
                      ' . $activeListingsDropdown . '
                      ' . $inventoryDropdown . '
                      ' . $cjAutomationDropdown . '
                    </div>
                  </div>
                </section>',
            'listing-detail' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Product detail</h2>
                      <div class="section-note">Inspect images, specifics, variations, and description on its own page, then revise or end the listing without fighting the rest of the dashboard.</div>
                    </div>
                    <div class="actions">
                      <a class="button button-secondary" href="' . htmlspecialchars($this->appUrl(['page' => 'listings']), ENT_QUOTES, 'UTF-8') . '">Back to listings</a>
                      <a class="button button-primary" href="' . htmlspecialchars($this->appUrl(['page' => 'create-listing']), ENT_QUOTES, 'UTF-8') . '">Create listing</a>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="grid-2">
                      <div class="panel">
                        <h3>Listing detail</h3>
                        <div class="mini">Selected item data from the Trading listing surface.</div>
                        <div style="margin-top:14px">' . $listingDetailPanel . '</div>
                      </div>
                      <div class="panel">
                        <h3>Edit listing</h3>
                        <div class="mini">Revise the selected product or end it from this dedicated product page.</div>
                        <div style="margin-top:14px">' . $listingEditPanel . '</div>
                      </div>
                    </div>
                  </div>
                </section>',
            'create-listing' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Create listing</h2>
                      <div class="section-note">A dedicated listing creation page with full form fields, image URLs, and item specifics.</div>
                    </div>
                    <div class="actions"><a class="button button-secondary" href="' . htmlspecialchars($this->appUrl(['page' => 'listings']), ENT_QUOTES, 'UTF-8') . '">Back to listings</a></div>
                  </div>
                  <div class="section-body">
                    <div class="panel">' . $listingCreatePanel . '</div>
                  </div>
                </section>',
            'offers' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Offers</h2>
                      <div class="section-note">Inventory API offers live on their own page so publish and withdraw actions stay out of the way of listing editing.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="panel">' . $offerRows . '</div>
                  </div>
                </section>',
            'orders' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Orders</h2>
                      <div class="section-note">Review order traffic, inspect line items, ship packages, and issue refunds from a focused operations page.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="panel">
                      <h3>Recent orders</h3>
                      <div class="mini">Choose an order to open its operational detail.</div>
                      <div style="margin-top:14px">' . $orderRows . '</div>
                    </div>
                    <div class="grid-2" style="margin-top:18px">
                      <div class="panel">
                        <h3>Order detail</h3>
                        <div class="mini">Line items, shipping packages, and refund history.</div>
                        <div style="margin-top:14px">' . $orderDetailPanel . '</div>
                      </div>
                      <div class="panel">
                        <h3>Fulfillment</h3>
                        <div class="mini">Create shipping fulfillments with line-item targeting and tracking details.</div>
                        <div style="margin-top:14px">' . $orderActionPanel . '</div>
                        <div style="margin-top:18px">
                          <h3 style="margin-bottom:10px">Refunds</h3>
                          <div class="mini">Issue order-level or line-item refunds using the selected order context.</div>
                          <div style="margin-top:14px">' . $this->renderRefundActionPanel($selectedOrder) . '</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </section>',
            'marketing' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Marketing</h2>
                      <div class="section-note">Promoted listing campaigns now live on their own page so you can manage them without scrolling through unrelated seller data.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="panel">' . $campaignRows . '</div>
                  </div>
                </section>',
            'policies' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Policies</h2>
                      <div class="section-note">Fulfillment, payment, and return policies are grouped into a dedicated account policies page.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="panel">' . $policyBlocks . '</div>
                  </div>
                </section>',
            'notifications' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Notifications</h2>
                      <div class="section-note">Application-level webhook subscriptions and dispute visibility stay together in one operational monitoring page.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="grid-2">
                      <div class="panel">' . $notificationRows . '</div>
                      <div class="panel">' . $paymentDisputeRows . '</div>
                    </div>
                  </div>
                </section>',
            'integrations' => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Integrations</h2>
                      <div class="section-note">Keep CJdropshipping, shipping services, and automation launch points on a page that is honest about what is and is not connected yet.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="grid-2">
                      <div class="panel">
                        <h3>CJ connection</h3>
                        <div class="mini">Backend-only CJ auth, refresh handling, and token persistence for product research and order sync.</div>
                        <div style="margin-top:14px">' . $cjAuthPanel . '</div>
                        <div style="margin-top:18px">' . $integrationCards . '</div>
                      </div>
                      <div class="panel">
                        <h3>MCP-aligned management surfaces</h3>
                        <div class="mini">This console now prioritizes the non-store-dependent surfaces that are actually useful for your account.</div>
                        <div class="kv" style="margin-top:14px">
                          <div class="kv-item"><div class="kv-key">Regular listings</div><div>' . htmlspecialchars($this->statusLabel((int) ($traditionalListings['status'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</div></div>
                          <div class="kv-item"><div class="kv-key">Offers</div><div>' . htmlspecialchars($this->statusLabel((int) ($offers['status'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</div></div>
                          <div class="kv-item"><div class="kv-key">Orders</div><div>' . htmlspecialchars($this->statusLabel((int) ($orders['status'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</div></div>
                          <div class="kv-item"><div class="kv-key">Notifications</div><div>' . htmlspecialchars($this->statusLabel((int) ($notifications['status'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</div></div>
                        </div>
                        <div style="margin-top:18px">' . $cjMappingPanel . '</div>
                        <div style="margin-top:18px">' . $cjOrderPanel . '</div>
                      </div>
                    </div>
                  </div>
                </section>',
            default => '
                <section class="section">
                  <div class="section-head">
                    <div>
                      <h2>Operator dashboard</h2>
                      <div class="section-note">A lighter home page with connection health, quick metrics, and direct entry points into real workspaces.</div>
                    </div>
                  </div>
                  <div class="section-body">
                    <div class="grid-2">
                      <div class="panel">' . $storePanel . '</div>
                      <div class="panel">
                        <h3>What to open next</h3>
                        <div class="mini">Use the cards below to jump straight into listings, orders, offers, campaigns, or integrations.</div>
                        <div class="kv" style="margin-top:14px">
                          <div class="kv-item"><div class="kv-key">Listings</div><div>' . $listingCount . ' live regular listings available</div></div>
                          <div class="kv-item"><div class="kv-key">Offers</div><div>' . $offerCount . ' Inventory API offers visible</div></div>
                          <div class="kv-item"><div class="kv-key">Campaigns</div><div>' . $runningCampaigns . ' currently running</div></div>
                          <div class="kv-item"><div class="kv-key">Store</div><div>' . htmlspecialchars($storeHeadline, ENT_QUOTES, 'UTF-8') . '</div></div>
                        </div>
                      </div>
                    </div>
                    <div style="margin-top:18px">' . $dashboardCards . '</div>
                  </div>
                </section>',
        };

        return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>eBay Seller Console</title>
  <style>
    @import url("https://cdn.jsdelivr.net/npm/modern-normalize@2.0.0/modern-normalize.min.css");
    @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;700;800&display=swap");
    :root{
      --bg:#f5f7fb;
      --ink:#182235;
      --muted:#5f6f86;
      --paper:#ffffff;
      --card:#ffffff;
      --line:#dce3ee;
      --accent:#2563eb;
      --accent-soft:#e7efff;
      --accent-deep:#1d4ed8;
      --emerald:#047857;
      --emerald-soft:#dff7ed;
      --sky:#e0f2fe;
      --sky-ink:#0369a1;
      --gold:#b7791f;
      --shadow:0 12px 30px rgba(24,34,53,.08);
      --glass-border:1px solid var(--line);
      --radius:8px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:"Inter",sans-serif;
      color:var(--ink);
      background:var(--bg);
      background-attachment: fixed;
    }
    .shell{
      max-width:none;
      margin:0 auto;
      padding:16px clamp(12px,2vw,28px) 40px;
    }
    .hero{
      background:linear-gradient(180deg,#ffffff,#f9fbff);
      border:var(--glass-border);
      border-radius:var(--radius);
      padding:28px;
      box-shadow:var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .hero::after{
      display:none;
    }
    .hero-grid{
      display:grid;
      grid-template-columns:1.35fr .95fr;
      gap:18px;
      position:relative;
      z-index:1;
    }
    .eyebrow{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border-radius:var(--radius);
      font-size:12px;
      font-weight:800;
      letter-spacing:.08em;
      text-transform:uppercase;
      background:var(--accent-soft);
      color:var(--accent-deep);
      margin-bottom:14px;
    }
    h1{
      margin:0;
      font-family:"Outfit",sans-serif;
      font-size:46px;
      line-height:1.02;
      letter-spacing:0;
      color:var(--ink);
    }
    .hero-copy{
      margin:14px 0 0;
      color:var(--muted);
      max-width:720px;
      line-height:1.7;
      font-size:15px;
    }
    .topline{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      align-items:center;
      margin-top:18px;
    }
    .status-pill{
      display:inline-flex;
      align-items:center;
      gap:10px;
      border-radius:999px;
      padding:12px 16px;
      font-size:14px;
      font-weight:800;
      background:' . ($connectionTone === 'success' ? 'var(--emerald-soft);color:var(--emerald);' : 'var(--accent-soft);color:var(--accent-deep);') . '
    }
    .status-pill.warn{background:#fff1f2;color:#be123c}
    .status-pill.ok{background:#ecfdf5;color:#047857}
    .metric-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
      align-self:start;
    }
    .metric{
      background:var(--card);
      border:var(--glass-border);
      border-radius:var(--radius);
      padding:16px;
      min-height:114px;
      transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .metric:hover{
      transform:translateY(-4px);
      box-shadow:0 14px 28px rgba(24,34,53,.12);
    }
    .metric-label{
      color:var(--muted);
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.08em;
      margin-bottom:8px;
      font-weight:800;
    }
    .metric-value{
      font-size:22px;
      font-weight:800;
      line-height:1.2;
    }
    .metric-note{
      color:var(--muted);
      margin-top:8px;
      font-size:13px;
      line-height:1.5;
    }
    .actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:22px;
    }
    .button{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:44px;
      padding:0 16px;
      border-radius:var(--radius);
      border:1px solid transparent;
      text-decoration:none;
      font-weight:800;
    }
    .button-primary{background:var(--accent);color:#fff;box-shadow:0 10px 20px rgba(37,99,235,.18);border:none;transition:transform .18s ease,box-shadow .18s ease,background .18s ease;}
    .button-primary:hover{transform:translateY(-1px);background:var(--accent-deep);box-shadow:0 14px 28px rgba(37,99,235,.22);}
    .button-secondary{background:#fff;border-color:var(--line);color:var(--ink);transition:background .18s ease,border-color .18s ease,transform .18s ease;}
    .button-secondary:hover{background:#f3f7fc;transform:translateY(-1px);}
    .button-muted{background:#edf2f7;color:var(--muted);border-color:var(--line);}
    .button-danger{background:#fff1f2;border-color:#fecdd3;color:#be123c;}
    .notice{
      margin-top:16px;
      padding:12px 14px;
      border-radius:var(--radius);
      font-size:14px;
      line-height:1.6;
    }
    .notice.ok{background:var(--emerald-soft);color:var(--emerald)}
    .notice.warn{background:var(--accent-soft);color:var(--accent-deep)}
    .async-overlay{
      position:fixed;
      right:18px;
      bottom:18px;
      left:auto;
      top:auto;
      display:flex;
      align-items:flex-end;
      justify-content:flex-end;
      padding:0;
      opacity:0;
      pointer-events:none;
      transition:opacity .24s ease;
      z-index:50;
    }
    .async-overlay.active{
      opacity:1;
    }
    .async-card{
      width:min(360px, calc(100vw - 28px));
      background:rgba(18, 25, 41, 0.94);
      border:var(--glass-border);
      border-radius:24px;
      box-shadow:var(--shadow);
      padding:18px;
    }
    .async-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-bottom:12px;
    }
    .async-title{
      margin:0;
      font-family:"Outfit",sans-serif;
      font-size:24px;
      letter-spacing:-.03em;
    }
    .async-spinner{
      width:20px;
      height:20px;
      border-radius:50%;
      border:2px solid rgba(255,255,255,.22);
      border-top-color:#8b5cf6;
      animation:spin .9s linear infinite;
      flex:none;
    }
    .async-note{
      color:var(--muted);
      font-size:14px;
      line-height:1.6;
      margin-bottom:14px;
    }
    .progress-track{
      width:100%;
      height:12px;
      border-radius:999px;
      background:rgba(255,255,255,.08);
      overflow:hidden;
      border:1px solid rgba(255,255,255,.08);
    }
    .progress-fill{
      height:100%;
      width:0%;
      background:linear-gradient(90deg, #8b5cf6, #38bdf8 72%, #10b981);
      border-radius:inherit;
      transition:width .22s ease;
    }
    .progress-meta{
      margin-top:10px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      color:var(--muted);
      font-size:13px;
    }
    .markup-builder{
      display:grid;
      gap:12px;
      margin-top:12px;
    }
    .markup-tier-list{
      display:grid;
      gap:10px;
    }
    .markup-tier-row{
      display:grid;
      grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;
      gap:10px;
      align-items:end;
    }
    .markup-tier-remove{
      min-height:44px;
      min-width:44px;
      padding:0 14px;
    }
    .cj-price-editor{
      display:grid;
      gap:8px;
      padding:10px;
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:#f7f9fc;
    }
    .cj-price-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:8px;
    }
    .cj-price-value{
      display:block;
      color:var(--ink);
      font-weight:800;
      margin-top:2px;
    }
    .cj-price-inline{
      display:flex;
      align-items:center;
      gap:4px;
      margin-top:2px;
      color:var(--ink);
      font-weight:800;
    }
    .cj-price-inline input{
      width:86px;
      min-height:30px;
      padding:4px 7px;
      border-radius:8px;
      font-weight:800;
    }
    .cj-price-actions{
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }
    .cj-price-button{
      min-width:30px;
      min-height:30px;
      width:30px;
      height:30px;
      padding:0;
      border-radius:8px;
      font-size:12px;
    }
    .cj-price-status{
      color:var(--muted);
      font-size:12px;
      font-weight:700;
    }
    @keyframes spin{
      to{transform:rotate(360deg)}
    }
    .section{
      margin-top:18px;
      background:rgba(30, 41, 59, 0.3);
      backdrop-filter:blur(20px);
      -webkit-backdrop-filter:blur(20px);
      border:var(--glass-border);
      border-radius:24px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .section-head{
      display:flex;
      flex-wrap:wrap;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      padding:20px 20px 10px;
    }
    .section-head h2{
      margin:0;
      font-family:"Fraunces",serif;
      font-size:31px;
      letter-spacing:-.02em;
    }
    .section-note{
      color:var(--muted);
      font-size:14px;
      line-height:1.6;
      max-width:720px;
    }
    .section-body{padding:0 20px 20px}
    .nav-strip{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:18px;
    }
    .nav-link{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:42px;
      padding:0 14px;
      border-radius:999px;
      text-decoration:none;
      font-weight:800;
      font-size:13px;
      color:var(--muted);
      background:rgba(255,255,255,0.05);
      border:1px solid var(--line);
      transition:all 0.2s;
    }
    .nav-link:hover{
      color:var(--ink);
      background:rgba(255,255,255,0.1);
    }
    .nav-link.active{
      background:linear-gradient(135deg, #8b5cf6, #38bdf8);
      color:#fff;
      border:none;
      box-shadow:0 8px 16px rgba(139,92,246,.3);
    }
    .grid-2{
      display:grid;
      grid-template-columns:1.15fr .85fr;
      gap:18px;
    }
    .panel{
      background:rgba(30, 41, 59, 0.4);
      backdrop-filter:blur(10px);
      border:var(--glass-border);
      border-radius:20px;
      padding:18px;
    }
    .panel h3{
      margin:0 0 12px;
      font-size:18px;
      letter-spacing:-.01em;
    }
    .listing-dropdowns{
      display:grid;
      gap:10px;
      margin-top:18px;
    }
    .compact-disclosure,
    .compact-options{
      border:var(--glass-border);
      border-radius:16px;
      background:rgba(30, 41, 59, 0.42);
      overflow:hidden;
    }
    .compact-disclosure summary,
    .compact-options summary{
      list-style:none;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      min-height:46px;
      padding:0 14px;
      font-weight:800;
      color:var(--ink);
    }
    .compact-disclosure summary::-webkit-details-marker,
    .compact-options summary::-webkit-details-marker{
      display:none;
    }
    .compact-disclosure summary::after,
    .compact-options summary::after{
      content:"+";
      width:24px;
      height:24px;
      display:inline-grid;
      place-items:center;
      border-radius:999px;
      background:rgba(255,255,255,.08);
      color:var(--muted);
      flex:none;
    }
    .compact-disclosure[open] summary::after,
    .compact-options[open] summary::after{
      content:"-";
    }
    .compact-disclosure-body,
    .compact-options-body{
      padding:0 14px 14px;
    }
    .bulk-toolbar .compact-options,
    .subcard .compact-options{
      background:#f8fbff;
      border:1px solid rgba(91,107,133,.16);
    }
    .bulk-toolbar .compact-options summary,
    .subcard .compact-options summary{
      color:#172033;
    }
    .mini{
      color:var(--muted);
      font-size:13px;
      line-height:1.6;
    }
    .table-wrap{
      overflow:auto;
      border:1px solid rgba(16,32,51,.08);
      border-radius:18px;
      background:#fff;
      color:#172033;
    }
    table{
      width:100%;
      border-collapse:collapse;
      min-width:680px;
    }
    th,td{
      padding:14px 16px;
      text-align:left;
      border-bottom:1px solid rgba(16,32,51,.08);
      vertical-align:top;
      font-size:14px;
    }
    th{
      color:var(--muted);
      text-transform:uppercase;
      letter-spacing:.08em;
      font-size:11px;
      font-weight:800;
      background:#faf5ee;
    }
    tr:last-child td{border-bottom:none}
    .badge{
      display:inline-flex;
      align-items:center;
      padding:7px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:800;
      white-space:nowrap;
    }
    .badge.good{background:var(--emerald-soft);color:var(--emerald)}
    .badge.warn{background:#fff0cf;color:#7d5f12}
    .badge.neutral{background:var(--sky);color:var(--sky-ink)}
    .inline-form{display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center}
    .action-stack{display:grid;gap:14px}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .form-grid-1{display:grid;grid-template-columns:1fr;gap:12px}
    label{display:grid;gap:6px;font-size:13px;color:var(--muted);font-weight:700}
    input,select,textarea{
      width:100%;
      border:var(--glass-border);
      border-radius:12px;
      padding:11px 12px;
      font:inherit;
      color:var(--ink);
      background:rgba(0,0,0,0.2);
    }
    .subcard label,
    .subcard .mini,
    .subcard .kv-key{
      color:#5b6b85;
    }
    .subcard input,
    .subcard select,
    .subcard textarea{
      color:#172033;
      background:#f3f6fb;
      border:1px solid rgba(91,107,133,.18);
    }
    .subcard input::placeholder,
    .subcard textarea::placeholder{
      color:#73849d;
    }
    textarea{min-height:120px;resize:vertical}
    .subcard{
      border:1px solid rgba(16,32,51,.08);
      border-radius:18px;
      padding:16px;
      background:#fffdfa;
      color:#172033;
    }
    .image-strip{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(110px,1fr));
      gap:12px;
    }
    .image-strip img{
      width:100%;
      aspect-ratio:1/1;
      object-fit:cover;
      border-radius:14px;
      border:1px solid rgba(16,32,51,.08);
      background:#fff;
    }
    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .description-box{
      max-height:220px;
      overflow:auto;
      padding:14px;
      border:1px solid rgba(16,32,51,.08);
      border-radius:14px;
      background:#fff;
      line-height:1.7;
      color:#172033;
      white-space:pre-wrap;
    }
    .variant-toggle{
      margin:0;
      border:1px solid rgba(91,107,133,.18);
      border-radius:16px;
      background:#f8fbff;
      color:#172033;
      overflow:hidden;
    }
    .variant-toggle summary{
      cursor:pointer;
      list-style:none;
      padding:14px 16px;
      font-weight:800;
    }
    .variant-toggle summary::-webkit-details-marker{
      display:none;
    }
    .subcard h4,
    .subcard strong,
    .subcard a,
    .table-wrap a{
      color:#172033;
    }
    .listing-gallery{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
      gap:16px;
    }
    .listing-card{
      display:grid;
      gap:12px;
      border:var(--glass-border);
      border-radius:20px;
      padding:14px;
      background:rgba(30, 41, 59, 0.5);
      transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }
    .listing-card:hover{
      transform:translateY(-3px);
      box-shadow:0 14px 26px rgba(16,32,51,.1);
    }
    .listing-card.selected{
      border-color:#a78bfa;
      box-shadow:0 8px 30px rgba(139,92,246,.3);
      background:rgba(139,92,246,.15);
    }
    .card-topline{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      min-width:0;
    }
    .card-select{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-size:12px;
      font-weight:800;
      color:var(--ink);
      background:rgba(30,30,40,0.8);
      border:var(--glass-border);
      border-radius:999px;
      padding:6px 10px;
    }
    .card-select input{
      width:16px;
      height:16px;
      margin:0;
      padding:0;
    }
    .market-chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:0;
      max-width:100%;
      padding:6px 9px;
      border-radius:999px;
      font-size:11px;
      font-weight:800;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      color:#0f3a5c;
      background:#e0f2fe;
      border:1px solid rgba(14,116,144,.16);
    }
    .market-chip.good{
      color:#047857;
      background:#ecfdf5;
      border-color:rgba(4,120,87,.16);
    }
    .listing-card-image{
      width:100%;
      aspect-ratio:1/1;
      object-fit:cover;
      border-radius:16px;
      background:#f3ede3;
      border:1px solid rgba(16,32,51,.06);
    }
    .listing-card-placeholder{
      display:grid;
      place-items:center;
      aspect-ratio:1/1;
      border-radius:16px;
      background:#f5eee5;
      color:var(--muted);
      border:1px dashed rgba(16,32,51,.12);
      font-size:13px;
      text-align:center;
      padding:14px;
    }
    .listing-card-title{
      margin:0;
      font-size:17px;
      line-height:1.35;
    }
    .listing-card-title a{
      color:var(--ink);
      text-decoration:none;
    }
    .listing-meta{
      display:grid;
      gap:8px;
      font-size:13px;
      color:var(--muted);
    }
    .pill-row{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
    }
    .toolbar-row{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      align-items:end;
    }
    .bulk-toolbar{
      position:sticky;
      top:14px;
      z-index:3;
      padding:16px;
      border-radius:20px;
      background:linear-gradient(180deg, rgba(255,255,255,.96), rgba(251,245,236,.98));
      border:1px solid rgba(16,32,51,.08);
      box-shadow:0 14px 30px rgba(16,32,51,.08);
    }
    .bulk-toolbar .button-secondary{
      background:#e7edf8;
      color:#172033;
      border-color:rgba(23,32,51,.12);
    }
    .bulk-toolbar .button-secondary:hover{
      background:#dbe5f5;
      color:#0f172a;
    }
    .bulk-toolbar label{
      color:#5b6b85;
    }
    .bulk-toolbar select,
    .bulk-toolbar input{
      color:#172033;
      background:#eef3fb;
      border:1px solid rgba(91,107,133,.16);
    }
    .scroll-detail-button{
      position:fixed;
      right:18px;
      bottom:118px;
      z-index:45;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:48px;
      padding:0 16px;
      border-radius:999px;
      border:none;
      background:linear-gradient(135deg, #8b5cf6, #38bdf8);
      color:#fff;
      font-weight:800;
      box-shadow:0 14px 28px rgba(56,189,248,.25);
      cursor:pointer;
    }
    .selection-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
      gap:10px;
    }
    .selection-chip{
      display:flex;
      gap:10px;
      align-items:flex-start;
      padding:12px;
      border-radius:16px;
      border:1px solid rgba(16,32,51,.08);
      background:#fff;
      cursor:pointer;
    }
    .selection-chip small{
      display:block;
      color:var(--muted);
      margin-top:4px;
      font-size:12px;
    }
    .category-tree{
      margin-top:14px;
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:#fff;
      color:var(--ink);
      max-height:360px;
      overflow:auto;
    }
    .category-tree summary{
      cursor:pointer;
      padding:12px 14px;
      font-weight:800;
      border-bottom:1px solid var(--line);
    }
    .category-tree ul{
      margin:0;
      padding:8px 0 8px 22px;
      line-height:1.55;
      font-size:13px;
    }
    .category-tree li{
      padding:2px 8px 2px 0;
    }
    .product-grid{
      display:grid;
      grid-template-columns:repeat(10,minmax(0,1fr));
      gap:14px;
    }
    .empty{
      padding:20px;
      border-radius:18px;
      background:#fcf7ef;
      color:var(--muted);
      line-height:1.7;
      border:1px dashed #d9d0c0;
    }
    .stack{
      display:grid;
      gap:16px;
    }
    .store-box{
      display:grid;
      gap:14px;
    }
    .store-main{
      font-size:28px;
      font-family:"Fraunces",serif;
      line-height:1.1;
      margin:0;
    }
    .kv{
      display:grid;
      gap:10px;
    }
    .kv-item{
      display:grid;
      grid-template-columns:140px 1fr;
      gap:12px;
      padding:10px 0;
      border-top:1px solid rgba(16,32,51,.08);
    }
    .kv-item:first-child{border-top:none;padding-top:0}
    .kv-key{
      color:var(--muted);
      text-transform:uppercase;
      letter-spacing:.08em;
      font-size:11px;
      font-weight:800;
    }
    .policy-columns{
      display:grid;
      grid-template-columns:1fr;
      gap:16px;
    }
    .policy-card{
      background:#fff;
      border:1px solid rgba(16,32,51,.08);
      border-radius:18px;
      padding:18px;
    }
    .policy-card h4{
      margin:0 0 12px;
      font-size:16px;
    }
    .policy-card ul{
      margin:0;
      padding-left:18px;
      color:var(--muted);
      line-height:1.7;
      font-size:14px;
    }
    .integration-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px;
    }
    .workspace-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:16px;
    }
    .workspace-card{
      display:grid;
      gap:12px;
      padding:20px;
      border-radius:22px;
      background:rgba(30, 41, 59, 0.4);
      backdrop-filter:blur(12px);
      border:var(--glass-border);
      box-shadow:var(--shadow);
      transition:transform 0.3s ease, box-shadow 0.3s ease;
    }
    .workspace-card:hover{
      transform:translateY(-5px);
      box-shadow:0 16px 32px rgba(139,92,246,0.25);
    }
    .workspace-eyebrow{
      color:var(--accent-deep);
      text-transform:uppercase;
      letter-spacing:.08em;
      font-size:11px;
      font-weight:800;
    }
    .workspace-card h3{
      margin:0;
      font-size:19px;
      line-height:1.18;
    }
    .workspace-card p{
      margin:0;
      color:var(--muted);
      line-height:1.65;
      font-size:14px;
    }
    .workspace-card a{
      text-decoration:none;
      color:var(--accent-deep);
      font-weight:800;
    }
    .integration-card{
      background:rgba(30, 41, 59, 0.4);
      border:var(--glass-border);
      border-radius:20px;
      padding:18px;
      display:grid;
      gap:10px;
      align-content:start;
    }
    .integration-card h4{
      margin:0;
      font-size:18px;
    }
    .integration-card p{
      margin:0;
      color:var(--muted);
      line-height:1.6;
      font-size:14px;
    }
    .integration-card a{
      text-decoration:none;
      color:var(--accent-deep);
      font-weight:800;
    }
    .footer-rail{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:12px;
    }
    .section{
      background:transparent;
      border:none;
      border-radius:0;
      box-shadow:none;
      overflow:visible;
    }
    .section-head{
      padding:22px 2px 12px;
    }
    .section-body{
      padding:0;
    }
    .panel,
    .subcard,
    .metric,
    .workspace-card,
    .integration-card,
    .listing-card,
    .policy-card,
    .async-card,
    .table-wrap,
    .variant-toggle,
    .empty,
    .bulk-toolbar,
    .notice,
    .listing-card-image,
    .listing-card-placeholder,
    .image-strip img,
    input,
    select,
    textarea,
    .nav-link,
    .badge,
    .status-pill,
    .eyebrow{
      border-radius:var(--radius);
    }
    .panel,
    .subcard,
    .workspace-card,
    .integration-card,
    .listing-card,
    .policy-card{
      background:var(--card);
      color:var(--ink);
      border:1px solid var(--line);
      box-shadow:0 8px 22px rgba(24,34,53,.06);
      backdrop-filter:none;
    }
    .workspace-card:hover,
    .integration-card:hover,
    .listing-card:hover{
      transform:translateY(-2px);
      box-shadow:0 14px 28px rgba(24,34,53,.1);
    }
    .workspace-eyebrow,
    .workspace-card a,
    .integration-card a,
    .table-wrap a{
      color:var(--accent-deep);
    }
    .workspace-card p,
    .integration-card p,
    .listing-meta,
    .mini{
      color:var(--muted);
    }
    input,
    select,
    textarea,
    .subcard input,
    .subcard select,
    .subcard textarea,
    .bulk-toolbar input,
    .bulk-toolbar select{
      color:var(--ink);
      background:#fff;
      border:1px solid var(--line);
    }
    input:focus,
    select:focus,
    textarea:focus{
      outline:3px solid rgba(37,99,235,.16);
      border-color:var(--accent);
    }
    .table-wrap{
      background:#fff;
      color:var(--ink);
    }
    th{
      background:#f7f9fc;
      color:#4c5d73;
      letter-spacing:.04em;
    }
    .nav-link{
      background:#fff;
      color:var(--muted);
    }
    .nav-link.active{
      background:var(--accent);
      color:#fff;
      border-color:var(--accent);
      box-shadow:0 10px 22px rgba(37,99,235,.2);
    }
    .bulk-toolbar{
      background:#ffffff;
      border:1px solid var(--line);
      box-shadow:0 10px 26px rgba(24,34,53,.09);
    }
    .product-grid{
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    }
    .async-card{
      background:#fff;
      color:var(--ink);
    }
    .progress-fill{
      background:linear-gradient(90deg, var(--accent), #0891b2, var(--emerald));
    }
    @media (prefers-reduced-motion: reduce){
      *,
      *::before,
      *::after{
        animation-duration:.01ms!important;
        animation-iteration-count:1!important;
        scroll-behavior:auto!important;
        transition-duration:.01ms!important;
      }
    }
    @media (max-width:1180px){
      .hero-grid,.grid-2,.integration-grid,.detail-grid,.workspace-grid{grid-template-columns:1fr 1fr}
      .product-grid{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}
    }
    @media (max-width:860px){
      h1{font-size:34px}
      .hero-grid,.grid-2,.policy-columns,.integration-grid,.metric-grid,.detail-grid,.form-grid,.workspace-grid{grid-template-columns:1fr}
      .product-grid{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
      .shell{
        width:min(calc(100vw - 12px), 100%);
        padding:12px 0 28px;
      }
      .section-head,.section-body{padding-left:16px;padding-right:16px}
      .kv-item{grid-template-columns:1fr}
    }
    @media (max-width:560px){
      .product-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media (max-width:420px){
      .product-grid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="shell" id="app-shell">
    <section class="hero">
      <div class="hero-grid">
        <div>
          <div class="eyebrow">Live eBay seller management</div>
          <h1>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</h1>
          <p class="hero-copy">Cleaner workspaces, less filler, faster paths into the parts of eBay you actually use.</p>
          <div class="topline">
            <span class="status-pill">' . htmlspecialchars($connectionLabel, ENT_QUOTES, 'UTF-8') . '</span>
            <span class="badge neutral">Token: ' . htmlspecialchars($tokenLabel, ENT_QUOTES, 'UTF-8') . '</span>
            <span class="badge neutral">Selling limit: ' . htmlspecialchars($sellingLimit, ENT_QUOTES, 'UTF-8') . '</span>
            <span class="badge neutral">Store: ' . htmlspecialchars($storeHeadline, ENT_QUOTES, 'UTF-8') . '</span>
          </div>
          <div class="actions">
            ' . $oauthButton . '
            <a class="button button-secondary" href="/ebay/api_subscriptions.php">Notification API</a>
            <a class="button button-secondary" href="/ebay/ebay.php?challenge_code=123">Webhook check</a>
          </div>
          <div class="nav-strip">
            ' . $this->renderPrimaryNav($page) . '
          </div>
          ' . $notices . '
        </div>
        <div class="metric-grid">
          <div class="metric">
            <div class="metric-label">Store status</div>
            <div class="metric-value">' . htmlspecialchars($storeHeadline, ENT_QUOTES, 'UTF-8') . '</div>
            <div class="metric-note">Store visibility comes from the live Stores API. If you do not pay for an eBay Store, the console says so directly.</div>
          </div>
          <div class="metric">
            <div class="metric-label">Listings visible</div>
            <div class="metric-value">' . (int) (($traditionalListings['body']['total'] ?? $inventory['body']['total'] ?? $inventory['body']['size'] ?? 0)) . '</div>
            <div class="metric-note">Prefers Trading active listings when they exist, because some seller accounts list there before Inventory API reflects them.</div>
          </div>
          <div class="metric">
            <div class="metric-label">Offers visible</div>
            <div class="metric-value">' . (int) (($offers['body']['total'] ?? $offers['body']['size'] ?? 0)) . '</div>
            <div class="metric-note">Useful for seeing which SKUs are actually market-ready.</div>
          </div>
          <div class="metric">
            <div class="metric-label">Campaigns running</div>
            <div class="metric-value">' . $this->countRunningCampaigns((array) (($campaigns['body'] ?? [])['campaigns'] ?? [])) . '</div>
            <div class="metric-note">Marketing data comes from the live Promoted Listings endpoint.</div>
          </div>
        </div>
      </div>
    </section>
    ' . $pageContent . '
  </div>' . ($page === 'listings' && $hasSelectedCjProduct ? '
  <button class="scroll-detail-button" id="scroll-detail-button" type="button">Scroll to product</button>' : '') . '
  <div class="async-overlay" id="async-overlay" aria-hidden="true">
    <div class="async-card">
      <div class="async-head">
        <h3 class="async-title" id="async-title">Working</h3>
        <div class="async-spinner" id="async-spinner"></div>
      </div>
      <div class="async-note" id="async-note">Your request is being sent to eBay and CJ.</div>
      <div class="progress-track"><div class="progress-fill" id="async-progress-fill"></div></div>
      <div class="progress-meta">
        <span id="async-progress-label">Preparing request...</span>
        <strong id="async-progress-value">0%</strong>
      </div>
    </div>
  </div>
  <script>
    const asyncUi = {
      timer: null,
      progress: 0,
    };

    function updateBulkSelectionCount() {
      const checked = document.querySelectorAll(".listing-selector:checked").length;
      const target = document.getElementById("bulk-selection-count");
      if (target) {
        target.textContent = checked + " selected";
      }
    }
    function toggleBulkListingSelection(checked) {
      document.querySelectorAll(".listing-selector").forEach((el) => {
        el.checked = checked;
      });
      updateBulkSelectionCount();
    }
    function toggleCjBulkSelection(checked) {
      document.querySelectorAll(".cj-bulk-selector").forEach((el) => {
        el.checked = checked;
      });
    }
    function showAsyncOverlay(title, note, withProgress) {
      const overlay = document.getElementById("async-overlay");
      const titleEl = document.getElementById("async-title");
      const noteEl = document.getElementById("async-note");
      const fillEl = document.getElementById("async-progress-fill");
      const valueEl = document.getElementById("async-progress-value");
      const labelEl = document.getElementById("async-progress-label");
      if (!overlay || !titleEl || !noteEl || !fillEl || !valueEl || !labelEl) {
        return;
      }
      titleEl.textContent = title;
      noteEl.textContent = note;
      labelEl.textContent = withProgress ? "Starting..." : "Updating...";
      asyncUi.progress = 0;
      fillEl.style.width = "0%";
      valueEl.textContent = "0%";
      overlay.classList.add("active");
      overlay.setAttribute("aria-hidden", "false");
      if (asyncUi.timer) {
        window.clearInterval(asyncUi.timer);
      }
      if (withProgress) {
        asyncUi.timer = window.setInterval(() => {
          const next = asyncUi.progress < 82 ? asyncUi.progress + 4 : asyncUi.progress < 94 ? asyncUi.progress + 1 : asyncUi.progress;
          setAsyncProgress(next, next < 100 ? "Listing to eBay..." : "Finishing...");
        }, 220);
      }
    }
    function setAsyncProgress(value, label) {
      const fillEl = document.getElementById("async-progress-fill");
      const valueEl = document.getElementById("async-progress-value");
      const labelEl = document.getElementById("async-progress-label");
      asyncUi.progress = Math.max(0, Math.min(100, value));
      if (fillEl) {
        fillEl.style.width = asyncUi.progress + "%";
      }
      if (valueEl) {
        valueEl.textContent = Math.round(asyncUi.progress) + "%";
      }
      if (labelEl && label) {
        labelEl.textContent = label;
      }
    }
    function hideAsyncOverlay() {
      const overlay = document.getElementById("async-overlay");
      if (asyncUi.timer) {
        window.clearInterval(asyncUi.timer);
        asyncUi.timer = null;
      }
      if (overlay) {
        overlay.classList.remove("active");
        overlay.setAttribute("aria-hidden", "true");
      }
    }
    async function refreshAppShell(url, flashType, flashMessage) {
      const response = await fetch(url, {
        headers: {
          "X-Requested-With": "XMLHttpRequest"
        }
      });
      if (!response.ok) {
        throw new Error("Could not refresh the updated workspace.");
      }
      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, "text/html");
      const nextShell = doc.getElementById("app-shell");
      const currentShell = document.getElementById("app-shell");
      if (!nextShell || !currentShell) {
        window.location.href = url;
        return;
      }
      currentShell.innerHTML = nextShell.innerHTML;
      const title = doc.querySelector("title");
      if (title) {
        document.title = title.textContent || document.title;
      }
      window.history.replaceState({}, "", url);
      if (flashMessage) {
        injectAsyncNotice(flashType || "ok", flashMessage);
      }
      initEbayUi();
    }
    function injectAsyncNotice(type, message) {
      const hero = document.querySelector("#app-shell .hero");
      if (!hero || !message) {
        return;
      }
      const notice = document.createElement("div");
      notice.className = "notice " + (type === "warn" ? "warn" : "ok");
      notice.textContent = message;
      hero.appendChild(notice);
    }
    function initMarkupBuilder(scope) {
      (scope || document).querySelectorAll("[data-markup-builder]").forEach((builder) => {
        if (builder.dataset.bound === "true") {
          return;
        }
        builder.dataset.bound = "true";
        const list = builder.querySelector("[data-markup-tier-list]");
        const addButton = builder.querySelector("[data-add-markup-tier]");
        if (!list || !addButton) {
          return;
        }
        addButton.addEventListener("click", () => {
          const row = document.createElement("div");
          row.className = "markup-tier-row";
          row.innerHTML = `
            <label>Apply when base price is <=
              <input type="number" step="0.01" name="markup_thresholds[]" placeholder="5.00">
            </label>
            <label>Markup %
              <input type="number" step="0.01" name="markup_percents[]" placeholder="122">
            </label>
            <button class="button button-secondary markup-tier-remove" type="button" data-remove-markup-tier>&times;</button>
          `;
          list.appendChild(row);
          refreshCjMarkupPrices(builder.closest("form") || document);
        });
        builder.addEventListener("click", (event) => {
          const trigger = event.target instanceof HTMLElement ? event.target.closest("[data-remove-markup-tier]") : null;
          if (!trigger) {
            return;
          }
          const row = trigger.closest(".markup-tier-row");
          if (row && list.children.length > 1) {
            row.remove();
            refreshCjMarkupPrices(builder.closest("form") || document);
          }
        });
      });
    }
    function parseMoneyValue(value) {
      const cleaned = String(value || "").replace(/,/g, "").match(/\d+(?:\.\d+)?/);
      return cleaned ? Number(cleaned[0]) : 0;
    }
    function formatMoneyValue(value) {
      if (!Number.isFinite(value) || value <= 0) {
        return "";
      }
      return value.toFixed(2);
    }
    function readMarkupRules(form) {
      const defaultInput = form.querySelector("input[name=\"markup_default_percent\"], input[name=\"markup_percent\"]");
      const defaultPercent = defaultInput instanceof HTMLInputElement ? parseMoneyValue(defaultInput.value) : 55;
      const thresholds = Array.from(form.querySelectorAll("input[name=\"markup_thresholds[]\"]"));
      const percents = Array.from(form.querySelectorAll("input[name=\"markup_percents[]\"]"));
      const rules = [];
      const count = Math.max(thresholds.length, percents.length);
      for (let index = 0; index < count; index += 1) {
        const thresholdInput = thresholds[index];
        const percentInput = percents[index];
        const threshold = thresholdInput instanceof HTMLInputElement ? parseMoneyValue(thresholdInput.value) : 0;
        const percent = percentInput instanceof HTMLInputElement ? parseMoneyValue(percentInput.value) : 0;
        if (threshold > 0) {
          rules.push({ threshold, percent });
        }
      }
      rules.sort((left, right) => left.threshold - right.threshold);
      return { defaultPercent, rules };
    }
    function resolveMarkupPercent(basePrice, defaultPercent, rules) {
      for (const rule of rules) {
        if (basePrice <= rule.threshold) {
          return rule.percent;
        }
      }
      return defaultPercent;
    }
    function calculateMarkedUpPrice(basePrice, form) {
      if (!Number.isFinite(basePrice) || basePrice <= 0) {
        return "";
      }
      const { defaultPercent, rules } = readMarkupRules(form);
      const percent = resolveMarkupPercent(basePrice, defaultPercent, rules);
      return formatMoneyValue(basePrice * (1 + percent / 100));
    }
    function updateCjPriceDisplay(editor, price) {
      const display = editor.querySelector("[data-cj-price-display]");
      if (display) {
        if (display instanceof HTMLInputElement) {
          display.value = price || "";
        } else {
          display.textContent = price ? "$" + price : "$Set price";
        }
      }
    }
    function refreshCjMarkupPrices(scope) {
      const root = scope || document;
      const editors = [];
      if (root instanceof Element && root.matches("[data-cj-price-editor]")) {
        editors.push(root);
      }
      root.querySelectorAll("[data-cj-price-editor]").forEach((editor) => editors.push(editor));
      editors.forEach((editor) => {
        const input = editor.querySelector("[data-cj-price-input]");
        if (!(input instanceof HTMLInputElement) || input.dataset.custom === "true") {
          return;
        }
        const form = editor.closest("form") || document;
        const basePrice = parseMoneyValue(editor.getAttribute("data-base-price") || "");
        const nextPrice = calculateMarkedUpPrice(basePrice, form);
        if (nextPrice !== "") {
          input.value = nextPrice;
          updateCjPriceDisplay(editor, nextPrice);
        }
      });
    }
    function initCjPriceEditors(scope) {
      (scope || document).querySelectorAll("[data-cj-price-editor]").forEach((editor) => {
        if (editor.dataset.bound === "true") {
          return;
        }
        editor.dataset.bound = "true";
        const input = editor.querySelector("[data-cj-price-input]");
        const status = editor.querySelector("[data-cj-price-status]");
        const pid = editor.getAttribute("data-pid") || "";
        const storageKey = pid ? "cj-ebay-price-" + pid : "";
        if (input instanceof HTMLInputElement && storageKey) {
          const saved = window.localStorage.getItem(storageKey);
          if (saved && parseMoneyValue(saved) > 0) {
            const savedPrice = formatMoneyValue(parseMoneyValue(saved));
            input.value = savedPrice;
            input.dataset.custom = "true";
            updateCjPriceDisplay(editor, savedPrice);
            if (status) {
              status.textContent = "Saved";
            }
          }
          input.addEventListener("input", () => {
            input.dataset.custom = "true";
            updateCjPriceDisplay(editor, formatMoneyValue(parseMoneyValue(input.value)));
            if (status) {
              status.textContent = "Edited";
            }
          });
        }
        editor.addEventListener("click", (event) => {
          const target = event.target instanceof HTMLElement ? event.target : null;
          if (!target || !(input instanceof HTMLInputElement)) {
            return;
          }
          if (target.closest("[data-save-cj-price]")) {
            const price = formatMoneyValue(parseMoneyValue(input.value));
            if (price === "") {
              if (status) {
                status.textContent = "Enter a valid price";
              }
              return;
            }
            input.value = price;
            input.dataset.custom = "true";
            updateCjPriceDisplay(editor, price);
            if (storageKey) {
              window.localStorage.setItem(storageKey, price);
            }
            if (status) {
              status.textContent = "Saved";
            }
          }
          if (target.closest("[data-reset-cj-price]")) {
            input.dataset.custom = "false";
            if (storageKey) {
              window.localStorage.removeItem(storageKey);
            }
            refreshCjMarkupPrices(editor);
            if (status) {
              status.textContent = "Reset";
            }
          }
        });
      });
      (scope || document).querySelectorAll("form").forEach((form) => {
        if (form.dataset.cjPriceBound === "true") {
          return;
        }
        form.dataset.cjPriceBound = "true";
        form.addEventListener("input", (event) => {
          const target = event.target instanceof HTMLInputElement ? event.target : null;
          if (!target || !["markup_default_percent", "markup_percent", "markup_thresholds[]", "markup_percents[]"].includes(target.name)) {
            return;
          }
          refreshCjMarkupPrices(form);
        });
      });
      refreshCjMarkupPrices(scope || document);
    }
    function initAsyncForms() {
      document.addEventListener("submit", async (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || form.dataset.asyncBound === "skip") {
          return;
        }
        const action = form.getAttribute("action") || "";
        if ((form.method || "").toLowerCase() !== "post" || (!action.includes("action=manage") && !action.endsWith("/ebay/manage"))) {
          return;
        }
        event.preventDefault();
        const manageActionInput = form.querySelector("input[name=\"manage_action\"]");
        const manageAction = manageActionInput instanceof HTMLInputElement ? manageActionInput.value : "";
        const isListingAction = manageAction === "import_cj_product_to_ebay" || manageAction === "bulk_import_cj_product_to_ebay";
        const isInventorySync = manageAction === "bulk_sync_cj_inventory";
        const button = form.querySelector("button[type=\"submit\"]");
        if (button instanceof HTMLButtonElement) {
          button.disabled = true;
        }
        showAsyncOverlay(
          isListingAction ? "Publishing listing" : (isInventorySync ? "Syncing inventory" : "Updating workspace"),
          isListingAction ? "Preparing your CJ-to-eBay listing request with free shipping and markup rules." : (isInventorySync ? "Reading CJ inventory and updating mapped eBay quantities." : "Sending your request and refreshing only the affected workspace."),
          isListingAction || isInventorySync
        );
        try {
          const payload = new FormData(form);
          const response = await fetch(action, {
            method: "POST",
            body: payload,
            headers: {
              "X-Requested-With": "XMLHttpRequest",
              "Accept": "application/json"
            }
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || !data.ok) {
            throw new Error(data.message || "The request could not be completed.");
          }
          setAsyncProgress(100, "Refreshing workspace...");
          await refreshAppShell(data.redirect || window.location.href, "ok", data.message || "Update completed.");
        } catch (error) {
          const message = error instanceof Error ? error.message : "The request could not be completed.";
          injectAsyncNotice("warn", message);
        } finally {
          hideAsyncOverlay();
          if (button instanceof HTMLButtonElement) {
            button.disabled = false;
          }
        }
      });
    }
    function initEbayUi() {
      updateBulkSelectionCount();
      initMarkupBuilder(document);
      initCjPriceEditors(document);
      const scrollButton = document.getElementById("scroll-detail-button");
      if (scrollButton) {
        scrollButton.onclick = () => {
          const panel = document.getElementById("cj-product-detail-panel");
          if (panel) {
            panel.scrollIntoView({ behavior: "smooth", block: "start" });
          }
        };
      }
    }
    initAsyncForms();
    initEbayUi();
  </script>
</body>
</html>';
    }

    private function currentPage(): string
    {
        $requested = trim((string) ($_GET['page'] ?? ''));
        if ($requested === '' && trim((string) ($_GET['listing_id'] ?? '')) !== '') {
            $requested = 'listing-detail';
        }
        if ($requested === '' && trim((string) ($_GET['order_id'] ?? '')) !== '') {
            $requested = 'orders';
        }
        if ($requested === '') {
            $requested = 'dashboard';
        }

        $allowed = [
            'dashboard',
            'listings',
            'listing-detail',
            'create-listing',
            'offers',
            'orders',
            'marketing',
            'policies',
            'notifications',
            'integrations',
        ];

        return in_array($requested, $allowed, true) ? $requested : 'dashboard';
    }

    private function isAsyncRequest(): bool
    {
        $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private function respondManageActionJson(bool $ok, string $message, string $redirect, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'redirect' => $redirect,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string, scalar|null> $params */
    private function appUrl(array $params = []): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = (string) $value;
        }

        $query = http_build_query($filtered);
        return '/ebay/index.php' . ($query !== '' ? '?' . $query : '');
    }

    private function manageUrl(): string
    {
        return $this->appUrl(['action' => 'manage']);
    }

    private function defaultCjWebhookUrl(): string
    {
        $base = rtrim($this->config->appBaseUrl, '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $scheme = trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
            }
            $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }

        return $base . '/ebay/cj-webhook.php';
    }

    private function renderPrimaryNav(string $currentPage): string
    {
        $activePage = $currentPage === 'create-listing' || $currentPage === 'listing-detail' ? 'listings' : $currentPage;
        $items = [
            'dashboard' => ['label' => 'Dashboard', 'url' => $this->appUrl(['page' => 'dashboard'])],
            'listings' => ['label' => 'Listings', 'url' => $this->appUrl(['page' => 'listings'])],
            'offers' => ['label' => 'Offers', 'url' => $this->appUrl(['page' => 'offers'])],
            'orders' => ['label' => 'Orders', 'url' => $this->appUrl(['page' => 'orders'])],
            'marketing' => ['label' => 'Marketing', 'url' => $this->appUrl(['page' => 'marketing'])],
            'policies' => ['label' => 'Policies', 'url' => $this->appUrl(['page' => 'policies'])],
            'notifications' => ['label' => 'Notifications', 'url' => $this->appUrl(['page' => 'notifications'])],
            'integrations' => ['label' => 'Integrations', 'url' => $this->appUrl(['page' => 'integrations'])],
        ];

        $html = '';
        foreach ($items as $key => $item) {
            $html .= '<a class="nav-link' . ($key === $activePage ? ' active' : '') . '" href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        return $html;
    }

    /** @param list<array{eyebrow:string,title:string,text:string,href:string,cta:string}> $cards */
    private function renderWorkspaceCards(array $cards): string
    {
        $html = '<div class="workspace-grid">';
        foreach ($cards as $card) {
            $html .= '<article class="workspace-card">
              <div class="workspace-eyebrow">' . htmlspecialchars($card['eyebrow'], ENT_QUOTES, 'UTF-8') . '</div>
              <h3>' . htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') . '</h3>
              <p>' . htmlspecialchars($card['text'], ENT_QUOTES, 'UTF-8') . '</p>
              <a href="' . htmlspecialchars($card['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($card['cta'], ENT_QUOTES, 'UTF-8') . '</a>
            </article>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string, mixed> $inventory @param array<string, mixed> $traditionalListings */
    private function renderInventoryVsTradingNote(array $inventory, array $traditionalListings): string
    {
        $inventoryTotal = (int) (($inventory['body']['total'] ?? $inventory['body']['size'] ?? 0));
        $tradingTotal = (int) (($traditionalListings['body']['total'] ?? 0));

        if ($inventoryTotal === 0 && $tradingTotal > 0) {
            return '<div class="notice warn">Your account has active listings in the regular listing view, but the modern Inventory API currently returns zero items. That is possible on eBay and usually means these listings were created or maintained outside the Inventory API workflow.</div>';
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function loadSelectedListing(): ?array
    {
        $itemId = trim((string) ($_GET['listing_id'] ?? ''));
        if ($itemId === '') {
            return null;
        }

        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            return ['error' => 'No user token available for listing detail lookup.'];
        }

        try {
            return $this->tradingService->getListing($token, $itemId);
        } catch (Throwable $throwable) {
            return ['error' => $throwable->getMessage()];
        }
    }

    /** @return array<string, mixed>|null */
    private function loadSelectedOrder(): ?array
    {
        $orderId = trim((string) ($_GET['order_id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            return ['error' => 'No user token available for order lookup.'];
        }

        try {
            $result = $this->apiClient->request('GET', '/sell/fulfillment/v1/order/' . rawurlencode($orderId), $token);
            $order = (array) ($result['body'] ?? []);
            $fulfillments = $this->apiClient->request('GET', '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment', $token);
            $order['_shippingFulfillments'] = (array) (($fulfillments['body'] ?? [])['fulfillments'] ?? []);
            return $order;
        } catch (Throwable $throwable) {
            return ['error' => $throwable->getMessage()];
        }
    }

    /** @param list<array<string, mixed>> $listings @return list<array<string, mixed>> */
    private function buildTradingListingCards(string $token, array $listings): array
    {
        $cards = [];
        foreach ($listings as $listing) {
            $itemId = (string) ($listing['itemId'] ?? '');
            if ($itemId === '') {
                continue;
            }

            $detail = [];
            try {
                $detail = $this->tradingService->getListing($token, $itemId);
            } catch (Throwable) {
                $detail = [];
            }

            $item = isset($detail['Item']) && is_array($detail['Item']) ? (array) $detail['Item'] : $detail;
            $pictures = $this->normalizeStrings($item['PictureDetails']['PictureURL'] ?? []);
            $specifics = $this->normalizeNameValueList($item['ItemSpecifics']['NameValueList'] ?? []);
            $cards[] = [
                'itemId' => $itemId,
                'title' => (string) ($listing['title'] ?? $item['Title'] ?? ''),
                'sku' => (string) ($listing['sku'] ?? $item['SKU'] ?? ''),
                'price' => (string) ($listing['currentPrice'] ?? $this->formatTradingPrice($item['StartPrice'] ?? '')),
                'available' => (string) ($listing['quantityAvailable'] ?? $item['QuantityAvailable'] ?? $item['Quantity'] ?? 0),
                'watchers' => (string) ($listing['watchCount'] ?? 0),
                'image' => $pictures[0] ?? '',
                'condition' => (string) ($item['ConditionDisplayName'] ?? $item['ConditionID'] ?? ''),
                'specifics' => $this->safeArraySlice($specifics, 0, 3, true),
                'variationCount' => count($this->normalizeVariations($item['Variations']['Variation'] ?? [])),
            ];
        }
        return $cards;
    }

    /** @return array<string, string>|null */
    private function consumeFlash(): ?array
    {
        $flash = $_SESSION['ebay_flash'] ?? null;
        unset($_SESSION['ebay_flash']);
        return is_array($flash) ? $flash : null;
    }

    /** @return list<array<string, mixed>> */
    private function consumeRecentExportFiles(): array
    {
        $files = $_SESSION['ebay_recent_export_files'] ?? [];
        unset($_SESSION['ebay_recent_export_files']);
        return is_array($files) ? array_values(array_filter($files, 'is_array')) : [];
    }

    /** @param list<array<string, mixed>> $files */
    private function renderExportDownloadNotice(array $files): string
    {
        $buttons = '';
        foreach ($files as $file) {
            $relative = trim((string) ($file['relative'] ?? ''));
            if ($relative === '') {
                continue;
            }
            $label = trim((string) ($file['target'] ?? 'export'));
            $label = str_replace('_', ' ', $label);
            $rows = (int) ($file['rows'] ?? 0);
            $text = ucwords($label) . ($rows > 0 ? ' (' . $rows . ')' : '');
            $href = $this->exportDownloadUrl($relative);
            $buttons .= '<a class="button button-secondary" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        if ($buttons === '') {
            return '';
        }

        return '<div class="notice ok"><strong>Export files ready.</strong><div class="actions" style="margin-top:10px">' . $buttons . '</div></div>';
    }

    private function renderRecentMarketplaceExports(): string
    {
        $dir = $this->resolveMarketplaceExportDirectory();
        $files = glob(rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'cj-marketplace-export-*.*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        if ($files === []) {
            return '';
        }

        usort($files, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $buttons = '';
        foreach ($this->safeArraySlice($files, 0, 12) as $file) {
            $relative = $this->relativeExportPath((string) $file);
            if ($relative === '') {
                continue;
            }
            $name = basename((string) $file);
            $buttons .= '<a class="button button-secondary" href="' . htmlspecialchars($this->exportDownloadUrl($relative), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($this->truncateText($name, 42), ENT_QUOTES, 'UTF-8') . '</a>';
        }

        if ($buttons === '') {
            return '';
        }

        return '<details class="compact-options" style="margin-bottom:12px"><summary>Recent exports</summary><div class="toolbar-row">' . $buttons . '</div></details>';
    }

    private function relativeExportPath(string $path): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? str_replace('\\', '/', substr($path, strlen($root))) : str_replace('\\', '/', $path);
    }

    private function exportDownloadUrl(string $relative): string
    {
        return '/ebay/export/download?file=' . rawurlencode($relative);
    }

    private function resolveMarketplaceExportDirectory(): string
    {
        $dir = trim(Env::get('MARKETPLACE_EXPORT_DIR', 'database/exports'));
        if ($dir === '') {
            $dir = 'database/exports';
        }
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $dir) !== 1 && !str_starts_with($dir, DIRECTORY_SEPARATOR)) {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** @return array<string, mixed> */
  private function loadCjWorkspace(): array
{
    $tokenSource = 'none';
    $accessToken = $this->resolveCjAccessToken($tokenSource);

    $workspace = [
        'connected' => $accessToken !== '',
        'token_source' => $tokenSource,
        'products' => [],
        'selectedProduct' => null,
        'settings' => null,
        'recentOrders' => [],
        'recentOrderMaps' => [],
        'recentMappings' => [],
        'lowStockAlerts' => [],
        'lowStockThreshold' => 2,
        'mappingCount' => 0,
        'categoryHierarchy' => [],
        'categoryMap' => [],
        'categoryOptions' => [],
        'cjPage' => 1,
        'cjPageSize' => 20,
        'cjSort' => 'desc',
        'cjTotal' => 0,
        'marketSampleSize' => 10,
        'ebayCategorySuggestions' => [],
        'ebayConditionOptions' => [],
        'ebayAspectRequirements' => [],
        'ebayAspectTemplateValues' => [],
        'selectedEbayCategoryId' => '',
        'error' => '',
        'searchError' => '',
    ];

    // Always load repository data.
    $workspace['mappingCount'] = $this->cjMappingRepository->countListingMaps();
    $workspace['recentMappings'] = $this->cjMappingRepository->listRecentListingMaps(8);
    $workspace['recentOrderMaps'] = $this->cjMappingRepository->listRecentOrderMaps(12);

    // Load CJ settings if token is available.
    if ($accessToken !== '') {
        try {
            $workspace['settings'] = $this->cjService->getSettings($accessToken);
        } catch (\Throwable $throwable) {
            $workspace['error'] = $throwable->getMessage();
        }
    }

    $currentPage = $this->currentPage();

    // Only continue on relevant pages.
    if (!in_array($currentPage, ['listings', 'integrations', 'dashboard', 'orders'], true)) {
        return $workspace;
    }

    // Stop if not connected to CJ.
    if ($accessToken === '') {
        return $workspace;
    }

    // Read request parameters.
    $pid = trim((string) ($_GET['cj_pid'] ?? ''));
    $query = trim((string) ($_GET['cj_query'] ?? ''));
    $categoryName = trim((string) ($_GET['cj_category'] ?? ''));
    $categoryId = trim((string) ($_GET['cj_category_id'] ?? ''));
    $countryCode = strtoupper(trim((string) ($_GET['cj_country'] ?? 'US')));
    $searchRequested = $query !== '' || $categoryName !== '' || $categoryId !== '' || $pid !== '';

    $page = max(1, (int) ($_GET['cj_page'] ?? 1));
    $marketSampleSize = $this->resolveMarketSampleSizeFromRequest();

    $pageSize = (int) ($_GET['cj_page_size'] ?? 20);
    if ($pageSize < 1 || $pageSize > 100) {
        $pageSize = 20;
    }
    $sort = strtolower(trim((string) ($_GET['cj_sort'] ?? 'desc')));
    if (!in_array($sort, ['asc', 'desc'], true)) {
        $sort = 'desc';
    }
    $workspace['cjPageSize'] = $pageSize;
    $workspace['cjSort'] = $sort;
    $workspace['marketSampleSize'] = $marketSampleSize;

    try {
        /*
        |--------------------------------------------------------------------------
        | Load category data
        |--------------------------------------------------------------------------
        */
        $workspace['categoryHierarchy'] = $this->getCjCategoriesHierarchy($accessToken);
        $workspace['categoryMap'] = $this->getCjCategoriesMap($accessToken);
        $workspace['categoryOptions'] = $this->buildCjCategoryOptions(
            (array) $workspace['categoryMap'],
            $categoryName
        );

        if ($categoryId === '' && $categoryName !== '') {
            $categoryId = $this->resolveCjCategoryId(
                $categoryName,
                (array) $workspace['categoryMap']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load products and selected product
        |--------------------------------------------------------------------------
        */
        if ($query !== '' || $categoryId !== '' || $pid !== '') {
            // Load selected product detail.
            if ($pid !== '') {
                $workspace['selectedProduct'] = $this->loadCjProductDetail(
                    $accessToken,
                    $pid,
                    $countryCode
                );
            }

            // Build CJ list parameters.
            $listParams = [
                'page' => (string) $page,
                'size' => (string) $pageSize,
                'countryCode' => $countryCode,
            ];

            if ($query !== '') {
                $listParams['keyWord'] = $query;
            }

            if ($categoryId !== '') {
                $listParams['categoryId'] = $categoryId;
            }

            // Sort: desc = newest first, asc = oldest first.
            $listParams['sort'] = $sort;

            // Load products.
            $listResponse = $this->cjService->listProducts($accessToken, $listParams);
            $data = is_array($listResponse['data'] ?? null)
                ? (array) $listResponse['data']
                : [];

            $workspace['products'] = $this->normalizeCjProductList(
                $data,
                $workspace['categoryMap']
            );
            $workspace['products'] = $this->applyEbayMarketPricingToCjProducts(
                (array) $workspace['products'],
                $marketSampleSize
            );

            $workspace['cjPage'] = $page;
            $workspace['cjPageSize'] = $pageSize;
            $workspace['cjTotal'] = (int) ($data['totalRecords'] ?? $data['total'] ?? 0);
        }

        if (is_array($workspace['selectedProduct'])) {
            $workspace['selectedProduct'] = $this->applyEbayMarketPricingToSelectedCjProduct(
                (array) $workspace['selectedProduct'],
                $marketSampleSize
            );
        }

        /*
        |--------------------------------------------------------------------------
        | eBay category suggestions
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | The original parse error occurred because this block was placed
        | outside of the method. It must be inside the try block or inside
        | the function body.
        |--------------------------------------------------------------------------
        */
        $suggestionSeeds = [];
        if (is_array($workspace['selectedProduct'])) {
            $selectedProduct = (array) $workspace['selectedProduct'];
            $suggestionSeeds[] = $this->extractCjProductTitle($selectedProduct);
            $suggestionSeeds[] = (string) ($selectedProduct['categoryName'] ?? '');
        }
        if ($query !== '') {
            $suggestionSeeds[] = $query;
        }
        foreach ($this->safeArraySlice((array) ($workspace['products'] ?? []), 0, 8) as $product) {
            if (is_array($product)) {
                $suggestionSeeds[] = (string) ($product['title'] ?? '');
                $suggestionSeeds[] = (string) ($product['category'] ?? '');
            }
        }
        $categoryLabel = $categoryId !== '' ? (string) (((array) $workspace['categoryMap'])[$categoryId] ?? '') : '';
        foreach ([$categoryLabel, $categoryName] as $categorySeed) {
            $categorySeed = trim((string) $categorySeed);
            if ($categorySeed === '') {
                continue;
            }
            $suggestionSeeds[] = $categorySeed;
            $parts = preg_split('/\s*>\s*/', $categorySeed) ?: [];
            $leaf = trim((string) end($parts));
            if ($leaf !== '' && $leaf !== $categorySeed) {
                $suggestionSeeds[] = $leaf;
            }
        }

        if ($suggestionSeeds !== []) {
            $workspace['ebayCategorySuggestions'] =
                $this->loadEbayCategorySuggestionsFromSeeds($suggestionSeeds);

            $workspace['selectedEbayCategoryId'] =
                (string) ($workspace['ebayCategorySuggestions'][0]['id'] ?? '');

            $selectedCategoryId = trim(
                (string) (
                    $_GET['ebay_category_id']
                    ?? $_POST['ebay_category_id']
                    ?? $workspace['selectedEbayCategoryId']
                )
            );
            if (
                $selectedCategoryId !== ''
                && !$this->hasEbayCategorySuggestion((array) $workspace['ebayCategorySuggestions'], $selectedCategoryId)
            ) {
                $selectedCategoryId = (string) ($workspace['selectedEbayCategoryId'] ?? '');
            }

            if ($selectedCategoryId !== '') {
                $workspace['selectedEbayCategoryId'] = $selectedCategoryId;

                $workspace['ebayConditionOptions'] =
                    $this->loadEbayConditionOptions($selectedCategoryId);

                $workspace['ebayAspectRequirements'] =
                    $this->loadEbayAspectRequirements($selectedCategoryId);

                $workspace['ebayAspectTemplateValues'] =
                    $this->loadSavedAspectTemplateValues($selectedCategoryId);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Recent CJ orders
        |--------------------------------------------------------------------------
        */
        if (in_array($currentPage, ['integrations', 'orders'], true)) {
            $ordersResponse = $this->cjService->listOrders(
                $accessToken,
                [
                    'pageNum' => '1',
                    'pageSize' => '6',
                ]
            );

            $workspace['recentOrders'] = $this->normalizeCjOrderList(
                $ordersResponse['data'] ?? []
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Low stock alerts
        |--------------------------------------------------------------------------
        */
        if ($currentPage === 'integrations') {
            $workspace['lowStockAlerts'] = $this->loadLowStockAlerts(
                $accessToken,
                (array) $workspace['recentMappings'],
                $countryCode,
                (int) $workspace['lowStockThreshold']
            );
        }
    } catch (\Throwable $throwable) {
        $workspace['error'] = $throwable->getMessage();
        if ($searchRequested) {
            $workspace['searchError'] = $throwable->getMessage();
        }
    }

    return $workspace;
}

    /**
     * @param list<array<string, mixed>> $mappings
     * @return list<array<string, mixed>>
     */
    private function loadLowStockAlerts(string $accessToken, array $mappings, string $countryCode, int $threshold): array
    {
        $threshold = max(0, $threshold);
        $alerts = [];
        foreach ($this->safeArraySlice($mappings, 0, 8) as $map) {
            if (!is_array($map)) {
                continue;
            }

            $pid = trim((string) ($map['cj_pid'] ?? ''));
            $itemId = trim((string) ($map['ebay_item_id'] ?? ''));
            if ($pid === '' || $itemId === '') {
                continue;
            }

            try {
                $product = $this->loadCjProductDetail($accessToken, $pid, $countryCode);
                foreach ($this->normalizeCjVariants($product['_variants'] ?? []) as $variant) {
                    if (!$this->hasCjInventorySignal($variant)) {
                        continue;
                    }

                    $quantity = $this->extractCjVariantQuantity($variant, 0);
                    if ($quantity > $threshold) {
                        continue;
                    }

                    $alerts[] = [
                        'ebay_item_id' => $itemId,
                        'cj_pid' => $pid,
                        'sku' => (string) ($variant['variantSku'] ?? ''),
                        'vid' => (string) ($variant['vid'] ?? ''),
                        'title' => (string) ($map['cj_title'] ?? $this->extractCjProductTitle($product)),
                        'quantity' => $quantity,
                        'source' => (string) ($variant['_inventory_source'] ?? 'CJ inventory'),
                    ];

                    if (count($alerts) >= 16) {
                        return $alerts;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $alerts;
    }

    /** @return list<array{id:string,name:string,path:string,level:int,children:list<array<string,mixed>>}> */
    private function getCjCategoriesHierarchy(string $accessToken): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/database/cj_categories_hierarchy.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400 * 7) {
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($data) && array_is_list($data)) {
                return $data;
            }
        }

        try {
            $res = $this->cjService->getCategory($accessToken);
            $hierarchy = $this->normalizeCjCategoryHierarchy($res['data'] ?? []);
            if ($hierarchy !== []) {
                file_put_contents($cacheFile, json_encode($hierarchy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $hierarchy;
            }
        } catch (Throwable) {
        }

        return [];
    }

    /** @return array<string, string> */
    private function getCjCategoriesMap(string $accessToken): array
    {
        $hierarchy = $this->getCjCategoriesHierarchy($accessToken);
        $hierarchyMap = $this->flattenCjCategoryMap($hierarchy);
        if ($hierarchyMap !== []) {
            file_put_contents(dirname(__DIR__, 2) . '/database/cj_categories.json', json_encode($hierarchyMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $hierarchyMap;
        }

        $cacheFile = dirname(__DIR__, 2) . '/database/cj_categories.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400 * 7) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if (is_array($data)) {
                return array_filter(array_map('strval', $data), static fn (string $value): bool => $value !== '');
            }
        }

        try {
            $res = $this->cjService->getCategory($accessToken);
            $map = $this->flattenCjCategoryMap($this->normalizeCjCategoryHierarchy($res['data'] ?? []));
            if ($map !== []) {
                file_put_contents($cacheFile, json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $map;
            }
        } catch (\Throwable $t) {}
        return [];
    }

    /** @return list<array{id:string,name:string,path:string,level:int,children:list<array<string,mixed>>}> */
    private function normalizeCjCategoryHierarchy(mixed $data, string $parentPath = '', int $level = 0): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            $nodes = [];
            foreach ($data as $entry) {
                $nodes = array_merge($nodes, $this->normalizeCjCategoryHierarchy($entry, $parentPath, $level));
            }
            return $nodes;
        }

        $name = $this->firstNonEmptyString($data, ['categoryName', 'categoryFirstName', 'categorySecondName', 'categoryThirdName', 'name', 'title']);
        $id = $this->firstNonEmptyString($data, ['categoryId', 'categoryFirstId', 'categorySecondId', 'categoryThirdId', 'id']);
        $children = $this->extractCjCategoryChildren($data);

        if ($name === '' && $id === '') {
            $nodes = [];
            foreach ($children as $child) {
                $nodes = array_merge($nodes, $this->normalizeCjCategoryHierarchy($child, $parentPath, $level));
            }
            return $nodes;
        }

        $nodeName = $name !== '' ? $name : $id;
        $path = $parentPath !== '' ? $parentPath . ' > ' . $nodeName : $nodeName;
        return [[
            'id' => $id,
            'name' => $nodeName,
            'path' => $path !== '' ? $path : $id,
            'level' => $level + 1,
            'children' => $this->normalizeCjCategoryHierarchy($children, $path, $level + 1),
        ]];
    }

    /** @return list<mixed> */
    private function extractCjCategoryChildren(array $node): array
    {
        $children = [];
        foreach (['categoryFirstList', 'categorySecondList', 'categoryThirdList', 'children', 'childList', 'categoryList', 'subCategoryList', 'subCategories', 'list', 'data'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                continue;
            }
            foreach ((array) $node[$key] as $child) {
                if (is_array($child)) {
                    $children[] = $child;
                }
            }
        }

        return $children;
    }

    /** @param list<array<string,mixed>> $nodes @return array<string, string> */
    private function flattenCjCategoryMap(array $nodes): array
    {
        $map = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            $path = trim((string) ($node['path'] ?? $node['name'] ?? ''));
            if ($id !== '' && $path !== '') {
                $map[$id] = $path;
            }
            $children = is_array($node['children'] ?? null) ? (array) $node['children'] : [];
            foreach ($this->flattenCjCategoryMap($children) as $childId => $childPath) {
                $map[$childId] = $childPath;
            }
        }

        return $map;
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private function firstNonEmptyString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveCjCategoryId(string $categoryName, array $categoryMap): string
    {
        $needle = strtolower(trim($categoryName));
        if ($needle === '') {
            return '';
        }

        if (isset($categoryMap[$categoryName])) {
            return $categoryName;
        }

        foreach ($categoryMap as $id => $name) {
            $label = strtolower(trim((string) $name));
            $leaf = strtolower(trim((string) preg_replace('/^.*>\s*/', '', (string) $name)));
            if ($label === $needle || $leaf === $needle) {
                return (string) $id;
            }
        }
        foreach ($categoryMap as $id => $name) {
            if (str_contains(strtolower((string) $name), $needle)) {
                return (string) $id;
            }
        }

        return '';
    }

    /** @return list<array{id:string,name:string}> */
    private function buildCjCategoryOptions(array $categoryMap, string $search = ''): array
    {
        $needle = strtolower(trim($search));
        $options = [];
        foreach ($categoryMap as $id => $name) {
            $label = (string) $name;
            if ($needle !== '' && !str_contains(strtolower($label), $needle)) {
                continue;
            }
            $options[] = ['id' => (string) $id, 'name' => $label];
        }
        usort($options, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        return $options;
    }

    /** @return list<array<string, mixed>> */
    private function loadEbayCategorySuggestionsFromSeeds(array $seeds): array
    {
        $seenSeeds = [];
        $seenCategoryIds = [];
        $suggestions = [];
        $seedIndex = 0;
        foreach ($seeds as $seed) {
            $seed = trim((string) $seed);
            if ($seed === '') {
                continue;
            }
            $seedKey = strtolower($seed);
            if (isset($seenSeeds[$seedKey])) {
                continue;
            }
            $seenSeeds[$seedKey] = true;

            $matches = $this->loadEbayCategorySuggestions($seed);
            foreach ($matches as $matchIndex => $match) {
                $id = trim((string) ($match['id'] ?? ''));
                if ($id === '' || isset($seenCategoryIds[$id])) {
                    continue;
                }
                if (($match['leaf'] ?? null) === false) {
                    continue;
                }

                $match['_score'] = $this->scoreEbayCategorySuggestion($match, $seed, $seedIndex, $matchIndex);
                $seenCategoryIds[$id] = true;
                $suggestions[] = $match;
            }

            $seedIndex++;
            if (count($suggestions) >= 50) {
                break;
            }
        }

        usort($suggestions, static function (array $left, array $right): int {
            return ((int) ($right['_score'] ?? 0)) <=> ((int) ($left['_score'] ?? 0));
        });

        return array_values(array_map(static function (array $suggestion): array {
            unset($suggestion['_score']);
            return $suggestion;
        }, $this->safeArraySlice($suggestions, 0, 50)));
    }

    /** @return list<array<string, mixed>> */
    private function loadEbayCategorySuggestions(string $query): array
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '' || trim($query) === '') {
            return [];
        }

        try {
            $result = $this->apiClient->request(
                'GET',
                '/commerce/taxonomy/v1/category_tree/0/get_category_suggestions',
                $token,
                ['q' => $query]
            );
            $suggestions = [];
            foreach ((array) (($result['body'] ?? [])['categorySuggestions'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $category = (array) ($entry['category'] ?? []);
                $id = (string) ($category['categoryId'] ?? '');
                $name = (string) ($category['categoryName'] ?? '');
                if ($id === '' || $name === '') {
                    continue;
                }
                $pathParts = [];
                foreach (array_reverse((array) ($entry['categoryTreeNodeAncestors'] ?? [])) as $ancestor) {
                    if (is_array($ancestor) && isset($ancestor['categoryName'])) {
                        $pathParts[] = (string) $ancestor['categoryName'];
                    }
                }
                $pathParts[] = $name;
                $leafState = $this->extractEbayCategoryLeafStateFromSuggestion($entry);
                $suggestions[] = [
                    'id' => $id,
                    'name' => $name,
                    'path' => implode(' / ', $pathParts),
                    'leaf' => $leafState,
                ];
                if (count($suggestions) >= 20) {
                    break;
                }
            }
            return $suggestions;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $suggestion */
    private function scoreEbayCategorySuggestion(array $suggestion, string $seed, int $seedIndex, int $matchIndex): int
    {
        $haystack = strtolower((string) ($suggestion['path'] ?? $suggestion['name'] ?? ''));
        $words = preg_split('/[^a-z0-9]+/', strtolower($seed)) ?: [];
        $score = 10000 - ($seedIndex * 250) - ($matchIndex * 10);
        foreach ($words as $word) {
            if (strlen($word) < 4) {
                continue;
            }
            if (str_contains($haystack, $word)) {
                $score += 25;
            }
        }
        if (($suggestion['leaf'] ?? null) === true) {
            $score += 500;
        }
        return $score;
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function hasEbayCategorySuggestion(array $suggestions, string $categoryId): bool
    {
        foreach ($suggestions as $suggestion) {
            if (is_array($suggestion) && (string) ($suggestion['id'] ?? '') === $categoryId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $entry */
    private function extractEbayCategoryLeafStateFromSuggestion(array $entry): ?bool
    {
        foreach (['leafCategoryTreeNode', 'isLeafCategory'] as $key) {
            if (array_key_exists($key, $entry)) {
                return $this->normalizeNullableBool($entry[$key]);
            }
        }

        foreach (['categoryTreeNode', 'category'] as $key) {
            if (is_array($entry[$key] ?? null)) {
                $state = $this->extractEbayCategoryLeafStateFromNode((array) $entry[$key]);
                if ($state !== null) {
                    return $state;
                }
            }
        }

        return null;
    }

    private function assertEbayLeafCategoryId(string $categoryId): void
    {
        $leafState = $this->resolveEbayCategoryLeafState($categoryId);
        if ($leafState === false) {
            throw new RuntimeException('The selected eBay category ' . $categoryId . ' is a parent category. Choose a more specific leaf category from the eBay category suggestions before listing.');
        }
    }

    private function resolveEbayCategoryLeafState(string $categoryId): ?bool
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return null;
        }
        if (array_key_exists($categoryId, $this->ebayCategoryLeafStateCache)) {
            return $this->ebayCategoryLeafStateCache[$categoryId];
        }

        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            return $this->ebayCategoryLeafStateCache[$categoryId] = null;
        }

        try {
            $result = $this->apiClient->request(
                'GET',
                '/commerce/taxonomy/v1/category_tree/0/get_category_subtree',
                $token,
                ['category_id' => $categoryId]
            );
            $status = (int) ($result['status'] ?? 500);
            if ($status < 200 || $status >= 300) {
                return $this->ebayCategoryLeafStateCache[$categoryId] = null;
            }

            $body = is_array($result['body'] ?? null) ? (array) $result['body'] : [];
            $state = $this->extractEbayCategoryLeafStateFromNode($body);
            return $this->ebayCategoryLeafStateCache[$categoryId] = $state;
        } catch (Throwable) {
            return $this->ebayCategoryLeafStateCache[$categoryId] = null;
        }
    }

    /** @param array<string, mixed> $node */
    private function extractEbayCategoryLeafStateFromNode(array $node): ?bool
    {
        foreach (['leafCategoryTreeNode', 'isLeafCategory'] as $key) {
            if (array_key_exists($key, $node)) {
                return $this->normalizeNullableBool($node[$key]);
            }
        }

        foreach (['categorySubtreeNode', 'categoryTreeNode'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $state = $this->extractEbayCategoryLeafStateFromNode((array) $node[$key]);
                if ($state !== null) {
                    return $state;
                }
            }
        }

        if (array_key_exists('childCategoryTreeNodes', $node)) {
            return ((array) $node['childCategoryTreeNodes']) === [];
        }

        return null;
    }

    private function normalizeNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        return null;
    }

    /** @return list<array{id:string,name:string}> */
    private function loadEbayConditionOptions(string $categoryId): array
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '' || trim($categoryId) === '') {
            return [];
        }

        try {
            $result = $this->apiClient->request(
                'GET',
                '/sell/metadata/v1/marketplace/EBAY_US/get_item_condition_policies',
                $token,
                ['filter' => 'categoryIds:{' . $categoryId . '}']
            );
            $policies = (array) (($result['body'] ?? [])['itemConditionPolicies'] ?? []);
            $conditions = [];
            foreach ($policies as $policy) {
                if (!is_array($policy)) {
                    continue;
                }
                foreach ((array) ($policy['itemConditions'] ?? []) as $condition) {
                    if (!is_array($condition)) {
                        continue;
                    }
                    $id = (string) ($condition['conditionId'] ?? '');
                    $name = (string) ($condition['conditionDescription'] ?? '');
                    if ($id !== '' && $name !== '') {
                        $conditions[] = ['id' => $id, 'name' => $name];
                    }
                }
            }
            return $conditions;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> */
    private function loadEbayAspectRequirements(string $categoryId): array
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '' || trim($categoryId) === '') {
            return [];
        }

        try {
            $result = $this->apiClient->request(
                'GET',
                '/commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category',
                $token,
                ['category_id' => $categoryId]
            );
            $aspects = [];
            foreach ((array) (($result['body'] ?? [])['aspects'] ?? []) as $aspect) {
                if (!is_array($aspect)) {
                    continue;
                }
                $name = trim((string) ($aspect['localizedAspectName'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $constraint = is_array($aspect['aspectConstraint'] ?? null) ? (array) $aspect['aspectConstraint'] : [];
                $required = (bool) ($constraint['aspectRequired'] ?? false);
                $mode = strtolower((string) ($constraint['aspectMode'] ?? 'FREE_TEXT'));
                $maxValues = max(1, (int) ($constraint['itemToAspectCardinality'] ?? 1));
                $values = [];
                foreach ((array) ($aspect['aspectValues'] ?? []) as $value) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $label = trim((string) ($value['localizedValue'] ?? ''));
                    if ($label !== '') {
                        $values[] = $label;
                    }
                }

                $aspects[] = [
                    'name' => $name,
                    'required' => $required,
                    'mode' => $mode,
                    'values' => array_values(array_unique($values)),
                    'maxValues' => $maxValues,
                ];
            }

            usort($aspects, static function (array $a, array $b): int {
                return [$b['required'] ? 1 : 0, $a['name']] <=> [$a['required'] ? 1 : 0, $b['name']];
            });

            return $aspects;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function loadSavedAspectTemplateValues(string $categoryId): array
    {
        $row = $this->cjMappingRepository->loadAspectTemplate($categoryId);
        if (!is_array($row)) {
            return [];
        }

        $decoded = json_decode((string) ($row['aspect_values_json'] ?? '{}'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function loadCjProductDetail(string $accessToken, string $pid, string $countryCode): array
    {
        $detail = $this->cjService->getProductDetail($accessToken, [
            'pid' => $pid,
            'countryCode' => $countryCode,
            'features' => 'enable_description,enable_combine,enable_inventory,enable_video',
        ]);
        $variants = $this->cjService->getVariants($accessToken, [
            'pid' => $pid,
            'countryCode' => $countryCode,
        ]);
        $inventory = [];
        try {
            $inventory = $this->cjService->getInventoryByProductId($accessToken, $pid);
        } catch (Throwable) {
            $inventory = [];
        }

        $product = is_array($detail['data'] ?? null) ? (array) $detail['data'] : [];
        if (!isset($product['pid']) || trim((string) $product['pid']) === '') {
            $product['pid'] = $pid;
        }
        $product['_inventory'] = is_array($inventory['data'] ?? null) ? (array) $inventory['data'] : [];
        $product['_variants'] = $this->enrichCjVariantsWithInventory(
            $this->mergeCjVariantSources($product['variants'] ?? [], $variants['data'] ?? []),
            $product
        );
        $product['_variants'] = $this->hydrateMissingCjVariantInventoryBySku($accessToken, $product['_variants']);
        $product['_images'] = $this->collectCjListingImages($product, $product['_variants']);
        return $product;
    }

    private function resolveCjAccessToken(string &$source): string
    {
        if ($this->config->cjAccessToken !== '') {
            $source = 'env_cj_access';
            return $this->config->cjAccessToken;
        }

        if ($this->config->cjRefreshToken !== '') {
            try {
                $token = $this->cjService->refreshAccessToken($this->config->cjRefreshToken);
                $data = is_array($token['data'] ?? null) ? (array) $token['data'] : [];
                $accessToken = (string) ($data['accessToken'] ?? '');
                $refreshToken = (string) ($data['refreshToken'] ?? $this->config->cjRefreshToken);
                if ($accessToken !== '') {
                    $this->persistCjTokensToEnv($accessToken, $refreshToken);
                    $source = 'env_cj_refresh';
                    return $accessToken;
                }
            } catch (Throwable) {
            }
        }

        $source = 'none';
        return '';
    }

    private function persistCjTokensToEnv(string $accessToken, string $refreshToken): void
    {
        $updates = [];
        if ($accessToken !== '') {
            $updates['CJ_ACCESS_TOKEN'] = $accessToken;
        }
        if ($refreshToken !== '') {
            $updates['CJ_REFRESH_TOKEN'] = $refreshToken;
        }
        if ($updates !== []) {
            $this->updateEnvFile($updates);
        }
    }

    private function renderBulkListingActionPanel(array $cards): string
    {
        if ($cards === []) {
            return '<div class="empty">No active listings are available for bulk ending right now.</div>';
        }

        $currentPage = max(1, (int) ($_GET['cj_page'] ?? 1));
        $pageSizeQuery = (string) ($_GET['cj_page_size'] ?? '20');
        $sortQuery = (string) ($_GET['cj_sort'] ?? 'desc');

        return '<form id="bulk-end-form" method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="action-stack bulk-toolbar">
          <input type="hidden" name="manage_action" value="bulk_end_regular_listings">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl([
              'page' => 'listings',
              'cj_query' => (string) ($_GET['cj_query'] ?? ''),
              'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
              'cj_category' => (string) ($_GET['cj_category'] ?? ''),
              'cj_category_id' => (string) ($_GET['cj_category_id'] ?? ''),
              'cj_page' => (string) $currentPage,
              'cj_page_size' => $pageSizeQuery,
              'cj_sort' => $sortQuery,
          ]), ENT_QUOTES, 'UTF-8') . '">
          <div class="toolbar-row">
            <div>
              <div class="workspace-eyebrow">Bulk end</div>
              <div class="mini">Select listings directly on the cards below, then end the chosen items together.</div>
            </div>
            <button class="button button-secondary" type="button" onclick="toggleBulkListingSelection(true)">Select all displayed listings</button>
            <button class="button button-secondary" type="button" onclick="toggleBulkListingSelection(false)">Clear</button>
            <label style="min-width:220px">Ending reason
              <select name="ending_reason">
                <option value="NotAvailable">NotAvailable</option>
                <option value="Incorrect">Incorrect</option>
                <option value="LostOrBroken">LostOrBroken</option>
                <option value="SellToHighBidder">SellToHighBidder</option>
              </select>
            </label>
            <div class="badge neutral"><span id="bulk-selection-count">0 selected</span></div>
            <button class="button button-danger" type="submit">Bulk end selected</button>
          </div>
        </form>';
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjAuthPanel(array $workspace): string
    {
        $settingsData = is_array($workspace['settings']['data'] ?? null) ? (array) $workspace['settings']['data'] : [];
        $error = trim((string) ($workspace['error'] ?? ''));

        $status = ($workspace['connected'] ?? false) ? '<span class="badge good">Connected</span>' : '<span class="badge warn">Not connected</span>';
        $note = $error !== '' ? '<div class="notice warn" style="margin-top:12px">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';

        return '<div class="action-stack">
          <div class="subcard">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
              <div>' . $status . '</div>
              <div class="badge neutral">Source: ' . htmlspecialchars((string) ($workspace['token_source'] ?? 'none'), ENT_QUOTES, 'UTF-8') . '</div>
            </div>
            <div class="kv" style="margin-top:12px">
              <div class="kv-item"><div class="kv-key">API key</div><div>' . ($this->config->cjApiKey !== '' ? 'Configured in .env' : 'Missing') . '</div></div>
              <div class="kv-item"><div class="kv-key">Access token</div><div>' . ($this->config->cjAccessToken !== '' ? 'Saved in .env' : 'Not saved yet') . '</div></div>
              <div class="kv-item"><div class="kv-key">Refresh token</div><div>' . ($this->config->cjRefreshToken !== '' ? 'Saved in .env' : 'Not saved yet') . '</div></div>
              <div class="kv-item"><div class="kv-key">Mapped listings</div><div>' . (int) ($workspace['mappingCount'] ?? 0) . ' linked for future CJ sync</div></div>
            </div>
            ' . $note . '
          </div>
          <div class="toolbar-row">
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="cj_authenticate">
              <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'integrations']), ENT_QUOTES, 'UTF-8') . '">
              <button class="button button-primary" type="submit">Connect CJ</button>
            </form>
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="cj_refresh">
              <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'integrations']), ENT_QUOTES, 'UTF-8') . '">
              <button class="button button-secondary" type="submit">Refresh CJ token</button>
            </form>
          </div>
          <div class="subcard">
            <h4 style="margin:0 0 10px">CJ settings snapshot</h4>
            <div class="mini">' . htmlspecialchars($settingsData !== [] ? 'CJ settings endpoint responded successfully for this account.' : 'Settings will appear here after CJ authentication succeeds.', ENT_QUOTES, 'UTF-8') . '</div>
          </div>
        </div>';
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjSearchPanel(array $workspace): string
    {
        $query = (string) ($_GET['cj_query'] ?? '');
        $categoryName = (string) ($_GET['cj_category'] ?? '');
        $countryCode = (string) ($_GET['cj_country'] ?? 'US');
        $pid = (string) ($_GET['cj_pid'] ?? '');
        $pageSize = (int) ($workspace['cjPageSize'] ?? ($_GET['cj_page_size'] ?? 20));
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }
        $sort = (string) ($workspace['cjSort'] ?? ($_GET['cj_sort'] ?? 'desc'));
        if (!in_array($sort, ['asc', 'desc'], true)) {
            $sort = 'desc';
        }
        $marketSampleSize = (int) ($workspace['marketSampleSize'] ?? $this->resolveMarketSampleSizeFromRequest());
        $disabled = !($workspace['connected'] ?? false) ? ' disabled' : '';
        $categoryOptions = '';
        foreach ((array) ($workspace['categoryOptions'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $categoryOptions .= '<option value="' . htmlspecialchars((string) ($option['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '"></option>';
        }

        $categoryTree = $this->renderCjCategoryTree((array) ($workspace['categoryHierarchy'] ?? []));

        return '<form method="get" action="/ebay/index.php" class="action-stack">
          <input type="hidden" name="page" value="listings">
          <div class="form-grid">
            <label>Search name or SKU<input type="text" name="cj_query" value="' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '"' . $disabled . '></label>
            <label>Country<input type="text" name="cj_country" value="' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '" placeholder="US"' . $disabled . '></label>
            <label>Category name<input type="text" list="cj_categories" name="cj_category" value="' . htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') . '" placeholder="Pet supplies, fitness, jewelry..."' . $disabled . '></label>
            <label>Direct PID<input type="text" name="cj_pid" value="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '"' . $disabled . '></label>
            <label>Products per page<select name="cj_page_size"' . $disabled . '>
              <option value="20"' . ($pageSize === 20 ? ' selected' : '') . '>20</option>
              <option value="40"' . ($pageSize === 40 ? ' selected' : '') . '>40</option>
              <option value="60"' . ($pageSize === 60 ? ' selected' : '') . '>60</option>
              <option value="100"' . ($pageSize === 100 ? ' selected' : '') . '>100</option>
            </select></label>
            <label>Sort<select name="cj_sort"' . $disabled . '>
              <option value="desc"' . ($sort === 'desc' ? ' selected' : '') . '>Newest first</option>
              <option value="asc"' . ($sort === 'asc' ? ' selected' : '') . '>Oldest first</option>
            </select></label>
            <label>eBay market sample<select name="market_sample_size"' . $disabled . '>
              <option value="5"' . ($marketSampleSize === 5 ? ' selected' : '') . '>First 5 related listings</option>
              <option value="10"' . ($marketSampleSize === 10 ? ' selected' : '') . '>First 10 related listings</option>
              <option value="15"' . ($marketSampleSize === 15 ? ' selected' : '') . '>First 15 related listings</option>
            </select></label>
          </div>
          <datalist id="cj_categories">' . $categoryOptions . '</datalist>
          <div class="actions">
            <button class="button button-primary" type="submit"' . $disabled . '>Search CJ</button>
            <a class="button button-secondary" href="' . htmlspecialchars($this->appUrl(['page' => 'listings']), ENT_QUOTES, 'UTF-8') . '">Reset</a>
          </div>
        </form>' . $categoryTree;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function renderCjCategoryTree(array $nodes): string
    {
        if ($nodes === []) {
            return '';
        }

        return '<details class="category-tree"><summary>CJ category hierarchy</summary>' . $this->renderCjCategoryTreeNodes($nodes) . '</details>';
    }

    /** @param list<array<string, mixed>> $nodes */
    private function renderCjCategoryTreeNodes(array $nodes): string
    {
        $html = '<ul>';
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string) ($node['name'] ?? $node['path'] ?? ''));
            if ($name === '') {
                continue;
            }
            $children = is_array($node['children'] ?? null) ? (array) $node['children'] : [];
            $html .= '<li><span title="' . htmlspecialchars((string) ($node['path'] ?? $name), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
            if ($children !== []) {
                $html .= $this->renderCjCategoryTreeNodes($children);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjProductGrid(array $workspace): string
    {
        $products = (array) ($workspace['products'] ?? []);
        if ($products === []) {
            $searched = trim((string) ($_GET['cj_query'] ?? $_GET['cj_category'] ?? $_GET['cj_category_id'] ?? $_GET['cj_pid'] ?? '')) !== '';
            $error = trim((string) ($workspace['searchError'] ?? ''));
            if ($error !== '') {
                return '<div class="empty">' . htmlspecialchars('CJ product search failed: ' . $error, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if ($searched) {
                return '<div class="empty">No CJ products were returned for this search.</div>';
            }
            return '<div class="empty">Connect CJ and run a product search to see results here.</div>';
        }
        $currentPage = (int) ($workspace['cjPage'] ?? 1);
        $pageSize = (int) ($workspace['cjPageSize'] ?? 20);
        $total = (int) ($workspace['cjTotal'] ?? 0);
        $totalPages = max(1, (int) ceil($total / max(1, $pageSize)));
        $selectedCategoryId = (string) ($workspace['selectedEbayCategoryId'] ?? '');
        $categorySuggestions = $this->renderEbayCategorySelectOptions((array) ($workspace['ebayCategorySuggestions'] ?? []), $selectedCategoryId);
        $conditionOptions = $this->renderConditionSelectOptions((array) ($workspace['ebayConditionOptions'] ?? []), '1000');
        $pageSizeQuery = (string) ($_GET['cj_page_size'] ?? (string) $pageSize);
        $sortQuery = (string) ($workspace['cjSort'] ?? ($_GET['cj_sort'] ?? 'desc'));
        $marketSampleSize = (int) ($workspace['marketSampleSize'] ?? $this->resolveMarketSampleSizeFromRequest());
        
        // Resolve CJ access token and country code for freight calculation
        $cjTokenSource = 'none';
        $accessToken = $this->resolveCjAccessToken($cjTokenSource);
        $countryCode = strtoupper(trim((string) ($_GET['cj_country'] ?? 'US')));

        $markupBuilder = $this->renderMarkupRuleBuilder('bulk-import');
        $html = '<form id="bulk-import-form" method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="action-stack"><div class="bulk-toolbar" style="margin-bottom:12px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <input id="bulk-manage-action" type="hidden" name="manage_action" value="bulk_import_cj_product_to_ebay">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl([
              'page' => 'listings',
              'cj_query' => (string) ($_GET['cj_query'] ?? ''),
              'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
              'cj_category' => (string) ($_GET['cj_category'] ?? ''),
              'cj_pid' => (string) ($_GET['cj_pid'] ?? ''),
              'cj_page' => (string) $currentPage,
              'cj_page_size' => $pageSizeQuery,
              'cj_sort' => $sortQuery,
              'market_sample_size' => (string) $marketSampleSize,
          ]), ENT_QUOTES, 'UTF-8') . '">
          <input type="hidden" name="cj_country_code" value="' . htmlspecialchars((string) ($_GET['cj_country'] ?? 'US'), ENT_QUOTES, 'UTF-8') . '">
          <button class="button button-secondary" type="button" onclick="toggleCjBulkSelection(true)">Select all displayed CJ products</button>
          <button class="button button-secondary" type="button" onclick="toggleCjBulkSelection(false)">Clear</button>
          <label>eBay category<select name="ebay_category_id" style="min-width:320px; padding:6px;">' . $categorySuggestions . '</select></label>
          <label>Postal Code <input type="text" name="postal_code" value="10001" style="width:80px; padding:6px;"></label>
          <label>Condition <select name="ebay_condition_id" style="padding:6px;">' . $conditionOptions . '</select></label>
          <label>Max eBay qty <input type="number" name="max_listing_quantity" value="5" min="1" max="50" style="width:90px; padding:6px;"></label>
          <details class="compact-options"><summary>Export files</summary>
            <div class="toolbar-row">
              <label><input type="checkbox" name="marketplace_export_targets[]" value="ebay" checked> eBay</label>
              <label><input type="checkbox" name="marketplace_export_targets[]" value="facebook" checked> Facebook</label>
              <label><input type="checkbox" name="marketplace_export_targets[]" value="tiktok" checked> TikTok</label>
              <span class="mini">Categories auto-map per CJ product. eBay uses taxonomy; Facebook/TikTok use detected template categories.</span>
              <label>Google category fallback <input type="text" name="google_product_category" placeholder="Optional ID/path" style="min-width:180px"></label>
              <button class="button button-secondary" type="submit" onclick="document.getElementById(\'bulk-manage-action\').value=\'bulk_export_cj_marketplace_files\'">Export Selected</button>
            </div>
          </details>
          <button class="button button-primary" type="submit" onclick="document.getElementById(\'bulk-manage-action\').value=\'bulk_import_cj_product_to_ebay\'">Bulk List Selected</button>
        </div>' . $this->renderRecentMarketplaceExports() . $markupBuilder . '<div class="product-grid">';
        
        $firstFreightLogged = false;
        
        foreach ($products as $product) {
            $pid = (string) ($product['pid'] ?? '');
            $href = $this->appUrl([
                'page' => 'listings',
                'cj_pid' => $pid,
                'cj_query' => (string) ($_GET['cj_query'] ?? ''),
                'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
                'cj_category' => (string) ($_GET['cj_category'] ?? ''),
                'cj_category_id' => (string) ($_GET['cj_category_id'] ?? ''),
                'cj_page' => (string) $currentPage,
                'cj_page_size' => $pageSizeQuery,
                'cj_sort' => $sortQuery,
                'market_sample_size' => (string) $marketSampleSize,
            ]);
            $image = trim((string) ($product['image'] ?? ''));
            $imageHtml = $image !== ''
                ? '<img class="listing-card-image" src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="CJ product image">'
                : '<div class="listing-card-placeholder">No image</div>';
            $title = $this->truncateText((string) ($product['title'] ?? 'CJ product'), 32);
            
            // Fetch product details to get variants if not already loaded
            if (!isset($product['_variants']) || !is_array($product['_variants']) || empty($product['_variants'])) {
                try {
                    $productDetail = $this->loadCjProductDetail($accessToken, $pid, $countryCode);
                    if (isset($productDetail['_variants']) && is_array($productDetail['_variants']) && !empty($productDetail['_variants'])) {
                        $product['_variants'] = $productDetail['_variants'];
                    }
                } catch (Throwable $e) {
                    // Silently fail, will use default freight
                }
            }
            
            $vid = (string) ($product['vid'] ?? '');
            // If product doesn't have vid, try to get it from first variant
            if ($vid === '' && isset($product['_variants']) && is_array($product['_variants']) && !empty($product['_variants'])) {
                $vid = (string) ($product['_variants'][0]['vid'] ?? '');
            }
            
            // Extract SKU from product level first, then variant level
            $sku = (string) ($product['sku'] ?? '');
            if ($sku === '') {
                $sku = (string) ($product['variantSku'] ?? '');
            }
            if ($sku === '') {
                $sku = (string) ($product['productSku'] ?? '');
            }
            // Fall back to variant SKU if product SKU not available
            if ($sku === '' && isset($product['_variants']) && is_array($product['_variants']) && !empty($product['_variants'])) {
                $sku = (string) ($product['_variants'][0]['sku'] ?? '');
            }
            if ($sku === '' && isset($product['_variants']) && is_array($product['_variants']) && !empty($product['_variants'])) {
                $sku = (string) ($product['_variants'][0]['variantSku'] ?? '');
            }
            
            // Log product keys for debugging if SKU still empty
            if ($sku === '' && !$firstFreightLogged) {
                error_log('Product keys (first product): ' . json_encode(array_keys($product)));
                if (isset($product['_variants']) && is_array($product['_variants']) && !empty($product['_variants'])) {
                    error_log('First variant keys: ' . json_encode(array_keys($product['_variants'][0])));
                }
            }
            
            // Extract product location (srcCountryCode) - use the search country as source
            $srcCountryCode = $countryCode; // When searching US, source is US; when searching CN, source is CN
            
            // Only log details for first freight calculation
            $logDetails = !$firstFreightLogged;
            $basePrice = $this->extractFullCjCost($product, $accessToken, $srcCountryCode, $countryCode, $sku, $logDetails);
            $firstFreightLogged = true;
            
            $marketPricing = is_array($product['_marketPricing'] ?? null) ? (array) $product['_marketPricing'] : [];
            $marketPrice = (string) ($marketPricing['recommendedPrice'] ?? '');
            $ebayPrice = $marketPrice !== '' ? $marketPrice : $this->formatMarkedUpPrice($basePrice, 55.0, $this->defaultMarkupRules());
            $marketCustom = $marketPrice !== '' ? ' data-custom="true"' : '';
            $marketStatus = $marketPrice !== '' ? htmlspecialchars($this->formatEbayMarketPricingStatus($marketPricing), ENT_QUOTES, 'UTF-8') : '';
            $marketChip = $marketPrice !== '' ? $this->renderEbayMarketRangeChip($marketPricing) : '';
            $priceEditor = '<div class="cj-price-editor" data-cj-price-editor data-pid="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '" data-base-price="' . htmlspecialchars($basePrice > 0 ? number_format($basePrice, 2, '.', '') : '', ENT_QUOTES, 'UTF-8') . '">
                <div class="cj-price-row">
                  <span class="mini">CJ price <strong class="cj-price-value">' . htmlspecialchars((string) ($product['price'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</strong></span>
                  <span class="mini">eBay price <span class="cj-price-inline"><span>$</span><input class="cj-price-value-input" type="number" step="0.01" min="0.01" name="cj_price_overrides[' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . ']" value="' . htmlspecialchars($ebayPrice, ENT_QUOTES, 'UTF-8') . '" placeholder="19.99" data-cj-price-input data-cj-price-display title="Click to edit eBay price"' . $marketCustom . '></span></span>
                </div>
                <div class="cj-price-actions">
                  <button class="button button-secondary cj-price-button" type="button" data-save-cj-price title="Save price">S</button>
                  <button class="button button-secondary cj-price-button" type="button" data-reset-cj-price title="Reset price">R</button>
                  <span class="cj-price-status" data-cj-price-status>' . $marketStatus . '</span>
                </div>
              </div>';
            $html .= '<article class="workspace-card">
              <div class="card-topline"><label class="card-select"><input form="bulk-import-form" class="cj-bulk-selector" type="checkbox" name="cj_pids[]" value="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '"> Select</label>' . $marketChip . '</div>
              <a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $imageHtml . '</a>
              <h3 style="font-size:20px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>
              ' . $priceEditor . '
              <p>Inventory: ' . htmlspecialchars((string) ($product['inventory'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</p>
              <a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Open detail</a>
            </article>';
        }
        $html .= '</div></form>';

        $pageLinks = $this->renderCjPaginationLinks($currentPage, $totalPages, $pageSizeQuery);
        $nextUrl = $currentPage < $totalPages ? $this->appUrl([
            'page' => 'listings',
            'cj_query' => (string) ($_GET['cj_query'] ?? ''),
            'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
            'cj_category' => (string) ($_GET['cj_category'] ?? ''),
            'cj_category_id' => (string) ($_GET['cj_category_id'] ?? ''),
            'cj_page' => (string) ($currentPage + 1),
            'cj_page_size' => $pageSizeQuery,
            'cj_sort' => $sortQuery,
            'market_sample_size' => (string) $marketSampleSize,
        ]) : '#';

        $html .= '<div class="actions" style="justify-content:center; margin-top:20px; flex-wrap:wrap;">
            <span class="mini" style="padding:0 10px">Page ' . $currentPage . ' of ' . $totalPages . ' (' . $total . ' results)</span>
            ' . $pageLinks . '
            <a class="button button-secondary" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '" ' . ($currentPage >= $totalPages ? 'disabled style="opacity:0.5;pointer-events:none;"' : '') . '>Next</a>
        </div>';

        return $html;
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function renderEbayCategorySelectOptions(array $suggestions, string $selectedId = ''): string
    {
        if ($suggestions === []) {
            return '<option value="">No eBay category suggestions returned yet</option>';
        }

        $html = '';
        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $id = (string) ($suggestion['id'] ?? '');
            $selected = ($selectedId !== '' ? $id === $selectedId : $index === 0) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string) ($suggestion['id'] ?? ''), ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars((string) ($suggestion['path'] ?? $suggestion['name'] ?? ''), ENT_QUOTES, 'UTF-8') . ' [' . htmlspecialchars((string) ($suggestion['id'] ?? ''), ENT_QUOTES, 'UTF-8') . ']</option>';
        }

        return $html;
    }

    /** @param list<array<string, mixed>> $conditions */
    private function renderConditionSelectOptions(array $conditions, string $selectedId = '1000'): string
    {
        if ($conditions === []) {
            return '<option value="1000">1000 - New</option><option value="2750">2750 - Like New</option><option value="3000">3000 - Used</option>';
        }

        $html = '';
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $id = (string) ($condition['id'] ?? '');
            $name = (string) ($condition['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $selected = $id === $selectedId ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($id . ' - ' . $name, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        return $html;
    }

    private function renderMarkupRuleBuilder(string $context = 'default'): string
    {
        $defaultPercent = (string) ($_POST['markup_default_percent'] ?? $_POST['markup_percent'] ?? '55');
        $thresholds = array_values(array_map('strval', (array) ($_POST['markup_thresholds'] ?? [])));
        $percents = array_values(array_map('strval', (array) ($_POST['markup_percents'] ?? [])));

        $rows = [];
        $count = max(count($thresholds), count($percents));
        for ($index = 0; $index < $count; $index++) {
            $threshold = trim($thresholds[$index] ?? '');
            $percent = trim($percents[$index] ?? '');
            if ($threshold === '' && $percent === '') {
                continue;
            }
            $rows[] = ['threshold' => $threshold, 'percent' => $percent];
        }

        if ($rows === []) {
            $rows = $this->defaultProfitRows();
        }

        $html = '<details class="markup-builder compact-options" data-markup-builder="' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '">
          <summary><span>Markup rules</span></summary>
          <div class="compact-options-body">
          <div class="mini">Free shipping is applied automatically. Set price bands so cheaper products can carry a stronger markup.</div>
          <div class="form-grid">
            <label>Default markup % for prices above your tiers<input type="number" step="0.01" name="markup_default_percent" value="' . htmlspecialchars($defaultPercent, ENT_QUOTES, 'UTF-8') . '"></label>
          </div>
          <div class="markup-tier-list" data-markup-tier-list>';

        foreach ($rows as $row) {
            $profitValue = $row['profit'] ?? $row['percent'] ?? '';
            $html .= '<div class="markup-tier-row">
              <label>Apply when base price is <=
                <input type="number" step="0.01" name="markup_thresholds[]" value="' . htmlspecialchars($row['threshold'] ?? '', ENT_QUOTES, 'UTF-8') . '" placeholder="5.00">
              </label>
              <label>Profit $
                <input type="number" step="0.01" name="markup_percents[]" value="' . htmlspecialchars($profitValue, ENT_QUOTES, 'UTF-8') . '" placeholder="122">
              </label>
              <button class="button button-secondary markup-tier-remove" type="button" data-remove-markup-tier>&times;</button>
            </div>';
        }

        $html .= '</div>
          <div class="actions">
            <button class="button button-secondary" type="button" data-add-markup-tier>+ Add markup tier</button>
          </div>
          </div>
        </details>';

        return $html;
    }

    /**
     * @param list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> $aspects
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $variants
     */
    private function renderEbayAspectFields(array $aspects, array $product, array $variants, array $templateValues = []): string
    {
        if ($aspects === []) {
            return '<div class="subcard"><div class="mini">Load an eBay category above to review required item specifics like Department, Style, and material.</div></div>';
        }

        $variationAspectNames = $this->collectVariationAspectNames($variants, $product);
        $requiredRows = '';
        $optionalRows = '';
        $optionalCount = 0;
        foreach ($aspects as $aspect) {
            $name = trim((string) ($aspect['name'] ?? ''));
            if ($name === '' || in_array($name, $variationAspectNames, true)) {
                continue;
            }

            $defaultValue = (string) ($templateValues[$name] ?? $this->inferCjAspectValue($product, $name));
            $value = (string) ($_POST['ebay_aspects'][$name] ?? $defaultValue);
            $required = (bool) ($aspect['required'] ?? false);
            $values = is_array($aspect['values'] ?? null) ? array_values(array_filter(array_map('strval', $aspect['values']))) : [];
            $marker = $required ? ' <span class="mini">(Required)</span>' : '';

            if ($values !== [] && count($values) <= 25) {
                $options = '<option value="">Select ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
                foreach ($values as $option) {
                    $selected = $option === $value ? ' selected' : '';
                    $options .= '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                $input = '<select name="ebay_aspects[' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ']"' . ($required ? ' required' : '') . '>' . $options . '</select>';
            } else {
                $placeholder = $required ? 'Required for this category' : 'Optional';
                $input = '<input type="text" name="ebay_aspects[' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ']" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' . ($required ? ' required' : '') . '>';
            }

            $row = '<label>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . $marker . $input . '</label>';
            if ($required) {
                $requiredRows .= $row;
            } else {
                $optionalRows .= $row;
                $optionalCount++;
            }
        }

        if ($requiredRows === '' && $optionalRows === '') {
            return '<div class="subcard"><div class="mini">No extra non-variation item specifics were required for the current category.</div></div>';
        }

        $html = '<div class="subcard"><h4 style="margin:0 0 12px">Category-aware item specifics</h4>';
        if ($requiredRows !== '') {
            $html .= '<div class="form-grid">' . $requiredRows . '</div>';
        }
        if ($optionalRows !== '') {
            $html .= '<details class="aspect-optional"><summary>Optional specifics (' . $optionalCount . ')</summary><div class="form-grid" style="margin-top:14px">' . $optionalRows . '</div></details>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjProductDetailPanel(array $workspace): string
    {
        $product = is_array($workspace['selectedProduct'] ?? null) ? (array) $workspace['selectedProduct'] : [];
        if ($product === []) {
            return '<div class="empty">Pick a CJ product from the search results to inspect its variants and import it into eBay.</div>';
        }

        // Resolve CJ access token for freight calculation
        $cjTokenSource = 'none';
        $accessToken = $this->resolveCjAccessToken($cjTokenSource);

        $title = $this->extractCjProductTitle($product);
        $description = $this->buildCjDescriptionHtml($product);
        $images = $this->extractCjImages($product);
        $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
        $pid = (string) ($product['pid'] ?? '');
        $countryCode = strtoupper(trim((string) ($_GET['cj_country'] ?? 'US')));
        $srcCountryCode = $countryCode; // Use search country as source country
        $detailBasePrice = 0.0;
        // Extract SKU from product level first
        $productSku = (string) ($product['sku'] ?? '');
        if ($productSku === '') {
            $productSku = (string) ($product['variantSku'] ?? '');
        }
        if ($productSku === '') {
            $productSku = (string) ($product['productSku'] ?? '');
        }
        
        foreach ($variants as $variant) {
            $sku = (string) ($variant['sku'] ?? '');
            if ($sku === '') {
                $sku = (string) ($variant['variantSku'] ?? '');
            }
            // Use variant SKU if available, otherwise use product SKU
            $effectiveSku = $sku !== '' ? $sku : $productSku;
            $variantBase = $this->extractFullCjCost($variant, $accessToken, $srcCountryCode, $countryCode, $effectiveSku);
            if ($variantBase > 0 && ($detailBasePrice <= 0 || $variantBase < $detailBasePrice)) {
                $detailBasePrice = $variantBase;
            }
        }
        if ($detailBasePrice <= 0) {
            $sku = $variants !== [] ? (string) ($variants[0]['sku'] ?? '') : '';
            if ($sku === '') {
                $sku = (string) ($variants[0]['variantSku'] ?? '');
            }
            $effectiveSku = $sku !== '' ? $sku : $productSku;
            $detailBasePrice = $this->extractFullCjCost($product, $accessToken, $srcCountryCode, $countryCode, $effectiveSku);
        }
        $marketPricing = is_array($product['_marketPricing'] ?? null) ? (array) $product['_marketPricing'] : [];
        $marketPrice = (string) ($marketPricing['recommendedPrice'] ?? '');
        $detailEbayPrice = $marketPrice !== '' ? $marketPrice : $this->formatMarkedUpPrice($detailBasePrice, 55.0, $this->defaultMarkupRules());
        $marketCustom = $marketPrice !== '' ? ' data-custom="true"' : '';
        $marketStatus = $marketPrice !== '' ? htmlspecialchars($this->formatEbayMarketPricingStatus($marketPricing), ENT_QUOTES, 'UTF-8') : '';
        $marketNote = $marketPrice !== '' ? '<div class="mini" style="margin-top:8px">' . htmlspecialchars($this->formatEbayMarketPricingNote($marketPricing), ENT_QUOTES, 'UTF-8') . '</div>' : '';

        $imageHtml = '';
        foreach ($this->safeArraySlice($images, 0, 8) as $image) {
            $imageHtml .= '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="CJ image">';
        }
        if ($imageHtml === '') {
            $imageHtml = '<div class="empty">No CJ images were returned for this product.</div>';
        } else {
            $imageHtml = '<div class="image-strip">' . $imageHtml . '</div>';
        }

        $variantRows = '';
        foreach ($variants as $variant) {
            $vid = (string) ($variant['vid'] ?? '');
            $inventoryLabel = $this->hasCjInventorySignal($variant)
                ? (string) $this->extractCjVariantQuantity($variant, 0)
                : '';
            $variantRows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($variant['variantNameEn'] ?? $variant['variantName'] ?? $vid), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($variant['variantSku'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($variant['variantSellPrice'] ?? $variant['variantSugSellPrice'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($inventoryLabel, ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }
        $variantTable = $variantRows !== ''
            ? '<details class="variant-toggle"><summary>+ Show variants (' . count($variants) . ')</summary><div class="table-wrap"><table><thead><tr><th>Variant</th><th>SKU</th><th>Price</th><th>Inventory</th></tr></thead><tbody>' . $variantRows . '</tbody></table></div></details>'
            : '<div class="empty">No variants were returned for this CJ product.</div>';
        $selectedCategoryId = (string) ($workspace['selectedEbayCategoryId'] ?? '');
        $categorySuggestions = $this->renderEbayCategorySelectOptions((array) ($workspace['ebayCategorySuggestions'] ?? []), $selectedCategoryId);
        $conditionOptions = $this->renderConditionSelectOptions((array) ($workspace['ebayConditionOptions'] ?? []), (string) ($_POST['ebay_condition_id'] ?? '1000'));
        $marketSampleSize = (int) ($workspace['marketSampleSize'] ?? $this->resolveMarketSampleSizeFromRequest());
        $aspectFields = $this->renderEbayAspectFields(
            (array) ($workspace['ebayAspectRequirements'] ?? []),
            $product,
            $variants,
            (array) ($workspace['ebayAspectTemplateValues'] ?? [])
        );
        $markupBuilder = $this->renderMarkupRuleBuilder('single-import');

        return '<div class="action-stack">
          <div class="subcard">
            <h4 style="margin:0 0 10px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>
            <div class="kv">
              <div class="kv-item"><div class="kv-key">CJ PID</div><div>' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">Country filter</div><div>' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">Variation keys</div><div>' . htmlspecialchars((string) ($product['productKeyEn'] ?? $product['productKey'] ?? 'Auto-detect'), ENT_QUOTES, 'UTF-8') . '</div></div>
            </div>
          </div>
          <div class="subcard">' . $imageHtml . '</div>
          <div class="subcard"><h4 style="margin:0 0 12px">Variants</h4>' . $variantTable . '</div>
          <form method="get" action="' . htmlspecialchars($this->appUrl([]), ENT_QUOTES, 'UTF-8') . '" class="subcard action-stack">
            <input type="hidden" name="page" value="listings">
            <input type="hidden" name="cj_pid" value="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_country" value="' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_query" value="' . htmlspecialchars((string) ($_GET['cj_query'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_category" value="' . htmlspecialchars((string) ($_GET['cj_category'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_page_size" value="' . htmlspecialchars((string) ($_GET['cj_page_size'] ?? '20'), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_sort" value="' . htmlspecialchars((string) ($_GET['cj_sort'] ?? 'desc'), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="market_sample_size" value="' . htmlspecialchars((string) $marketSampleSize, ENT_QUOTES, 'UTF-8') . '">
            <h4 style="margin:0">Refresh eBay category metadata</h4>
            <div class="mini">Choose a category suggestion and load the required item specifics for that category.</div>
            <div class="form-grid">
              <label>eBay category<select name="ebay_category_id" required>' . $categorySuggestions . '</select></label>
            </div>
            <div class="actions"><button class="button button-secondary" type="submit">Load category specifics</button></div>
          </form>
          <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="subcard action-stack">
            <input type="hidden" name="manage_action" value="import_cj_product_to_ebay">
            <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl([
                'page' => 'listings',
                'cj_pid' => $pid,
                'cj_country' => $countryCode,
                'cj_query' => (string) ($_GET['cj_query'] ?? ''),
                'cj_category' => (string) ($_GET['cj_category'] ?? ''),
                'cj_page' => (string) ($_GET['cj_page'] ?? '1'),
                'cj_page_size' => (string) ($_GET['cj_page_size'] ?? '20'),
                'cj_sort' => (string) ($_GET['cj_sort'] ?? 'desc'),
                'market_sample_size' => (string) $marketSampleSize,
                'ebay_category_id' => $selectedCategoryId,
            ]), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_pid" value="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="cj_country_code" value="' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '">
            <h4 style="margin:0">List this CJ product on eBay</h4>
            
            <div class="mini" style="margin-bottom:12px;">All available CJ variants will be listed automatically. Target eBay details below:</div>
            <div class="cj-price-editor" data-cj-price-editor data-pid="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '" data-base-price="' . htmlspecialchars($detailBasePrice > 0 ? number_format($detailBasePrice, 2, '.', '') : '', ENT_QUOTES, 'UTF-8') . '">
              <div class="cj-price-row">
                <span class="mini">CJ base <strong class="cj-price-value">' . htmlspecialchars($detailBasePrice > 0 ? '$' . number_format($detailBasePrice, 2, '.', '') : 'Unknown', ENT_QUOTES, 'UTF-8') . '</strong></span>
                <span class="mini">eBay price <span class="cj-price-inline"><span>$</span><input class="cj-price-value-input" type="number" step="0.01" min="0.01" name="cj_price_override" value="' . htmlspecialchars($detailEbayPrice, ENT_QUOTES, 'UTF-8') . '" placeholder="19.99" data-cj-price-input data-cj-price-display title="Click to edit eBay price"' . $marketCustom . '></span></span>
              </div>
              <div class="cj-price-actions">
                <button class="button button-secondary cj-price-button" type="button" data-save-cj-price title="Save price">S</button>
                <button class="button button-secondary cj-price-button" type="button" data-reset-cj-price title="Reset price">R</button>
                <span class="cj-price-status" data-cj-price-status>' . $marketStatus . '</span>
              </div>
            </div>' . $marketNote . '
            

            <div class="form-grid">
              <label>Title override<input type="text" name="ebay_title" value="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"></label>
              <label>eBay category<select name="ebay_category_id" required>' . $categorySuggestions . '</select></label>
              <label>Condition<select name="ebay_condition_id">' . $conditionOptions . '</select></label>
              <label>Manual fallback quantity<input type="number" name="fallback_quantity" value="0" min="0"></label>
              <label>Max eBay qty per variant<input type="number" name="max_listing_quantity" value="5" min="1" max="50"></label>
              <label>Postal/Storage code<input type="text" name="postal_code" value="10001" required></label>
              <label>PayPal email<input type="email" name="paypal_email" placeholder="seller@example.com"></label>
            </div>
            ' . $markupBuilder . '
            ' . $aspectFields . '
            <label>Description<textarea name="ebay_description">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</textarea></label>
            <div class="actions"><button class="button button-primary" type="submit">Create eBay listing from CJ</button></div>
          </form>
        </div>';
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjMappingPanel(array $workspace): string
    {
        $rows = (array) ($workspace['recentMappings'] ?? []);
        $alerts = (array) ($workspace['lowStockAlerts'] ?? []);
        $syncForm = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="bulk-toolbar toolbar-row" style="margin-bottom:14px">
          <input type="hidden" name="manage_action" value="bulk_sync_cj_inventory">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'integrations']), ENT_QUOTES, 'UTF-8') . '">
          <div>
            <div class="workspace-eyebrow">Inventory sync</div>
            <div class="mini">Updates mapped eBay listing quantities from CJ product, variant, and SKU inventory endpoints.</div>
          </div>
          <label>Country <input type="text" name="cj_country_code" value="US" maxlength="2" style="width:84px"></label>
          <label>Max mappings <input type="number" name="sync_limit" value="250" min="1" max="250" style="width:110px"></label>
          <label>Max eBay qty <input type="number" name="max_listing_quantity" value="5" min="1" max="50" style="width:110px"></label>
          <button class="button button-primary" type="submit">Bulk sync inventory</button>
        </form>';
        $manualForm = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="subcard action-stack" style="margin-bottom:14px">
          <input type="hidden" name="manage_action" value="manual_bulk_inventory_update">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'integrations']), ENT_QUOTES, 'UTF-8') . '">
          <div>
            <div class="workspace-eyebrow">Manual inventory override</div>
            <div class="mini">Paste one update per line as eBay item ID | SKU | quantity. Leave SKU blank for single-SKU listings.</div>
          </div>
          <textarea name="inventory_updates" rows="4" placeholder="336565186527|CJBHNSNS11458-Red-40|2&#10;336565186528||0"></textarea>
          <button class="button button-secondary" type="submit">Apply manual bulk update</button>
        </form>';
        $priceUpdateForm = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="bulk-toolbar toolbar-row" style="margin-bottom:14px">
          <input type="hidden" name="manage_action" value="update_ebay_prices_from_profit_rows">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'integrations']), ENT_QUOTES, 'UTF-8') . '">
          <div>
            <div class="workspace-eyebrow">Bulk price update</div>
            <div class="mini">Updates mapped eBay listing prices based on default profit rows from CJ costs. Handles both single listings and variations.</div>
          </div>
          <label>Country <input type="text" name="cj_country_code" value="US" maxlength="2" style="width:84px"></label>
          <label>Max mappings <input type="number" name="sync_limit" value="250" min="1" max="250" style="width:110px"></label>
          <button class="button button-primary" type="submit">Bulk update prices</button>
        </form>';
        $lowStockPanel = '<div class="subcard action-stack" style="margin-bottom:14px"><div><div class="workspace-eyebrow">Low inventory watch</div><div class="mini">Flags mapped CJ variants at or below ' . htmlspecialchars((string) ($workspace['lowStockThreshold'] ?? 2), ENT_QUOTES, 'UTF-8') . ' units.</div></div>';
        if ($alerts === []) {
            $lowStockPanel .= '<div class="empty">No mapped variant is at the low-stock threshold right now.</div>';
        } else {
            $lowStockPanel .= '<div class="table-wrap"><table><thead><tr><th>eBay item</th><th>SKU / VID</th><th>Qty</th><th>Source</th></tr></thead><tbody>';
            foreach ($alerts as $alert) {
                if (!is_array($alert)) {
                    continue;
                }
                $sku = trim((string) ($alert['sku'] ?? ''));
                $vid = trim((string) ($alert['vid'] ?? ''));
                $lowClass = (int) ($alert['quantity'] ?? 0) <= 0 ? 'warn' : 'ok';
                $lowStockPanel .= '<tr>
                  <td><strong>' . htmlspecialchars((string) ($alert['ebay_item_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong><div class="mini">' . htmlspecialchars((string) ($alert['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>
                  <td>' . htmlspecialchars($sku !== '' ? $sku : $vid, ENT_QUOTES, 'UTF-8') . '</td>
                  <td><span class="status-pill ' . $lowClass . '">' . htmlspecialchars((string) ($alert['quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</span></td>
                  <td>' . htmlspecialchars((string) ($alert['source'] ?? 'CJ inventory'), ENT_QUOTES, 'UTF-8') . '</td>
                </tr>';
            }
            $lowStockPanel .= '</tbody></table></div>';
        }
        $lowStockPanel .= '</div>';
        $inventoryControls = $syncForm . $priceUpdateForm . $manualForm . $lowStockPanel;
        if ($rows === []) {
            return $inventoryControls . '<div class="empty">No CJ to eBay listing mappings are stored yet. Once you import a CJ product through this console, it will appear here for later inventory and order sync work.</div>';
        }

        $html = $inventoryControls . '<div class="table-wrap"><table><thead><tr><th>eBay item</th><th>CJ product</th><th>Variants</th><th>Saved</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vids = json_decode((string) ($row['cj_vids_json'] ?? '[]'), true);
            $html .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($row['ebay_item_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong><div class="mini">' . htmlspecialchars((string) ($row['cj_title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>
              <td>' . htmlspecialchars((string) ($row['cj_pid'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) (is_array($vids) ? count($vids) : 0), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    /** @param array<string, mixed> $workspace */
    private function renderCjOrderPanel(array $workspace): string
    {
        $orders = (array) ($workspace['recentOrders'] ?? []);
        $maps = (array) ($workspace['recentOrderMaps'] ?? []);
        $redirect = htmlspecialchars($this->appUrl(['page' => 'orders']), ENT_QUOTES, 'UTF-8');
        $toolbar = '<div class="subcard action-stack" style="margin-bottom:14px">
          <div>
            <div class="workspace-eyebrow">CJ order automation</div>
            <div class="mini">Push paid eBay orders into CJ, pull CJ status, and send CJ tracking back to eBay.</div>
          </div>
          <div class="toolbar-row">
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="sync_orders_two_way">
              <input type="hidden" name="redirect" value="' . $redirect . '">
              <button class="button button-primary" type="submit">Two-way order sync</button>
            </form>
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="sync_ebay_orders_to_cj">
              <input type="hidden" name="redirect" value="' . $redirect . '">
              <button class="button button-secondary" type="submit">Push eBay to CJ</button>
            </form>
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="sync_cj_orders_from_cj">
              <input type="hidden" name="redirect" value="' . $redirect . '">
              <button class="button button-secondary" type="submit">Pull CJ status</button>
            </form>
            <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
              <input type="hidden" name="manage_action" value="sync_cj_tracking_to_ebay">
              <input type="hidden" name="redirect" value="' . $redirect . '">
              <button class="button button-secondary" type="submit">Push tracking to eBay</button>
            </form>
          </div>
          <form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
            <input type="hidden" name="manage_action" value="configure_cj_webhooks">
            <input type="hidden" name="redirect" value="' . $redirect . '">
            <label>CJ webhook URL <input type="url" name="cj_webhook_url" value="' . htmlspecialchars($this->defaultCjWebhookUrl(), ENT_QUOTES, 'UTF-8') . '" style="min-width:340px"></label>
            <button class="button button-secondary" type="submit">Enable CJ webhooks</button>
          </form>
        </div>';

        $localRows = '';
        foreach ($maps as $map) {
            if (!is_array($map)) {
                continue;
            }
            $tracking = trim((string) ($map['tracking_number'] ?? ''));
            $localRows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($map['ebay_order_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong><div class="mini">CJ ' . htmlspecialchars((string) ($map['cj_order_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>
              <td>' . htmlspecialchars((string) ($map['cj_order_status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($map['logistic_name'] ?? $map['shipping_carrier'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($tracking !== '' ? $tracking : 'Pending', ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($map['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        $localTable = $localRows !== ''
            ? '<div class="table-wrap" style="margin-bottom:14px"><table><thead><tr><th>eBay / CJ</th><th>CJ status</th><th>Logistics</th><th>Tracking</th><th>Updated</th></tr></thead><tbody>' . $localRows . '</tbody></table></div>'
            : '<div class="empty" style="margin-bottom:14px">No local CJ order mappings yet. Use the sync buttons after eBay has a paid order.</div>';

        $rows = '';
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $rows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($order['orderNum'] ?? $order['orderId'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($order['orderStatus'] ?? $order['status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($order['shippingStatus'] ?? $order['deliveryStatus'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($order['trackingNumber'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        $cjTable = $rows !== ''
            ? '<div class="table-wrap"><table><thead><tr><th>CJ order</th><th>Status</th><th>Shipping</th><th>Tracking</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<div class="empty">No recent CJ orders are visible right now.</div>';

        return $toolbar . $localTable . $cjTable;
    }

    /** @param list<array<string, mixed>> $data @param array<string, string> $categoryMap @return list<array<string, mixed>> */
    private function normalizeCjProductList(mixed $data, array $categoryMap = []): array
    {
        $rows = [];
        $items = $this->flattenCjProductRows($data);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $catId = (string) ($item['categoryId'] ?? '');
            $catName = (string) ($item['categoryName'] ?? '');
            if ($catName === '' && $catId !== '') {
                $catName = $categoryMap[$catId] ?? $catId;
            }

            $rows[] = [
                'pid' => (string) ($item['pid'] ?? $item['id'] ?? ''),
                'title' => $this->extractCjProductTitle($item),
                'image' => $this->extractCjImages($item)[0] ?? '',
                'price' => (string) ($item['productSellPrice'] ?? $item['sellPrice'] ?? $item['variantSellPrice'] ?? $item['minPrice'] ?? ''),
                'inventory' => (string) ($item['warehouseInventoryNum'] ?? $item['inventoryNum'] ?? $item['totalInventoryNum'] ?? ''),
                'category' => $catName !== '' ? $catName : 'Unknown',
            ];
        }

        return $rows;
    }

    private function extractNumericPrice(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        if (is_array($value)) {
            foreach (['value', 'price', 'amount', 'min', 'minPrice'] as $key) {
                if (isset($value[$key])) {
                    $price = $this->extractNumericPrice($value[$key]);
                    if ($price > 0) {
                        return $price;
                    }
                }
            }
            return 0.0;
        }

        $raw = str_replace(',', '', trim((string) $value));
        if ($raw === '') {
            return 0.0;
        }

        if (preg_match('/\d+(?:\.\d+)?/', $raw, $matches) !== 1) {
            return 0.0;
        }

        return max(0.0, (float) $matches[0]);
    }

    private function extractFullCjCost(array $productOrVariant, ?string $accessToken = null, string $srcCountryCode = 'CN', string $destCountryCode = 'US', ?string $sku = null, bool $logDetails = true): float
    {
        $basePrice = $this->extractNumericPrice(
            $productOrVariant['variantSellPrice'] 
            ?? $productOrVariant['variantSugSellPrice'] 
            ?? $productOrVariant['productSellPrice'] 
            ?? $productOrVariant['sellPrice'] 
            ?? $productOrVariant['minPrice'] 
            ?? $productOrVariant['price'] 
            ?? 0
        );

        $stockFee = $this->extractNumericPrice(
            $productOrVariant['stockFee'] 
            ?? $productOrVariant['stock_fee'] 
            ?? $productOrVariant['warehousingFee'] 
            ?? $productOrVariant['warehousing_fee'] 
            ?? 0
        );

        $lastMileFee = $this->extractNumericPrice(
            $productOrVariant['lastMileFee'] 
            ?? $productOrVariant['last_mile_fee'] 
            ?? $productOrVariant['shippingFee'] 
            ?? $productOrVariant['shipping_fee'] 
            ?? $productOrVariant['logisticsFee'] 
            ?? $productOrVariant['logistics_fee'] 
            ?? $productOrVariant['deliveryFee'] 
            ?? $productOrVariant['delivery_fee'] 
            ?? 0
        );

        // Calculate freight using CJ API freightCalculateTip (requires SKU)
        $freightCost = 0.0;
        
        if ($accessToken !== null && $accessToken !== '' && $sku !== null && $sku !== '') {
            try {
                // Extract product data for freight calculation
                $productData = [
                    'length' => (float) ($productOrVariant['length'] ?? 0.3),
                    'width' => (float) ($productOrVariant['width'] ?? 0.4),
                    'height' => (float) ($productOrVariant['height'] ?? 0.5),
                    'volume' => (float) ($productOrVariant['volume'] ?? 0.06),
                    'weight' => (int) ($productOrVariant['weight'] ?? $productOrVariant['wrapWeight'] ?? 100),
                    'wrapWeight' => (int) ($productOrVariant['wrapWeight'] ?? $productOrVariant['weight'] ?? 100),
                    'price' => $basePrice,
                ];
                
                $freightResponse = $this->cjService->calculateFreightTip($accessToken, $sku, $srcCountryCode, $destCountryCode, $productData, 1);
                if ($logDetails) {
                    error_log('Freight API response (sku): ' . json_encode($freightResponse));
                }
                if (isset($freightResponse['data']) && is_array($freightResponse['data']) && !empty($freightResponse['data'])) {
                    $shippingPrices = [];
                    foreach ($freightResponse['data'] as $option) {
                        // freightCalculateTip returns 'postage' field
                        if (isset($option['postage'])) {
                            $shippingPrices[] = (float) $option['postage'];
                        }
                    }
                    if ($shippingPrices !== []) {
                        $freightCost = min($shippingPrices);
                        if ($logDetails) {
                            error_log('Freight cost from API (sku, cheapest): ' . $freightCost);
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($logDetails) {
                    error_log('Freight API failed with sku: ' . $e->getMessage());
                }
            }
        }
        
        // No fallback - only use API return, freight cost remains 0 if API fails or no sku

        $totalCost = $basePrice + $stockFee + $lastMileFee + $freightCost;

        // Log for debugging
        if ($logDetails) {
            error_log('extractFullCjCost - basePrice: ' . $basePrice . ', stockFee: ' . $stockFee . ', lastMileFee: ' . $lastMileFee . ', freightCost: ' . $freightCost . ', total: ' . $totalCost . ', sku: ' . ($sku ?? 'null'));
        }

        return $totalCost;
    }

    /** @return list<array{threshold:string,profit:string}> */
    private function defaultProfitRows(): array
    {
        return [
            ['threshold' => '2',   'profit' => '8'],
            ['threshold' => '3',   'profit' => '10'],
            ['threshold' => '5',   'profit' => '15'],
            ['threshold' => '10',  'profit' => '25'],
            ['threshold' => '15',  'profit' => '30'],
            ['threshold' => '20',  'profit' => '35'],

            ['threshold' => '25',  'profit' => '38'],
            ['threshold' => '30',  'profit' => '40'],
            ['threshold' => '35',  'profit' => '42'],
            ['threshold' => '40',  'profit' => '45'],
            ['threshold' => '45',  'profit' => '47'],
            ['threshold' => '50',  'profit' => '50'],
            ['threshold' => '55',  'profit' => '52'],
            ['threshold' => '60',  'profit' => '55'],
            ['threshold' => '65',  'profit' => '57'],
            ['threshold' => '70',  'profit' => '60'],
            ['threshold' => '75',  'profit' => '62'],
            ['threshold' => '80',  'profit' => '65'],
            ['threshold' => '85',  'profit' => '67'],
            ['threshold' => '90',  'profit' => '70'],
            ['threshold' => '95',  'profit' => '72'],
            ['threshold' => '100', 'profit' => '75'],

            ['threshold' => '125', 'profit' => '80'],
            ['threshold' => '150', 'profit' => '90'],
            ['threshold' => '175', 'profit' => '100'],
            ['threshold' => '200', 'profit' => '110'],
            ['threshold' => '250', 'profit' => '125'],
            ['threshold' => '300', 'profit' => '150'],
            ['threshold' => '400', 'profit' => '175'],
            ['threshold' => '500', 'profit' => '200'],
        ];
    }

    private function getTargetProfit(float $cjCost): float
    {
        $rows = $this->defaultProfitRows();

        foreach ($rows as $row) {
            if ($cjCost <= (float) $row['threshold']) {
                return (float) $row['profit'];
            }
        }

        return 250.00;
    }

    private function calculateSellingPrice(float $cjCost): float
    {
        $feeMultiplier = 0.82;
        $targetProfit = $this->getTargetProfit($cjCost);

        $sellingPrice = ($cjCost + $targetProfit) / $feeMultiplier;

        return $this->roundToEbayPrice($sellingPrice);
    }

    private function roundToEbayPrice(float $price): float
    {
        return floor($price) + 0.99;
    }

    /** @return list<array{threshold:float,profit:float}> */
    private function defaultMarkupRules(): array
    {
        $rules = [];
        foreach ($this->defaultProfitRows() as $row) {
            $rules[] = [
                'threshold' => (float) $row['threshold'],
                'profit' => (float) $row['profit'],
            ];
        }

        usort($rules, static fn (array $left, array $right): int => $left['threshold'] <=> $right['threshold']);
        return $rules;
    }

    /** @param list<array{threshold:float,profit:float}> $rules */
    private function formatMarkedUpPrice(float $basePrice, float $defaultPercent, array $rules): string
    {
        if ($basePrice <= 0) {
            return '';
        }

        $sellingPrice = $this->calculateSellingPrice($basePrice);
        return number_format($sellingPrice, 2, '.', '');
    }

    private function resolveMarketSampleSizeFromRequest(): int
    {
        $sampleSize = (int) ($_GET['market_sample_size'] ?? $_POST['market_sample_size'] ?? 10);
        return in_array($sampleSize, [5, 10, 15], true) ? $sampleSize : 10;
    }

    /** @param list<array<string, mixed>> $products @return list<array<string, mixed>> */
    private function applyEbayMarketPricingToCjProducts(array $products, int $sampleSize): array
    {
        if ($products === []) {
            return [];
        }

        $token = $this->resolveEbayMarketPricingToken();
        if ($token === '') {
            return $products;
        }

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $basePrice = $this->extractCjMarketBasePrice($product, [], null, 'US');
            $markupPrice = $this->formatMarkedUpPrice($basePrice, 55.0, $this->defaultMarkupRules());
            $marketPricing = $this->calculateEbayMarketPricing(
                (string) ($product['title'] ?? ''),
                $basePrice,
                $markupPrice,
                $sampleSize,
                $token
            );
            if ($marketPricing !== []) {
                $product['_marketPricing'] = $marketPricing;
                $products[$index] = $product;
            }
        }

        return $products;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function applyEbayMarketPricingToSelectedCjProduct(array $product, int $sampleSize): array
    {
        $token = $this->resolveEbayMarketPricingToken();
        if ($token === '') {
            return $product;
        }

        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        $countryCode = strtoupper(trim((string) ($_GET['cj_country'] ?? 'US')));

        $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
        $basePrice = $this->extractCjMarketBasePrice($product, $variants, $cjAccessToken, $countryCode);
        $markupPrice = $this->formatMarkedUpPrice($basePrice, 55.0, $this->defaultMarkupRules());
        $marketPricing = $this->calculateEbayMarketPricing(
            $this->extractCjProductTitle($product),
            $basePrice,
            $markupPrice,
            $sampleSize,
            $token
        );
        if ($marketPricing !== []) {
            $product['_marketPricing'] = $marketPricing;
        }

        return $product;
    }

    private function resolveEbayMarketPricingToken(): string
    {
        if ($this->ebayMarketPricingTokenCache !== null) {
            return $this->ebayMarketPricingTokenCache;
        }

        if ($this->config->appToken !== '') {
            return $this->ebayMarketPricingTokenCache = $this->config->appToken;
        }

        $tokenSource = 'user';
        return $this->ebayMarketPricingTokenCache = $this->resolveToken('user', $tokenSource);
    }

    /**
     * @return array{
     *   recommendedPrice:string,
     *   sampleCount:int,
     *   sampleSize:int,
     *   marketMin:float,
     *   marketMax:float,
     *   marketMedian:float,
     *   markupPrice:float,
     *   profitFloor:float,
     *   strategy:string
     * }
     */
    private function calculateEbayMarketPricing(string $title, float $basePrice, string $markupPrice, int $sampleSize, string $token): array
    {
        $title = trim($title);
        $markup = $this->extractNumericPrice($markupPrice);
        if ($title === '' || $basePrice <= 0 || $markup <= 0 || $token === '' || $this->ebayMarketPricingUnavailable) {
            return [];
        }

        $cacheKey = strtolower($this->normalizeEbayMarketSearchQuery($title)) . '|' . number_format($basePrice, 2, '.', '') . '|' . $markupPrice . '|' . $sampleSize;
        if (array_key_exists($cacheKey, $this->ebayMarketPricingCache)) {
            return $this->ebayMarketPricingCache[$cacheKey];
        }

        $listings = $this->loadEbayMarketListingPrices($title, $sampleSize, $token);
        if (count($listings) < min(3, $sampleSize)) {
            return $this->ebayMarketPricingCache[$cacheKey] = [];
        }

        $prices = array_values(array_filter(array_map(
            static fn (array $listing): float => (float) ($listing['totalPrice'] ?? 0),
            $listings
        ), static fn (float $price): bool => $price > 0));
        if (count($prices) < min(3, $sampleSize)) {
            return $this->ebayMarketPricingCache[$cacheKey] = [];
        }

        sort($prices, SORT_NUMERIC);
        $marketMin = (float) $prices[0];
        $marketMax = (float) $prices[count($prices) - 1];
        $marketMedian = $this->median($prices);
        $profitFloor = max($basePrice + 1.00, $basePrice * 1.15);
        $range = max(0.0, $marketMax - $marketMin);
        $lowBandFloor = max($profitFloor, $marketMin * 0.97);
        $lowBandCeiling = $marketMin + max(3.0, $range * 0.2);
        $undercutTarget = max(0.01, $marketMin - 0.01);

        $strategy = 'market_adjusted';
        $recommended = max($profitFloor, $undercutTarget);
        if ($markup >= $lowBandFloor && $markup <= $lowBandCeiling) {
            $recommended = $markup;
            $strategy = 'markup_already_competitive';
        } elseif ($markup < $lowBandFloor) {
            $strategy = 'raised_to_market_floor';
        } elseif ($markup > $lowBandCeiling) {
            $strategy = 'lowered_to_market_floor';
        }

        if ($recommended <= $basePrice) {
            return $this->ebayMarketPricingCache[$cacheKey] = [];
        }

        return $this->ebayMarketPricingCache[$cacheKey] = [
            'recommendedPrice' => number_format($recommended, 2, '.', ''),
            'sampleCount' => count($prices),
            'sampleSize' => $sampleSize,
            'marketMin' => $marketMin,
            'marketMax' => $marketMax,
            'marketMedian' => $marketMedian,
            'markupPrice' => $markup,
            'profitFloor' => $profitFloor,
            'strategy' => $strategy,
        ];
    }

    /** @return list<array{title:string,totalPrice:float,itemPrice:float,shippingPrice:float,url:string}> */
    private function loadEbayMarketListingPrices(string $title, int $sampleSize, string $token): array
    {
        $queryText = $this->normalizeEbayMarketSearchQuery($title);
        if ($queryText === '') {
            return [];
        }

        $limit = (string) min(50, max(15, $sampleSize * 3));
        $query = [
            'q' => $queryText,
            'limit' => $limit,
            'filter' => 'buyingOptions:{FIXED_PRICE}',
        ];
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_US'];

        try {
            $result = $this->apiClient->request('GET', '/buy/browse/v1/item_summary/search', $token, $query, $headers);
            if ((int) ($result['status'] ?? 500) < 200 || (int) ($result['status'] ?? 500) >= 300) {
                unset($query['filter']);
                $result = $this->apiClient->request('GET', '/buy/browse/v1/item_summary/search', $token, $query, $headers);
            }
        } catch (Throwable) {
            return [];
        }

        $status = (int) ($result['status'] ?? 500);
        if ($status < 200 || $status >= 300) {
            if (in_array($status, [401, 403], true)) {
                $this->ebayMarketPricingUnavailable = true;
            }
            return [];
        }

        $listings = [];
        foreach ((array) (($result['body'] ?? [])['itemSummaries'] ?? []) as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $listingTitle = trim((string) ($summary['title'] ?? ''));
            if (!$this->isRelatedEbayMarketListing($title, $listingTitle)) {
                continue;
            }
            $priceData = $summary['price'] ?? 0;
            $itemPrice = $this->extractNumericPrice(is_array($priceData) ? ($priceData['value'] ?? $priceData) : $priceData);
            if ($itemPrice <= 0) {
                continue;
            }
            $shippingPrice = $this->extractEbayMarketShippingCost((array) ($summary['shippingOptions'] ?? []));
            $listings[] = [
                'title' => $listingTitle,
                'totalPrice' => $itemPrice + $shippingPrice,
                'itemPrice' => $itemPrice,
                'shippingPrice' => $shippingPrice,
                'url' => (string) ($summary['itemWebUrl'] ?? ''),
            ];
            if (count($listings) >= $sampleSize) {
                break;
            }
        }

        return $listings;
    }

    private function normalizeEbayMarketSearchQuery(string $title): string
    {
        $title = preg_replace('/\s+/', ' ', strip_tags($title)) ?? $title;
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        if (strlen($title) <= 120) {
            return $title;
        }

        $truncated = substr($title, 0, 120);
        $lastSpace = strrpos($truncated, ' ');
        return trim($lastSpace !== false ? substr($truncated, 0, $lastSpace) : $truncated);
    }

    /** @param list<array<string, mixed>> $shippingOptions */
    private function extractEbayMarketShippingCost(array $shippingOptions): float
    {
        $costs = [];
        foreach ($shippingOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $shippingCost = $option['shippingCost'] ?? 0;
            $cost = $this->extractNumericPrice(is_array($shippingCost) ? ($shippingCost['value'] ?? $shippingCost) : $shippingCost);
            if ($cost >= 0) {
                $costs[] = $cost;
            }
        }

        return $costs !== [] ? min($costs) : 0.0;
    }

    private function isRelatedEbayMarketListing(string $sourceTitle, string $listingTitle): bool
    {
        $sourceTokens = $this->tokenizeEbayMarketTitle($sourceTitle);
        $listingTokens = $this->tokenizeEbayMarketTitle($listingTitle);
        if ($sourceTokens === [] || $listingTokens === []) {
            return false;
        }

        $matches = array_intersect($sourceTokens, $listingTokens);
        $matchCount = count($matches);
        $required = min(4, max(2, (int) ceil(count($sourceTokens) * 0.25)));

        return $matchCount >= $required;
    }

    /** @return list<string> */
    private function tokenizeEbayMarketTitle(string $title): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($title)) ?: [];
        $stopWords = [
            'and' => true,
            'the' => true,
            'with' => true,
            'for' => true,
            'from' => true,
            'fits' => true,
            'fit' => true,
            'new' => true,
            'all' => true,
            'model' => true,
            'models' => true,
            'compatible' => true,
            'replacement' => true,
        ];

        $tokens = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || isset($stopWords[$word])) {
                continue;
            }
            if (strlen($word) < 3 && !preg_match('/\d/', $word)) {
                continue;
            }
            $tokens[] = $word;
        }

        return array_values(array_unique($tokens));
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /** @param array<string, mixed> $product @param list<array<string, mixed>> $variants */
    private function extractCjMarketBasePrice(array $product, array $variants = [], ?string $accessToken = null, string $countryCode = 'US'): float
    {
        $prices = [];
        foreach ($variants as $variant) {
            $vid = (string) ($variant['vid'] ?? '');
            $price = $this->extractFullCjCost($variant, $accessToken, 'CN', $countryCode, $vid);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        $vid = $variants !== [] ? (string) ($variants[0]['vid'] ?? '') : '';
        $productPrice = $this->extractFullCjCost($product, $accessToken, 'CN', $countryCode, $vid);
        if ($productPrice > 0) {
            $prices[] = $productPrice;
        }

        return $prices !== [] ? min($prices) : 0.0;
    }

    /** @param array<string, mixed> $marketPricing */
    private function formatEbayMarketPricingStatus(array $marketPricing): string
    {
        return (string) ($marketPricing['strategy'] ?? '') === 'markup_already_competitive'
            ? 'Markup OK'
            : 'Market';
    }

    /** @param array<string, mixed> $marketPricing */
    private function renderEbayMarketRangeChip(array $marketPricing): string
    {
        $count = (int) ($marketPricing['sampleCount'] ?? 0);
        $min = number_format((float) ($marketPricing['marketMin'] ?? 0), 2, '.', '');
        $max = number_format((float) ($marketPricing['marketMax'] ?? 0), 2, '.', '');
        if ($count <= 0 || $min === '0.00' || $max === '0.00') {
            return '';
        }

        $tone = (string) ($marketPricing['strategy'] ?? '') === 'markup_already_competitive' ? ' good' : '';
        return '<span class="market-chip' . $tone . '" title="' . htmlspecialchars($this->formatEbayMarketPricingNote($marketPricing), ENT_QUOTES, 'UTF-8') . '">eBay ' . $count . ': $' . htmlspecialchars($min, ENT_QUOTES, 'UTF-8') . '-$' . htmlspecialchars($max, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** @param array<string, mixed> $marketPricing */
    private function formatEbayMarketPricingNote(array $marketPricing): string
    {
        $count = (int) ($marketPricing['sampleCount'] ?? 0);
        $min = number_format((float) ($marketPricing['marketMin'] ?? 0), 2, '.', '');
        $max = number_format((float) ($marketPricing['marketMax'] ?? 0), 2, '.', '');
        $price = (string) ($marketPricing['recommendedPrice'] ?? '');
        $strategy = (string) ($marketPricing['strategy'] ?? '');
        $label = match ($strategy) {
            'markup_already_competitive' => 'markup is already competitive',
            'raised_to_market_floor' => 'raised toward the market while preserving margin',
            'lowered_to_market_floor' => 'lowered toward the low end of the market',
            default => 'market-adjusted',
        };

        return 'eBay market: ' . $count . ' related listings $' . $min . '-$' . $max . '; using $' . $price . ' (' . $label . ').';
    }

    /** @return list<array<string, mixed>> */
    private function normalizeCjVariants(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (is_array($data) && isset($data['variants']) && is_array($data['variants']) && array_is_list($data['variants'])) {
            return array_values(array_filter($data['variants'], 'is_array'));
        }
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $this->normalizeCjVariants($data['data']);
        }
        if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
            return array_values(array_filter($data['content'], 'is_array'));
        }
        return [];
    }

    private function extractCjProductTitle(array $product): string
    {
        return trim((string) ($product['productNameEn'] ?? $product['productName'] ?? $product['nameEn'] ?? $product['name'] ?? 'CJ product'));
    }

    /** @return list<string> */
    private function extractCjImages(array $product): array
    {
        $images = [];
        foreach (['bigImage', 'productImage', 'mainImage', 'image', 'variantImage'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                $images[] = $value;
            }
        }

        foreach (['productImageList', 'images', 'productImages', 'imageList', 'productImageSet'] as $key) {
            $value = $product[$key] ?? [];
            if (is_string($value) && $value !== '') {
                foreach ($this->parsePossibleStringList($value) as $part) {
                    if ($part !== '') {
                        $images[] = $part;
                    }
                }
            }
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $images[] = trim($entry);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images, static fn (string $value): bool => str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))));
    }

    /** @param array<string, mixed> $product @param list<array<string, mixed>> $variants @return list<string> */
    private function collectCjListingImages(array $product, array $variants): array
    {
        $images = $this->extractCjImages($product);
        foreach ($variants as $variant) {
            foreach (['variantImage', 'variantImageUrl', 'image', 'bigImage'] as $key) {
                $value = trim((string) ($variant[$key] ?? ''));
                if ($value !== '') {
                    $images[] = $value;
                }
            }
            foreach (['variantImageSet', 'images', 'imageList'] as $key) {
                $value = $variant[$key] ?? null;
                if (is_string($value)) {
                    $images = array_merge($images, $this->parsePossibleStringList($value));
                } elseif (is_array($value)) {
                    foreach ($value as $entry) {
                        $candidate = trim((string) $entry);
                        if ($candidate !== '') {
                            $images[] = $candidate;
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images, static fn (string $value): bool => str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))));
    }

    /** @return list<array<string, mixed>> */
    private function flattenCjProductRows(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (!is_array($data)) {
            return [];
        }

        $rows = [];
        foreach (['list', 'records', 'data'] as $key) {
            if (isset($data[$key])) {
                $rows = array_merge($rows, $this->flattenCjProductRows($data[$key]));
            }
        }
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $group) {
                if (is_array($group) && isset($group['productList']) && is_array($group['productList'])) {
                    $rows = array_merge($rows, array_values(array_filter($group['productList'], 'is_array')));
                    continue;
                }
                if (is_array($group)) {
                    $rows[] = $group;
                }
            }
        }
        if ($rows === [] && isset($data['productList']) && is_array($data['productList'])) {
            $rows = array_values(array_filter($data['productList'], 'is_array'));
        }

        return $rows;
    }

    /** @return list<string> */
    private function parsePossibleStringList(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(static fn ($entry): string => is_string($entry) ? trim($entry) : '', $decoded)));
        }

        $parts = preg_split('/[\s,]+/', $trimmed) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }

    /** @return list<array<string, mixed>> */
    private function normalizeCjOrderList(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (!is_array($data)) {
            return [];
        }
        foreach (['list', 'orders', 'records', 'content', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->normalizeCjOrderList($data[$key]);
            }
        }
        return [];
    }

    private function storeOAuthState(string $state): void
    {
        $states = $this->readOAuthStates();
        $states[$state] = time() + 1800;
        $this->writeOAuthStates($states);
    }

    private function consumeStoredOAuthState(string $state): bool
    {
        $states = $this->readOAuthStates();
        $now = time();
        $valid = false;
        foreach ($states as $key => $expiresAt) {
            if (!is_int($expiresAt) || $expiresAt < $now) {
                unset($states[$key]);
                continue;
            }
            if ($key === $state) {
                $valid = true;
                unset($states[$key]);
            }
        }
        $this->writeOAuthStates($states);
        return $valid;
    }

    /** @return array<string, int> */
    private function readOAuthStates(): array
    {
        $path = $this->oauthStatePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $states = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $states[$key] = $value;
            }
        }

        return $states;
    }

    /** @param array<string, int> $states */
    private function writeOAuthStates(array $states): void
    {
        $path = $this->oauthStatePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function oauthStatePath(): string
    {
        return dirname(__DIR__, 2) . '/database/oauth_states.json';
    }

    private function persistUserTokenToEnv(string $accessToken, ?string $refreshToken): void
    {
        if ($accessToken === '') {
            return;
        }

        $updates = ['EBAY_USER_TOKEN' => $accessToken];
        if ($refreshToken !== null && $refreshToken !== '') {
            $updates['EBAY_USER_REFRESH_TOKEN'] = $refreshToken;
        }

        $this->updateEnvFile($updates);
    }

    private function persistCurrentPublicBaseUrl(): void
    {
        $baseUrl = $this->detectCurrentBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        $this->updateEnvFile([
            'APP_BASE_URL' => $baseUrl,
            'EBAY_WEBHOOK_ENDPOINT' => $baseUrl . '/ebay/ebay.php',
        ]);
    }

    private function detectCurrentBaseUrl(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return $this->config->appBaseUrl;
        }

        $scheme = 'http';
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            $scheme = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }

    /** @param array<string, string> $updates */
    private function updateEnvFile(array $updates): void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        $lines = is_file($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $seen = [];
        foreach ($lines as $index => $line) {
            $parts = explode('=', (string) $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            if (!array_key_exists($key, $updates)) {
                continue;
            }
            $lines[$index] = $key . '=' . $this->quoteEnvValue($updates[$key]);
            $seen[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . $this->quoteEnvValue($value);
            }
        }

        file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function quoteEnvValue(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /** @param array<string, mixed> $store */
    private function renderStorePanel(array $store): string
    {
        $body = (array) ($store['body'] ?? []);
        $headline = $this->storeHeadline($store);
        $statusBadge = $store['ok'] ?? false
            ? '<span class="badge good">Store endpoint live</span>'
            : '<span class="badge warn">' . htmlspecialchars($this->statusLabel((int) ($store['status'] ?? 500)), ENT_QUOTES, 'UTF-8') . '</span>';

        $details = [
            'Account access' => $this->statusLabel((int) ($store['status'] ?? 500)),
            'Token source' => $this->tokenSourceLabel((string) ($store['token_source'] ?? 'none')),
            'Store URL' => (string) ($body['storeUrl'] ?? 'Not available'),
            'Store name' => (string) ($body['storeName'] ?? 'Not available'),
        ];

        if (!($store['ok'] ?? false) && ($store['error'] ?? '') !== '') {
            $details['API note'] = (string) $store['error'];
        }

        $html = '<div class="store-box">
          <div>' . $statusBadge . '</div>
          <p class="store-main">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</p>
          <div class="mini">' . htmlspecialchars($this->storeSubline($store), ENT_QUOTES, 'UTF-8') . '</div>
          <div class="kv">';

        foreach ($details as $key => $value) {
            $html .= '<div class="kv-item"><div class="kv-key">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</div><div>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div></div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /** @param list<mixed> $items */
    private function renderInventoryRows(array $items): string
    {
        if ($items === []) {
            return '<div class="empty">No inventory items came back from eBay right now. The connection still works, but there are no listing records in this response.</div>';
        }

        $rows = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = (string) ($item['sku'] ?? 'Unknown SKU');
            $title = (string) (($item['product']['title'] ?? $item['availability']['pickupAtLocationAvailability'][0]['merchantLocationKey'] ?? 'No title'));
            $condition = (string) ($item['condition'] ?? 'Not set');
            $quantity = (string) (($item['availability']['shipToLocationAvailability']['quantity'] ?? '0'));

            $rows .= '<tr>
              <td><strong>' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($condition, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($quantity, ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Title</th><th>Condition</th><th>Quantity</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param list<array<string, mixed>> $cards */
    private function renderTraditionalListingGallery(array $cards, string $error, string $selectedItemId = ''): string
    {
        if ($cards === []) {
            return '<div class="empty">' . htmlspecialchars($error !== '' ? $error : 'No active regular listings were returned.', ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $html = '<div class="listing-gallery">';
        foreach ($cards as $card) {
            $imageHtml = trim((string) ($card['image'] ?? '')) !== ''
                ? '<img class="listing-card-image" src="' . htmlspecialchars((string) $card['image'], ENT_QUOTES, 'UTF-8') . '" alt="Listing image">'
                : '<div class="listing-card-placeholder">No image returned for this listing</div>';

            $specificPills = '';
            foreach ((array) ($card['specifics'] ?? []) as $name => $value) {
                $specificPills .= '<span class="badge neutral">' . htmlspecialchars((string) $name . ': ' . (string) $value, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            if ((int) ($card['variationCount'] ?? 0) > 0) {
                $specificPills .= '<span class="badge good">' . (int) $card['variationCount'] . ' variations</span>';
            }

            $isSelected = $selectedItemId !== '' && (string) ($card['itemId'] ?? '') === $selectedItemId;

            $detailUrl = $this->appUrl(['page' => 'listing-detail', 'listing_id' => (string) ($card['itemId'] ?? '')]);
            $selectionControl = '<label class="card-select">
              <input form="bulk-end-form" class="listing-selector" type="checkbox" name="item_ids[]" value="' . htmlspecialchars((string) ($card['itemId'] ?? ''), ENT_QUOTES, 'UTF-8') . '" onchange="updateBulkSelectionCount()">
              <span>Select</span>
            </label>';
            $title = $this->truncateText((string) ($card['title'] ?? ''), 28);

            $html .= '<article class="listing-card' . ($isSelected ? ' selected' : '') . '">
              <div class="card-topline">' . $selectionControl . '<span class="badge neutral">' . htmlspecialchars((string) ($card['listingType'] ?? 'FixedPriceItem'), ENT_QUOTES, 'UTF-8') . '</span></div>
              <a href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '">' . $imageHtml . '</a>
              <h4 class="listing-card-title"><a href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></h4>
              <div class="listing-meta">
                <div><strong>Price:</strong> ' . htmlspecialchars((string) ($card['price'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') . '</div>
                <div><strong>Available:</strong> ' . htmlspecialchars((string) ($card['available'] ?? '0'), ENT_QUOTES, 'UTF-8') . ' | <strong>Watchers:</strong> ' . htmlspecialchars((string) ($card['watchers'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</div>
              </div>
              <div class="pill-row">
                <span class="badge neutral">' . htmlspecialchars((string) ($card['condition'] ?? 'Condition unknown'), ENT_QUOTES, 'UTF-8') . '</span>
                ' . $specificPills . '
              </div>
            </article>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string, mixed> $body */
    private function renderTraditionalListingPagination(array $body): string
    {
        $currentPage = max(1, (int) ($body['page'] ?? 1));
        $pageSize = max(1, (int) ($body['pageSize'] ?? 5));
        $total = max(0, (int) ($body['total'] ?? 0));
        $totalPages = max(1, (int) ($body['totalPages'] ?? (int) ceil($total / $pageSize)));
        if ($totalPages <= 1) {
            return '<div class="mini">Showing the latest ' . min($pageSize, $total) . ' listing' . (min($pageSize, $total) === 1 ? '' : 's') . '.</div>';
        }

        $pages = [1, $totalPages];
        for ($page = $currentPage - 2; $page <= $currentPage + 2; $page++) {
            if ($page >= 1 && $page <= $totalPages) {
                $pages[] = $page;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '<div class="toolbar-row"><div class="mini">Page ' . $currentPage . ' of ' . $totalPages . ' (' . $total . ' listings)</div><div class="actions">';
        $previous = null;
        foreach ($pages as $page) {
            if ($previous !== null && $page > $previous + 1) {
                $html .= '<span class="mini" style="padding:0 6px">...</span>';
            }
            $html .= '<a class="button ' . ($page === $currentPage ? 'button-primary' : 'button-secondary') . '" href="' . htmlspecialchars($this->appUrl([
                'page' => 'listings',
                'cj_query' => (string) ($_GET['cj_query'] ?? ''),
                'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
                'cj_category' => (string) ($_GET['cj_category'] ?? ''),
                'cj_pid' => (string) ($_GET['cj_pid'] ?? ''),
                'cj_page' => (string) ($_GET['cj_page'] ?? '1'),
                'cj_page_size' => (string) ($_GET['cj_page_size'] ?? '20'),
                'cj_sort' => (string) ($_GET['cj_sort'] ?? 'desc'),
                'listing_page' => (string) $page,
            ]), ENT_QUOTES, 'UTF-8') . '">' . $page . '</a>';
            $previous = $page;
        }
        $nextDisabled = $currentPage >= $totalPages;
        $html .= '<a class="button button-secondary" href="' . htmlspecialchars($this->appUrl([
            'page' => 'listings',
            'cj_query' => (string) ($_GET['cj_query'] ?? ''),
            'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
            'cj_category' => (string) ($_GET['cj_category'] ?? ''),
            'cj_pid' => (string) ($_GET['cj_pid'] ?? ''),
            'cj_page' => (string) ($_GET['cj_page'] ?? '1'),
            'cj_page_size' => (string) ($_GET['cj_page_size'] ?? '20'),
            'cj_sort' => (string) ($_GET['cj_sort'] ?? 'desc'),
            'listing_page' => (string) min($totalPages, $currentPage + 1),
        ]), ENT_QUOTES, 'UTF-8') . '" ' . ($nextDisabled ? 'style="opacity:.5;pointer-events:none"' : '') . '>Next</a>';
        $html .= '</div></div>';

        return $html;
    }

    /** @param list<mixed> $offers */
    private function renderOfferRows(array $offers): string
    {
        if ($offers === []) {
            return '<div class="empty">No offers were returned. That usually means there are no publish-ready offers in the current token view.</div>';
        }

        $rows = '';
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $sku = (string) ($offer['sku'] ?? 'Unknown SKU');
            $format = (string) ($offer['format'] ?? 'Unknown');
            $marketplace = (string) ($offer['marketplaceId'] ?? 'Unknown');
            $status = (string) ($offer['status'] ?? 'Unknown');
            $offerId = (string) ($offer['offerId'] ?? '');
            $priceData = (array) ($offer['pricingSummary']['price'] ?? $offer['availableQuantity'] ?? []);
            $price = is_array($priceData) && isset($priceData['value'], $priceData['currency'])
                ? (string) $priceData['currency'] . ' ' . (string) $priceData['value']
                : 'Not provided';

            $rows .= '<tr>
              <td><strong>' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars($format, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($price, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . $this->statusBadgeHtml($status) . '<div style="margin-top:10px">' . $this->renderOfferControlForm($offerId, $status) . '</div></td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Format</th><th>Marketplace</th><th>Price</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param list<mixed> $listings */
    private function renderTraditionalListingRows(array $listings, string $error): string
    {
        if ($listings === []) {
            return '<div class="empty">' . htmlspecialchars($error !== '' ? $error : 'No active Trading listings were returned.', ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $rows = '';
        foreach ($listings as $listing) {
            if (!is_array($listing)) {
                continue;
            }
            $rows .= '<tr>
              <td><strong><a href="' . htmlspecialchars($this->appUrl(['page' => 'listing-detail', 'listing_id' => (string) ($listing['itemId'] ?? '')]), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($listing['itemId'] ?? ''), ENT_QUOTES, 'UTF-8') . '</a></strong></td>
              <td><a href="' . htmlspecialchars($this->appUrl(['page' => 'listing-detail', 'listing_id' => (string) ($listing['itemId'] ?? '')]), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($listing['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</a></td>
              <td>' . htmlspecialchars((string) ($listing['sku'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($listing['currentPrice'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($listing['quantityAvailable'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($listing['watchCount'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Item ID</th><th>Title</th><th>SKU</th><th>Price</th><th>Available</th><th>Watchers</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param list<mixed> $locations */
    private function renderLocationRows(array $locations): string
    {
        if ($locations === []) {
            return '<div class="empty">No inventory locations were returned for this seller.</div>';
        }

        $rows = '';
        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }
            $address = (array) ($location['location']['address'] ?? $location['merchantLocationStatus'] ?? []);
            $city = (string) ($address['city'] ?? '');
            $state = (string) ($address['stateOrProvince'] ?? '');
            $country = (string) ($address['country'] ?? '');
            $rows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($location['merchantLocationKey'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars(trim(implode(', ', array_filter([$city, $state, $country]))), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($location['merchantLocationStatus'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Location key</th><th>Area</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param list<mixed> $orders */
    private function renderOrderRows(array $orders): string
    {
        if ($orders === []) {
            return '<div class="empty">No recent orders were returned in the current query window.</div>';
        }

        $rows = '';
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (string) ($order['orderId'] ?? 'Unknown');
            $created = (string) ($order['creationDate'] ?? 'Unknown');
            $status = (string) ($order['orderFulfillmentStatus'] ?? 'Unknown');
            $amount = (array) ($order['pricingSummary']['total'] ?? []);
            $total = isset($amount['currency'], $amount['value'])
                ? (string) $amount['currency'] . ' ' . (string) $amount['value']
                : 'Not provided';

            $rows .= '<tr>
              <td><strong><a href="' . htmlspecialchars($this->appUrl(['page' => 'orders', 'order_id' => $orderId]), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '</a></strong></td>
              <td>' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($total, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . $this->statusBadgeHtml($status) . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Order ID</th><th>Created</th><th>Total</th><th>Fulfillment</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param array<string, mixed>|null $order */
    private function renderOrderDetailPanel(?array $order): string
    {
        if ($order === null) {
            return '<div class="empty">Open an order from the Orders table to inspect line items, shipment packages, and refund history.</div>';
        }
        if (isset($order['error'])) {
            return '<div class="empty">' . htmlspecialchars((string) $order['error'], ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $lineItems = (array) ($order['lineItems'] ?? []);
        $fulfillments = (array) ($order['_shippingFulfillments'] ?? []);
        $refunds = (array) (($order['paymentSummary']['refunds'] ?? []));

        $lineRows = '';
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }
            $lineRows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($lineItem['lineItemId'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($lineItem['title'] ?? $lineItem['sku'] ?? 'Line item'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($lineItem['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($lineItem['lineItemFulfillmentStatus'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }
        $lineHtml = $lineRows !== '' ? '<div class="table-wrap"><table><thead><tr><th>Line item</th><th>Title</th><th>Qty</th><th>Status</th></tr></thead><tbody>' . $lineRows . '</tbody></table></div>' : '<div class="empty">No line items were returned.</div>';

        $shipRows = '';
        foreach ($fulfillments as $fulfillment) {
            if (!is_array($fulfillment)) {
                continue;
            }
            $shipRows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($fulfillment['fulfillmentId'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($fulfillment['shippingCarrierCode'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($fulfillment['trackingNumber'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($fulfillment['shippedDate'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }
        $shipHtml = $shipRows !== '' ? '<div class="table-wrap"><table><thead><tr><th>Fulfillment ID</th><th>Carrier</th><th>Tracking</th><th>Shipped</th></tr></thead><tbody>' . $shipRows . '</tbody></table></div>' : '<div class="empty">No shipping packages have been created for this order yet.</div>';

        $refundRows = '';
        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $amount = (array) ($refund['amount'] ?? []);
            $refundRows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($refund['refundId'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($refund['refundStatus'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((isset($amount['currency'], $amount['value']) ? $amount['currency'] . ' ' . $amount['value'] : 'Not available'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars((string) ($refund['refundDate'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }
        $refundHtml = $refundRows !== '' ? '<div class="table-wrap"><table><thead><tr><th>Refund ID</th><th>Status</th><th>Amount</th><th>Date</th></tr></thead><tbody>' . $refundRows . '</tbody></table></div>' : '<div class="empty">No refund history was returned for this order.</div>';

        return '<div class="action-stack">
          <div class="subcard"><h4 style="margin:0 0 12px">Line items</h4>' . $lineHtml . '</div>
          <div class="subcard"><h4 style="margin:0 0 12px">Shipping packages</h4>' . $shipHtml . '</div>
          <div class="subcard"><h4 style="margin:0 0 12px">Refund history</h4>' . $refundHtml . '</div>
        </div>';
    }

    /** @param list<mixed> $campaigns */
    private function renderCampaignRows(array $campaigns): string
    {
        if ($campaigns === []) {
            return '<div class="empty">No marketing campaigns were returned from eBay.</div>';
        }

        $rows = '';
        foreach ($campaigns as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }
            $name = (string) ($campaign['campaignName'] ?? 'Unnamed campaign');
            $status = (string) ($campaign['campaignStatus'] ?? 'Unknown');
            $type = (string) ($campaign['campaignTargetingType'] ?? $campaign['fundingStrategy']['fundingModel'] ?? 'Unknown');
            $marketplace = (string) ($campaign['marketplaceId'] ?? 'Unknown');
            $budget = $this->formatCampaignBudget((array) ($campaign['budget'] ?? []));

            $rows .= '<tr>
              <td><strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($budget, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . $this->statusBadgeHtml($status) . '<div style="margin-top:10px">' . $this->renderCampaignControlForm((string) ($campaign['campaignId'] ?? ''), $status) . '</div></td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Campaign</th><th>Type</th><th>Marketplace</th><th>Budget</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param array<string, mixed> $fulfillmentPolicies @param array<string, mixed> $paymentPolicies @param array<string, mixed> $returnPolicies */
    private function renderPolicyBlocks(array $fulfillmentPolicies, array $paymentPolicies, array $returnPolicies): string
    {
        $columns = [
            'Fulfillment policies' => (array) (($fulfillmentPolicies['body'] ?? [])['fulfillmentPolicies'] ?? []),
            'Payment policies' => (array) (($paymentPolicies['body'] ?? [])['paymentPolicies'] ?? []),
            'Return policies' => (array) (($returnPolicies['body'] ?? [])['returnPolicies'] ?? []),
        ];

        $html = '<div class="policy-columns">';
        foreach ($columns as $title => $items) {
            $html .= '<div class="policy-card"><h4>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>';
            if ($items === []) {
                $html .= '<div class="mini">No policies returned.</div>';
            } else {
                $html .= '<ul>';
                $count = 0;
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? $item['marketplaceId'] ?? 'Unnamed policy');
                    $html .= '<li>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
                    $count++;
                    if ($count >= 6) {
                        break;
                    }
                }
                $html .= '</ul>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param list<mixed> $subscriptions */
    private function renderNotificationRows(array $subscriptions): string
    {
        if ($subscriptions === []) {
            return '<div class="empty">No notification subscriptions were returned.</div>';
        }

        $rows = '';
        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }
            $topic = (string) ($subscription['topicId'] ?? 'Unknown');
            $status = (string) ($subscription['status'] ?? 'Unknown');
            $created = (string) ($subscription['creationDate'] ?? 'Unknown');
            $destination = (string) ($subscription['destinationId'] ?? 'Unknown');

            $rows .= '<tr>
              <td><strong>' . htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . $this->statusBadgeHtml($status) . '</td>
              <td>' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Topic</th><th>Status</th><th>Created</th><th>Destination</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param list<mixed> $disputes */
    private function renderPaymentDisputeRows(array $disputes): string
    {
        if ($disputes === []) {
            return '<div class="empty">No payment disputes were returned for this account.</div>';
        }

        $rows = '';
        foreach ($disputes as $dispute) {
            if (!is_array($dispute)) {
                continue;
            }
            $rows .= '<tr>
              <td><strong>' . htmlspecialchars((string) ($dispute['paymentDisputeId'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></td>
              <td>' . htmlspecialchars((string) ($dispute['orderId'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
              <td>' . $this->statusBadgeHtml((string) ($dispute['paymentDisputeStatus'] ?? 'Unknown')) . '</td>
              <td>' . htmlspecialchars((string) ($dispute['openDate'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Dispute ID</th><th>Order ID</th><th>Status</th><th>Opened</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param array<string, mixed>|null $listing */
    private function renderListingDetailPanel(?array $listing): string
    {
        if ($listing === null) {
            return '<div class="empty">Open a listing from the Regular active listings gallery to inspect images, description, item specifics, and variations.</div>';
        }
        if (isset($listing['error'])) {
            return '<div class="empty">' . htmlspecialchars((string) $listing['error'], ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $item = isset($listing['Item']) && is_array($listing['Item']) ? (array) $listing['Item'] : $listing;
        $title = (string) ($item['Title'] ?? 'Listing detail');
        $pictures = $this->normalizeStrings($item['PictureDetails']['PictureURL'] ?? []);
        $specifics = $this->normalizeNameValueList($item['ItemSpecifics']['NameValueList'] ?? []);
        $variations = $this->normalizeVariations($item['Variations']['Variation'] ?? []);
        $description = trim(strip_tags((string) ($item['Description'] ?? '')));

        $imageHtml = '';
        if ($pictures !== []) {
            foreach ($this->safeArraySlice($pictures, 0, 8) as $picture) {
                $imageHtml .= '<img src="' . htmlspecialchars($picture, ENT_QUOTES, 'UTF-8') . '" alt="Listing image">';
            }
            $imageHtml = '<div class="image-strip">' . $imageHtml . '</div>';
        } else {
            $imageHtml = '<div class="empty">No images were returned for this listing.</div>';
        }

        $specificRows = '';
        foreach ($specifics as $name => $value) {
            $specificRows .= '<tr><td><strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong></td><td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $specificHtml = $specificRows !== '' ? '<div class="table-wrap"><table><thead><tr><th>Specific</th><th>Value</th></tr></thead><tbody>' . $specificRows . '</tbody></table></div>' : '<div class="empty">No item specifics were returned.</div>';

        $variationRows = '';
        foreach ($variations as $variation) {
            $variationRows .= '<tr><td><strong>' . htmlspecialchars((string) ($variation['sku'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></td><td>' . htmlspecialchars((string) ($variation['price'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($variation['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($variation['specifics'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $variationHtml = $variationRows !== '' ? '<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Price</th><th>Quantity</th><th>Specifics</th></tr></thead><tbody>' . $variationRows . '</tbody></table></div>' : '<div class="empty">No variations were returned for this listing.</div>';

        return '<div class="action-stack">
          <div class="subcard">
            <h4 style="margin:0 0 10px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>
            <div class="kv">
              <div class="kv-item"><div class="kv-key">Item ID</div><div>' . htmlspecialchars((string) ($item['ItemID'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">SKU</div><div>' . htmlspecialchars((string) ($item['SKU'] ?? 'Not set'), ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">Price</div><div>' . htmlspecialchars($this->formatTradingPrice($item['StartPrice'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">Quantity</div><div>' . htmlspecialchars((string) ($item['Quantity'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</div></div>
            </div>
          </div>
          <div class="subcard">' . $imageHtml . '</div>
          <div class="detail-grid">
            <div class="subcard"><h4 style="margin:0 0 12px">Item specifics</h4>' . $specificHtml . '</div>
            <div class="subcard"><h4 style="margin:0 0 12px">Variations</h4>' . $variationHtml . '</div>
          </div>
          <div class="subcard"><h4 style="margin:0 0 12px">Description</h4><div class="description-box">' . htmlspecialchars($description !== '' ? $description : 'No description was returned.', ENT_QUOTES, 'UTF-8') . '</div></div>
        </div>';
    }

    /** @param array<string, mixed>|null $listing */
    private function renderListingActionPanel(?array $listing, string $mode = 'all'): string
    {
        $itemId = '';
        $title = '';
        $price = '';
        $quantity = '';
        $description = '';
        $sku = '';
        if (is_array($listing) && !isset($listing['error'])) {
            $item = isset($listing['Item']) && is_array($listing['Item']) ? (array) $listing['Item'] : $listing;
            $itemId = (string) ($item['ItemID'] ?? '');
            $title = (string) ($item['Title'] ?? '');
            $price = $this->formatTradingPrice($item['StartPrice'] ?? '');
            $quantity = (string) ($item['Quantity'] ?? '');
            $description = trim(strip_tags((string) ($item['Description'] ?? '')));
            $sku = (string) ($item['SKU'] ?? '');
        }

        $forms = [];
        if ($mode !== 'create') {
            $forms[] = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="subcard">
            <input type="hidden" name="manage_action" value="revise_regular_listing">
            <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'listing-detail', 'listing_id' => $itemId]), ENT_QUOTES, 'UTF-8') . '">
            <h4 style="margin:0 0 12px">Revise selected listing</h4>
            <div class="form-grid">
              <label>Item ID<input type="text" name="item_id" value="' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') . '" required></label>
              <label>Quantity<input type="number" name="quantity" value="' . htmlspecialchars($quantity, ENT_QUOTES, 'UTF-8') . '" min="0"></label>
              <label>Title<input type="text" name="title" value="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"></label>
              <label>Price<input type="text" name="price" value="' . htmlspecialchars($price, ENT_QUOTES, 'UTF-8') . '" placeholder="19.99"></label>
            </div>
            <label>Description<textarea name="description">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</textarea></label>
            <label>Image URLs, one per line<textarea name="picture_urls" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg"></textarea></label>
            <div class="actions"><button class="button button-primary" type="submit">Revise listing</button></div>
          </form>';

            $forms[] = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="subcard">
            <input type="hidden" name="manage_action" value="end_regular_listing">
            <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'listings']), ENT_QUOTES, 'UTF-8') . '">
            <h4 style="margin:0 0 12px">End listing</h4>
            <div class="form-grid">
              <label>Item ID<input type="text" name="item_id" value="' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') . '" required></label>
              <label>Reason
                <select name="ending_reason">
                  <option value="NotAvailable">NotAvailable</option>
                  <option value="LostOrBroken">LostOrBroken</option>
                  <option value="Incorrect">Incorrect</option>
                  <option value="SellToHighBidder">SellToHighBidder</option>
                </select>
              </label>
            </div>
            <div class="actions"><button class="button button-danger" type="submit">End listing</button></div>
          </form>';
        }

        if ($mode !== 'detail') {
            $forms[] = '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="subcard">
            <input type="hidden" name="manage_action" value="create_regular_listing">
            <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'listings']), ENT_QUOTES, 'UTF-8') . '">
            <h4 style="margin:0 0 12px">Create regular listing</h4>
            <div class="form-grid">
              <label>Title<input type="text" name="create_title" required></label>
              <label>SKU<input type="text" name="create_sku" value="' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '"></label>
              <label>Primary category ID<input type="text" name="create_category_id" placeholder="1234" required></label>
              <label>Condition ID<input type="text" name="create_condition_id" value="1000" required></label>
              <label>Price<input type="text" name="create_price" placeholder="19.99" required></label>
              <label>Quantity<input type="number" name="create_quantity" value="1" min="1" required></label>
              <label>Postal code<input type="text" name="create_postal_code" value="10001" required></label>
              <label>PayPal email<input type="email" name="create_paypal_email" placeholder="seller@example.com"></label>
              <label>Shipping service<input type="text" name="create_shipping_service" value="UPSGround"></label>
              <label>Shipping cost<input type="text" name="create_shipping_cost" value="0.0"></label>
            </div>
            <label>Description<textarea name="create_description" placeholder="Describe the product for buyers" required></textarea></label>
            <label>Image URLs, one per line<textarea name="create_picture_urls" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg"></textarea></label>
            <label>Item specifics, one per line as Name=Value<textarea name="create_item_specifics" placeholder="Brand=Example&#10;Color=Black&#10;Material=Elastic"></textarea></label>
            <div class="actions"><button class="button button-primary" type="submit">Create listing</button></div>
          </form>';
        }

        return '<div class="action-stack">' . implode('', $forms) . '</div>';
    }

    /** @param array<string, mixed>|null $order */
    private function renderOrderActionPanel(?array $order): string
    {
        if ($order === null) {
            return '<div class="empty">Open an order from the order table to manage fulfillment and refunds.</div>';
        }
        if (isset($order['error'])) {
            return '<div class="empty">' . htmlspecialchars((string) $order['error'], ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $orderId = (string) ($order['orderId'] ?? '');
        $lineItems = $this->normalizeOrderLineItems((array) ($order['lineItems'] ?? []));
        $selectedLineItem = $lineItems[0]['lineItemId'] ?? '';
        $options = '';
        foreach ($lineItems as $lineItem) {
            $options .= '<option value="' . htmlspecialchars($lineItem['lineItemId'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lineItem['label'], ENT_QUOTES, 'UTF-8') . '</option>';
        }

        return '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="action-stack">
          <input type="hidden" name="manage_action" value="create_shipping_fulfillment">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'orders', 'order_id' => $orderId]), ENT_QUOTES, 'UTF-8') . '">
          <div class="subcard">
            <div class="kv">
              <div class="kv-item"><div class="kv-key">Order ID</div><div>' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '</div></div>
              <div class="kv-item"><div class="kv-key">Buyer</div><div>' . htmlspecialchars((string) (($order['buyer']['username'] ?? 'Unknown')), ENT_QUOTES, 'UTF-8') . '</div></div>
            </div>
          </div>
          <div class="subcard">
            <div class="form-grid">
              <label>Order ID<input type="text" name="order_id" value="' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '" required></label>
              <label>Line item<select name="line_item_id" required>' . $options . '</select></label>
              <label>Quantity<input type="number" name="quantity" value="1" min="1"></label>
              <label>Carrier code<input type="text" name="shipping_carrier_code" placeholder="USPS"></label>
              <label>Tracking number<input type="text" name="tracking_number" placeholder="9400..."></label>
              <label>Shipped date<input type="text" name="shipped_date" value="' . htmlspecialchars(gmdate('c'), ENT_QUOTES, 'UTF-8') . '"></label>
            </div>
            <div class="actions"><button class="button button-primary" type="submit">Create shipping fulfillment</button></div>
          </div>
        </form>';
    }

    /** @param array<string, mixed>|null $order */
    private function renderRefundActionPanel(?array $order): string
    {
        if ($order === null) {
            return '<div class="empty">Open an order from the order table to issue a refund.</div>';
        }
        if (isset($order['error'])) {
            return '<div class="empty">' . htmlspecialchars((string) $order['error'], ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $orderId = (string) ($order['orderId'] ?? '');
        $currency = (string) (($order['pricingSummary']['total']['currency'] ?? 'USD'));
        $lineItems = $this->normalizeOrderLineItems((array) ($order['lineItems'] ?? []));
        $options = '<option value="">Order-level refund</option>';
        foreach ($lineItems as $lineItem) {
            $options .= '<option value="' . htmlspecialchars($lineItem['lineItemId'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lineItem['label'], ENT_QUOTES, 'UTF-8') . '</option>';
        }

        return '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="action-stack">
          <input type="hidden" name="manage_action" value="issue_refund">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'orders', 'order_id' => $orderId]), ENT_QUOTES, 'UTF-8') . '">
          <div class="subcard">
            <div class="form-grid">
              <label>Order ID<input type="text" name="order_id" value="' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '" required></label>
              <label>Reason
                <select name="reason_for_refund">
                  <option value="BUYER_CANCEL">BUYER_CANCEL</option>
                  <option value="OUT_OF_STOCK">OUT_OF_STOCK</option>
                  <option value="CORRECTIVE">CORRECTIVE</option>
                  <option value="OTHER_ADJUSTMENT">OTHER_ADJUSTMENT</option>
                </select>
              </label>
              <label>Line item<select name="line_item_id">' . $options . '</select></label>
              <label>Refund amount<input type="text" name="refund_amount" placeholder="10.00"></label>
              <label>Currency<input type="text" name="refund_currency" value="' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . '"></label>
            </div>
            <label>Comment<textarea name="refund_comment" placeholder="Optional refund note"></textarea></label>
            <div class="actions"><button class="button button-primary" type="submit">Issue refund</button></div>
          </div>
        </form>';
    }

    private function renderCampaignControlForm(string $campaignId, string $status): string
    {
        if ($campaignId === '') {
            return '';
        }

        $buttons = '';
        $upper = strtoupper($status);
        if ($upper === 'RUNNING') {
            $buttons .= $this->campaignButton($campaignId, 'pause', 'Pause');
            $buttons .= $this->campaignButton($campaignId, 'end', 'End');
        } elseif ($upper === 'PAUSED') {
            $buttons .= $this->campaignButton($campaignId, 'resume', 'Resume');
            $buttons .= $this->campaignButton($campaignId, 'end', 'End');
        } else {
            $buttons .= $this->campaignButton($campaignId, 'resume', 'Resume');
        }

        return '<div class="inline-form">' . $buttons . '</div>';
    }

    private function renderOfferControlForm(string $offerId, string $status): string
    {
        if ($offerId === '') {
            return '';
        }
        $upper = strtoupper($status);
        $operation = str_contains($upper, 'PUBLISHED') ? 'withdraw' : 'publish';
        $label = $operation === 'publish' ? 'Publish' : 'Withdraw';

        return '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
          <input type="hidden" name="manage_action" value="offer_control">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'offers']), ENT_QUOTES, 'UTF-8') . '">
          <input type="hidden" name="offer_id" value="' . htmlspecialchars($offerId, ENT_QUOTES, 'UTF-8') . '">
          <input type="hidden" name="offer_operation" value="' . htmlspecialchars($operation, ENT_QUOTES, 'UTF-8') . '">
          <button class="button button-secondary" type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>
        </form>';
    }

    private function campaignButton(string $campaignId, string $operation, string $label): string
    {
        return '<form method="post" action="' . htmlspecialchars($this->manageUrl(), ENT_QUOTES, 'UTF-8') . '" class="inline-form">
          <input type="hidden" name="manage_action" value="campaign_control">
          <input type="hidden" name="redirect" value="' . htmlspecialchars($this->appUrl(['page' => 'marketing']), ENT_QUOTES, 'UTF-8') . '">
          <input type="hidden" name="campaign_id" value="' . htmlspecialchars($campaignId, ENT_QUOTES, 'UTF-8') . '">
          <input type="hidden" name="campaign_operation" value="' . htmlspecialchars($operation, ENT_QUOTES, 'UTF-8') . '">
          <button class="button button-secondary" type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>
        </form>';
    }

    private function renderIntegrationCards(): string
    {
        $cards = [
            [
                'name' => 'CJdropshipping',
                'status' => 'Not connected',
                'note' => 'Ready for supplier sync once CJ credentials and workflow rules are added.',
                'link' => 'https://app.cjdropshipping.com/',
                'cta' => 'Open CJdropshipping',
            ],
            [
                'name' => 'AutoDS',
                'status' => 'Not connected',
                'note' => 'Use this space for automated catalog sync and repricing later.',
                'link' => 'https://platform.autods.com/',
                'cta' => 'Open AutoDS',
            ],
            [
                'name' => 'ShipStation',
                'status' => 'Not connected',
                'note' => 'Good fit for shipping and fulfillment workflows after API keys are added.',
                'link' => 'https://ship.shipstation.com/',
                'cta' => 'Open ShipStation',
            ],
            [
                'name' => 'Webhook automation',
                'status' => 'Live endpoint ready',
                'note' => 'Your current webhook endpoint can fan out to third-party automations as the next step.',
                'link' => $this->config->webhookEndpoint !== '' ? $this->config->webhookEndpoint : '/ebay/ebay.php',
                'cta' => 'Open webhook URL',
            ],
        ];

        $html = '<div class="integration-grid">';
        foreach ($cards as $card) {
            $tone = $card['status'] === 'Live endpoint ready' ? 'good' : 'warn';
            $html .= '<div class="integration-card">
              <span class="badge ' . $tone . '">' . htmlspecialchars($card['status'], ENT_QUOTES, 'UTF-8') . '</span>
              <h4>' . htmlspecialchars($card['name'], ENT_QUOTES, 'UTF-8') . '</h4>
              <p>' . htmlspecialchars($card['note'], ENT_QUOTES, 'UTF-8') . '</p>
              <a href="' . htmlspecialchars($card['link'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noreferrer">' . htmlspecialchars($card['cta'], ENT_QUOTES, 'UTF-8') . '</a>
            </div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function handleCreateRegularListing(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to create a regular listing.');
        }

        $title = trim((string) ($_POST['create_title'] ?? ''));
        $categoryId = trim((string) ($_POST['create_category_id'] ?? ''));
        $conditionId = trim((string) ($_POST['create_condition_id'] ?? ''));
        $price = trim((string) ($_POST['create_price'] ?? ''));
        $quantity = trim((string) ($_POST['create_quantity'] ?? '1'));
        $postalCode = trim((string) ($_POST['create_postal_code'] ?? ''));
        $description = trim((string) ($_POST['create_description'] ?? ''));

        if ($title === '' || $categoryId === '' || $conditionId === '' || $price === '' || $postalCode === '' || $description === '') {
            throw new RuntimeException('Title, category ID, condition ID, price, postal code, and description are required.');
        }
        $this->assertEbayLeafCategoryId($categoryId);

        $payload = [
            'Title' => $title,
            'PrimaryCategory' => ['CategoryID' => $categoryId],
            'StartPrice' => $price,
            'CategoryMappingAllowed' => 'true',
            'ConditionID' => $conditionId,
            'Country' => 'US',
            'Currency' => 'USD',
            'DispatchTimeMax' => '3',
            'ListingDuration' => 'GTC',
            'ListingType' => 'FixedPriceItem',
            'PostalCode' => $postalCode,
            'Quantity' => $quantity,
            'Description' => $description,
            'Site' => 'US',
            'ReturnPolicy' => [
                'ReturnsAcceptedOption' => 'ReturnsAccepted',
                'RefundOption' => 'MoneyBack',
                'ReturnsWithinOption' => 'Days_30',
                'ShippingCostPaidByOption' => 'Buyer',
            ],
            'ShippingDetails' => [
                'ShippingType' => 'Flat',
                'ShippingServiceOptions' => [
                    'ShippingServicePriority' => '1',
                    'ShippingService' => trim((string) ($_POST['create_shipping_service'] ?? 'UPSGround')),
                    'ShippingServiceCost' => trim((string) ($_POST['create_shipping_cost'] ?? '0.0')),
                ],
            ],
        ];

        $sku = trim((string) ($_POST['create_sku'] ?? ''));
        if ($sku !== '') {
            $payload['SKU'] = $sku;
        }

        $paypalEmail = trim((string) ($_POST['create_paypal_email'] ?? ''));
        if ($paypalEmail !== '') {
            $payload['PaymentMethods'] = 'PayPal';
            $payload['PayPalEmailAddress'] = $paypalEmail;
        }

        $pictureUrls = $this->parseMultilineList((string) ($_POST['create_picture_urls'] ?? ''));
        if ($pictureUrls !== []) {
            $payload['PictureDetails'] = ['PictureURL' => $pictureUrls];
        }

        $specifics = $this->parseNameValueLines((string) ($_POST['create_item_specifics'] ?? ''));
        if ($specifics !== []) {
            $payload['ItemSpecifics'] = ['NameValueList' => $specifics];
        }

        $result = $this->tradingService->createListing($token, $payload);
        return 'Listing create request sent. Response Ack: ' . (string) ($result['Ack'] ?? 'Unknown');
    }

    private function handleReviseRegularListing(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to revise a listing.');
        }

        $itemId = trim((string) ($_POST['item_id'] ?? ''));
        if ($itemId === '') {
            throw new RuntimeException('Item ID is required.');
        }

        $fields = [];
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            $fields['Title'] = $title;
        }
        $price = trim((string) ($_POST['price'] ?? ''));
        if ($price !== '') {
            $fields['StartPrice'] = preg_replace('/^[A-Z]{3}\s+/i', '', $price) ?? $price;
        }
        $quantity = trim((string) ($_POST['quantity'] ?? ''));
        if ($quantity !== '') {
            $fields['Quantity'] = (int) $quantity;
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description !== '') {
            $fields['Description'] = $description;
        }
        $pictureUrls = $this->parseMultilineList((string) ($_POST['picture_urls'] ?? ''));
        if ($pictureUrls !== []) {
            $fields['PictureDetails'] = ['PictureURL' => $pictureUrls];
        }
        if ($fields === []) {
            throw new RuntimeException('Provide at least one listing field to revise.');
        }

        $result = $this->tradingService->reviseListing($token, $itemId, $fields);
        return 'Listing revised. Response Ack: ' . (string) ($result['Ack'] ?? 'Unknown');
    }

    private function handleEndRegularListing(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to end a listing.');
        }

        $itemId = trim((string) ($_POST['item_id'] ?? ''));
        $reason = trim((string) ($_POST['ending_reason'] ?? 'NotAvailable'));
        $result = $this->tradingService->endListing($token, $itemId, $reason);
        if ($itemId !== '') {
            $this->cjMappingRepository->deleteListingMapByItemId($itemId);
        }

        return 'Listing ended. Response Ack: ' . (string) ($result['Ack'] ?? 'Unknown');
    }

    private function handleBulkEndRegularListings(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to bulk end listings.');
        }

        $itemIds = array_values(array_filter(array_map('strval', (array) ($_POST['item_ids'] ?? []))));
        if ($itemIds === []) {
            throw new RuntimeException('Select at least one listing to bulk end.');
        }

        $reason = trim((string) ($_POST['ending_reason'] ?? 'NotAvailable'));
        $success = 0;
        $failures = [];
        $notes = [];
        foreach ($itemIds as $itemId) {
            try {
                $this->tradingService->endListing($token, $itemId, $reason);
                $this->cjMappingRepository->deleteListingMapByItemId($itemId);
                $success++;
            } catch (Throwable $throwable) {
                $failures[] = $itemId . ': ' . $throwable->getMessage();
            }
        }

        $message = 'Bulk end finished. ' . $success . ' listing(s) ended.';
        if ($failures !== []) {
            $message .= ' Failed: ' . implode(' | ', $this->safeArraySlice($failures, 0, 3));
        }
        return $message;
    }

    private function handleCjAuthenticate(): string
    {
        if ($this->config->cjApiKey === '') {
            throw new RuntimeException('CJ_API_KEY is missing from .env.');
        }

        $response = $this->cjService->authenticate($this->config->cjApiKey);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
        $accessToken = (string) ($data['accessToken'] ?? '');
        $refreshToken = (string) ($data['refreshToken'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('CJ did not return an access token.');
        }

        $this->persistCjTokensToEnv($accessToken, $refreshToken);
        return 'CJ connected. Access and refresh tokens were saved into .env.';
    }

    private function handleCjRefresh(): string
    {
        if ($this->config->cjRefreshToken === '') {
            throw new RuntimeException('CJ_REFRESH_TOKEN is missing from .env.');
        }

        $response = $this->cjService->refreshAccessToken($this->config->cjRefreshToken);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
        $accessToken = (string) ($data['accessToken'] ?? '');
        $refreshToken = (string) ($data['refreshToken'] ?? $this->config->cjRefreshToken);
        if ($accessToken === '') {
            throw new RuntimeException('CJ did not return a refreshed access token.');
        }

        $this->persistCjTokensToEnv($accessToken, $refreshToken);
        return 'CJ token refreshed and saved into .env.';
    }

    private function handleImportCjProductToEbay(): string
    {
        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjAccessToken === '') {
            throw new RuntimeException('CJ access token is required before importing a product to eBay.');
        }

        $ebayTokenSource = 'user';
        $ebayToken = $this->resolveToken('user', $ebayTokenSource);
        if ($ebayToken === '') {
            throw new RuntimeException('A user eBay token is required before importing a CJ product.');
        }

        $pid = trim((string) ($_POST['cj_pid'] ?? ''));
        $countryCode = strtoupper(trim((string) ($_POST['cj_country_code'] ?? 'US')));
        if ($pid === '') {
            throw new RuntimeException('Choose a CJ product before listing it on eBay.');
        }

        $product = $this->loadCjProductDetail($cjAccessToken, $pid, $countryCode);
        $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
        
        if ($variants === []) {
            throw new RuntimeException('No variants found for this CJ product.');
        }
        
        $payload = $this->buildEbayListingPayloadFromCj($product, $variants, $cjAccessToken, $countryCode);
        $result = $this->tradingService->createListing($ebayToken, $payload);
        $itemId = (string) ($result['ItemID'] ?? $result['Item']['ItemID'] ?? '');
        if ($itemId !== '') {
            $this->cjMappingRepository->saveListingMap(
                $itemId,
                $pid,
                array_column($variants, 'vid'),
                $this->extractCjProductTitle($product),
                $this->buildCjSkuVidMap($variants)
            );
        }

        $message = 'CJ product imported to eBay. New item ID: ' . ($itemId !== '' ? $itemId : 'unknown') . '. Mapping saved for future sync.';
        if ($itemId !== '') {
            $promotionMessage = $this->tryCreateVolumePricingPromotion($ebayToken, $itemId);
            if ($promotionMessage !== '') {
                $message .= ' ' . $promotionMessage;
            }
        }
        $warnings = $this->formatTradingWarnings($result);
        if ($warnings !== '') {
            $message .= ' eBay warning: ' . $warnings;
        }

        return $message;
    }

    private function handleBulkImportCjProductToEbay(): string
    {
        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjAccessToken === '') {
            throw new RuntimeException('CJ access token is required before importing bulk products to eBay.');
        }

        $ebayTokenSource = 'user';
        $ebayToken = $this->resolveToken('user', $ebayTokenSource);
        if ($ebayToken === '') {
            throw new RuntimeException('A user eBay token is required before importing CJ products.');
        }

        $pids = array_values(array_filter(array_map('strval', (array) ($_POST['cj_pids'] ?? []))));
        if ($pids === []) {
            throw new RuntimeException('Select at least one CJ product to bulk list on eBay.');
        }

        $categoryId = trim((string) ($_POST['ebay_category_id'] ?? ''));
        $postalCode = trim((string) ($_POST['postal_code'] ?? '10001'));
        $conditionId = trim((string) ($_POST['ebay_condition_id'] ?? '1000'));
        $countryCode = strtoupper(trim((string) ($_POST['cj_country_code'] ?? 'US')));
        if ($categoryId === '') {
            throw new RuntimeException('Choose an eBay category suggestion before bulk listing CJ products.');
        }
        $this->assertEbayLeafCategoryId($categoryId);

        $success = 0;
        $failures = [];
        $notes = [];
        foreach ($pids as $pid) {
            try {
                $product = $this->loadCjProductDetail($cjAccessToken, $pid, $countryCode);
                $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
                if ($variants === []) {
                    continue;
                }

                $payload = $this->buildEbayListingPayloadFromCj($product, $variants, $cjAccessToken, $countryCode);
                $payload['PrimaryCategory'] = ['CategoryID' => $categoryId];
                $payload['ConditionID'] = $conditionId;
                $payload['PostalCode'] = $postalCode;
                $payload['ShippingDetails'] = [
                    'ShippingType' => 'Flat',
                    'ShippingServiceOptions' => [
                        'ShippingServicePriority' => '1',
                        'ShippingService' => 'UPSGround',
                        'ShippingServiceCost' => '0.0',
                        'ShippingServiceAdditionalCost' => '0.0',
                    ],
                ];

                $result = $this->tradingService->createListing($ebayToken, $payload);
                $itemId = (string) ($result['ItemID'] ?? $result['Item']['ItemID'] ?? '');
                if ($itemId !== '') {
                    $this->cjMappingRepository->saveListingMap(
                        $itemId,
                        $pid,
                        array_column($variants, 'vid'),
                        $this->extractCjProductTitle($product),
                        $this->buildCjSkuVidMap($variants)
                    );
                    $success++;
                    $promotionMessage = $this->tryCreateVolumePricingPromotion($ebayToken, $itemId);
                    if ($promotionMessage !== '') {
                        $notes[] = $pid . ': ' . $promotionMessage;
                    }
                }
                $warnings = $this->formatTradingWarnings($result);
                if ($warnings !== '') {
                    $notes[] = $pid . ': ' . $warnings;
                }
            } catch (\Throwable $t) {
                $failures[] = $pid . ': ' . $t->getMessage();
            }
        }

        $msg = 'Bulk import complete. ' . $success . ' products listed.';
        if ($failures !== []) {
            $msg .= ' Failures: ' . implode(' | ', $this->safeArraySlice($failures, 0, 3));
        }
        if ($notes !== []) {
            $msg .= ' eBay notes: ' . implode(' | ', $this->safeArraySlice($notes, 0, 3));
        }
        return $msg;
    }

    private function handleBulkExportCjMarketplaceFiles(): string
    {
        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjAccessToken === '') {
            throw new RuntimeException('CJ access token is required before exporting marketplace files.');
        }

        $pids = array_values(array_filter(array_map('strval', (array) ($_POST['cj_pids'] ?? []))));
        if ($pids === []) {
            throw new RuntimeException('Select at least one CJ product to export.');
        }

        $countryCode = strtoupper(trim((string) ($_POST['cj_country_code'] ?? 'US')));
        $products = [];
        $failures = [];
        foreach ($pids as $pid) {
            try {
                $product = $this->loadCjProductDetail($cjAccessToken, $pid, $countryCode);
                $product['_variants'] = $this->normalizeCjVariants($product['_variants'] ?? []);
                $products[] = $product;
            } catch (Throwable $throwable) {
                $failures[] = $pid . ': ' . $throwable->getMessage();
            }
        }

        if ($products === []) {
            throw new RuntimeException('No selected CJ products could be loaded for export. ' . implode(' | ', $this->safeArraySlice($failures, 0, 3)));
        }

        $marketplaceCategories = $this->buildMarketplaceCategoryMapsForExport($products);
        $ebayAspectsByCategory = [];
        foreach ($marketplaceCategories as $map) {
            $categoryId = trim((string) ($map['ebay_id'] ?? ''));
            if ($categoryId !== '' && !isset($ebayAspectsByCategory[$categoryId])) {
                $ebayAspectsByCategory[$categoryId] = $this->loadEbayAspectRequirements($categoryId);
            }
        }

        $exporter = new CjMarketplaceExportService(new MarketplaceTemplateService());
        $result = $exporter->export($products, [
            'targets' => (array) ($_POST['marketplace_export_targets'] ?? ['ebay', 'facebook', 'tiktok']),
            'ebay_category_id' => trim((string) ($_POST['ebay_category_id'] ?? '')),
            'marketplace_categories' => $marketplaceCategories,
            'ebay_aspects_by_category' => $ebayAspectsByCategory,
            'condition_id' => trim((string) ($_POST['ebay_condition_id'] ?? '1000')),
            'postal_code' => trim((string) ($_POST['postal_code'] ?? '10001')),
            'country_code' => $countryCode,
            'price_overrides' => (array) ($_POST['cj_price_overrides'] ?? []),
            'facebook_category_path' => trim((string) ($_POST['facebook_category_path'] ?? '')),
            'tiktok_category_path' => trim((string) ($_POST['tiktok_category_path'] ?? '')),
            'google_product_category' => trim((string) ($_POST['google_product_category'] ?? '')),
        ]);

        $files = (array) ($result['files'] ?? []);
        if ($files === []) {
            throw new RuntimeException('Marketplace export did not create any files.');
        }
        $_SESSION['ebay_recent_export_files'] = $files;

        $parts = [];
        foreach ($this->safeArraySlice($files, 0, 6) as $file) {
            if (is_array($file)) {
                $parts[] = (string) ($file['relative'] ?? $file['path'] ?? '');
            }
        }

        $message = 'Marketplace export complete for ' . count($products) . ' CJ products. Files: ' . implode(' | ', array_filter($parts));
        $errors = (array) ($result['errors'] ?? []);
        $warnings = (array) ($result['warnings'] ?? []);
        if ($failures !== []) {
            $message .= ' Load failures: ' . implode(' | ', $this->safeArraySlice($failures, 0, 2));
        }
        if ($errors !== []) {
            $message .= ' Validation errors: ' . count($errors) . ' saved in validation CSV.';
        }
        if ($warnings !== []) {
            $message .= ' Warnings: ' . count($warnings) . '.';
        }

        return $message;
    }

    /** @param list<array<string, mixed>> $products @return array<string, array<string, string>> */
    private function buildMarketplaceCategoryMapsForExport(array $products): array
    {
        $maps = [];
        foreach ($products as $product) {
            $pid = trim((string) ($product['pid'] ?? $product['productId'] ?? $product['id'] ?? ''));
            if ($pid === '') {
                continue;
            }

            $seeds = [];
            $title = $this->extractCjProductTitle($product);
            if ($title !== '') {
                $seeds[] = $title;
            }
            $categoryName = $this->firstNonEmptyString($product, ['categoryName', 'category', 'categoryPath', 'categoryFirstName', 'categorySecondName', 'categoryThirdName']);
            if ($categoryName !== '') {
                $seeds[] = $categoryName;
                $parts = preg_split('/\s*(?:>|\/\/|\/)\s*/', $categoryName) ?: [];
                $leaf = trim((string) end($parts));
                if ($leaf !== '' && $leaf !== $categoryName) {
                    $seeds[] = $leaf . ' ' . $title;
                }
            }

            $suggestions = $this->loadEbayCategorySuggestionsFromSeeds($seeds);
            $first = is_array($suggestions[0] ?? null) ? (array) $suggestions[0] : [];
            if ($first !== []) {
                $maps[$pid] = [
                    'ebay_id' => (string) ($first['id'] ?? ''),
                    'ebay_path' => (string) ($first['path'] ?? $first['name'] ?? ''),
                ];
            }
        }

        return $maps;
    }

    private function handleBulkSyncCjInventory(): string
    {
        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjAccessToken === '') {
            throw new RuntimeException('CJ access token is required before syncing inventory.');
        }

        $ebayTokenSource = 'user';
        $ebayToken = $this->resolveToken('user', $ebayTokenSource);
        if ($ebayToken === '') {
            throw new RuntimeException('A user eBay token is required before syncing inventory.');
        }

        $countryCode = strtoupper(trim((string) ($_POST['cj_country_code'] ?? 'US')));
        $limit = max(1, min(250, (int) ($_POST['sync_limit'] ?? 250)));
        $quantityCap = $this->resolveEbayQuantityCap();
        $maps = $this->cjMappingRepository->listAllListingMaps($limit);
        if ($maps === []) {
            throw new RuntimeException('No CJ-to-eBay listing mappings are available to sync.');
        }

        $syncedListings = 0;
        $syncedVariants = 0;
        $failures = [];
        foreach ($maps as $map) {
            $itemId = trim((string) ($map['ebay_item_id'] ?? ''));
            $pid = trim((string) ($map['cj_pid'] ?? ''));
            if ($itemId === '' || $pid === '') {
                continue;
            }

            try {
                $product = $this->loadCjProductDetail($cjAccessToken, $pid, $countryCode);
                $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
                $skuVidMap = json_decode((string) ($map['sku_vid_map_json'] ?? '{}'), true);
                $skuVidMap = is_array($skuVidMap) ? array_map('strval', $skuVidMap) : [];
                $rows = $this->buildEbayInventoryStatusRows($itemId, $variants, $skuVidMap, $quantityCap);
                if ($rows === []) {
                    $failures[] = $itemId . ': no CJ variant inventory was available';
                    continue;
                }

                foreach (array_chunk($rows, 4) as $chunk) {
                    $this->tradingService->reviseInventoryStatus($ebayToken, $chunk);
                }
                $syncedListings++;
                $syncedVariants += count($rows);
            } catch (Throwable $throwable) {
                $failures[] = $itemId . ': ' . $throwable->getMessage();
            }
        }

        $message = 'Inventory sync complete. ' . $syncedListings . ' mapped listings and ' . $syncedVariants . ' variants updated from CJ real inventory with the eBay quantity cap applied.';
        if ($failures !== []) {
            $message .= ' Skipped/failed: ' . implode(' | ', $this->safeArraySlice($failures, 0, 4));
        }

        return $message;
    }

    private function handleManualBulkInventoryUpdate(): string
    {
        $tokenSource = 'user';
        $ebayToken = $this->resolveToken('user', $tokenSource);
        if ($ebayToken === '') {
            throw new RuntimeException('A user eBay token is required before updating inventory.');
        }

        $rawUpdates = trim((string) ($_POST['inventory_updates'] ?? ''));
        if ($rawUpdates === '') {
            throw new RuntimeException('Paste at least one inventory update line.');
        }

        $rows = [];
        $failures = [];
        foreach (preg_split('/\r\n|\r|\n/', $rawUpdates) ?: [] as $lineNumber => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', preg_split('/[|,\t;]+/', $line) ?: []);
            if (count($parts) < 2) {
                $parts = array_map('trim', preg_split('/\s+/', $line) ?: []);
            }

            $itemId = '';
            $sku = '';
            $quantity = null;
            if (count($parts) === 2) {
                [$itemId, $quantityRaw] = $parts;
                $quantity = is_numeric($quantityRaw) ? (int) $quantityRaw : null;
            } elseif (count($parts) >= 3) {
                $itemId = (string) $parts[0];
                $sku = (string) $parts[1];
                $quantityRaw = (string) $parts[2];
                $quantity = is_numeric($quantityRaw) ? (int) $quantityRaw : null;
            }

            if ($itemId === '' || $quantity === null || $quantity < 0) {
                $failures[] = 'line ' . ((int) $lineNumber + 1);
                continue;
            }

            $row = ['ItemID' => $itemId, 'Quantity' => min(999, $quantity)];
            if ($sku !== '') {
                $row['SKU'] = $sku;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('No valid inventory update rows were found. Use eBay item ID | SKU | quantity.');
        }

        foreach (array_chunk($rows, 4) as $chunk) {
            $this->tradingService->reviseInventoryStatus($ebayToken, $chunk);
        }

        $message = 'Manual inventory update complete. ' . count($rows) . ' rows sent to eBay.';
        if ($failures !== []) {
            $message .= ' Skipped: ' . implode(', ', $this->safeArraySlice($failures, 0, 6));
        }

        return $message;
    }

    private function handleUpdateEbayPricesFromProfitRows(): string
    {
        $ebayTokenSource = 'user';
        $ebayToken = $this->resolveToken('user', $ebayTokenSource);
        if ($ebayToken === '') {
            throw new RuntimeException('A user eBay token is required before updating prices.');
        }

        $cjTokenSource = 'none';
        $cjAccessToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjAccessToken === '') {
            throw new RuntimeException('CJ access token is required before updating prices.');
        }

        $countryCode = strtoupper(trim((string) ($_POST['cj_country_code'] ?? 'US')));
        $limit = max(1, min(250, (int) ($_POST['sync_limit'] ?? 250)));
        $maps = $this->cjMappingRepository->listAllListingMaps($limit);
        if ($maps === []) {
            throw new RuntimeException('No CJ-to-eBay listing mappings are available to update prices.');
        }

        $updatedListings = 0;
        $updatedVariations = 0;
        $failures = [];
        foreach ($maps as $map) {
            $itemId = trim((string) ($map['ebay_item_id'] ?? ''));
            $pid = trim((string) ($map['cj_pid'] ?? ''));
            if ($itemId === '' || $pid === '') {
                continue;
            }

            try {
                $product = $this->loadCjProductDetail($cjAccessToken, $pid, $countryCode);
                $variants = $this->normalizeCjVariants($product['_variants'] ?? []);
                
                // Get current listing details to check for variations
                $listing = $this->tradingService->getItem($ebayToken, $itemId);
                $hasVariations = isset($listing['Item']['Variations']['Variation']) && is_array($listing['Item']['Variations']['Variation']);
                
                if ($hasVariations) {
                    // Update variation prices
                    $variationUpdates = [];
                    foreach ($variants as $index => $variant) {
                        $vid = (string) ($variant['vid'] ?? '');
                        $cjCost = $this->extractFullCjCost($variant, $cjAccessToken, 'CN', $countryCode, $vid);
                        if ($cjCost <= 0) {
                            continue;
                        }
                        $newPrice = $this->calculateSellingPrice($cjCost);
                        $sku = (string) ($variant['variantSku'] ?? ('CJ-' . ($variant['vid'] ?? $index)));
                        $variationUpdates[] = [
                            'SKU' => $sku,
                            'StartPrice' => number_format($newPrice, 2, '.', ''),
                        ];
                    }
                    
                    if ($variationUpdates !== []) {
                        $fields = ['Variations' => ['Variation' => $variationUpdates]];
                        $this->tradingService->reviseListing($ebayToken, $itemId, $fields);
                        $updatedListings++;
                        $updatedVariations += count($variationUpdates);
                    }
                } else {
                    // Update single listing price
                    $vid = $variants !== [] ? (string) ($variants[0]['vid'] ?? '') : '';
                    $cjCost = $this->extractFullCjCost($product, $cjAccessToken, 'CN', $countryCode, $vid);
                    if ($cjCost <= 0 && $variants !== []) {
                        $vid = (string) ($variants[0]['vid'] ?? '');
                        $cjCost = $this->extractFullCjCost($variants[0], $cjAccessToken, 'CN', $countryCode, $vid);
                    }
                    if ($cjCost <= 0) {
                        $failures[] = $itemId . ': no valid CJ cost found';
                        continue;
                    }
                    
                    $newPrice = $this->calculateSellingPrice($cjCost);
                    $fields = ['StartPrice' => number_format($newPrice, 2, '.', '')];
                    $this->tradingService->reviseListing($ebayToken, $itemId, $fields);
                    $updatedListings++;
                }
            } catch (Throwable $throwable) {
                $failures[] = $itemId . ': ' . $throwable->getMessage();
            }
        }

        $message = 'Price update complete. ' . $updatedListings . ' listings updated';
        if ($updatedVariations > 0) {
            $message .= ' with ' . $updatedVariations . ' variations updated';
        }
        $message .= '.';
        if ($failures !== []) {
            $message .= ' Skipped/failed: ' . implode(' | ', $this->safeArraySlice($failures, 0, 4));
        }

        return $message;
    }

    private function handleSyncEbayOrdersToCj(): string
    {
        [$ebayToken, $cjToken] = $this->resolveCjOrderSyncTokens(true);
        $result = $this->createCjOrderSyncService()->syncEbayOrdersToCj($ebayToken, $cjToken);
        return 'eBay to CJ order sync complete. Synced ' . count((array) ($result['synced'] ?? [])) . ', skipped ' . count((array) ($result['skipped'] ?? [])) . ', errors ' . count((array) ($result['errors'] ?? [])) . $this->formatSyncErrors($result);
    }

    private function handleSyncCjOrdersFromCj(): string
    {
        [, $cjToken] = $this->resolveCjOrderSyncTokens(false);
        $result = $this->createCjOrderSyncService()->syncCjOrdersFromCj($cjToken);
        return 'CJ order status pull complete. Checked ' . (int) ($result['checked'] ?? 0) . ', updated ' . count((array) ($result['updated'] ?? [])) . ', errors ' . count((array) ($result['errors'] ?? [])) . $this->formatSyncErrors($result);
    }

    private function handleSyncCjTrackingToEbay(): string
    {
        [$ebayToken, $cjToken] = $this->resolveCjOrderSyncTokens(true);
        $result = $this->createCjOrderSyncService()->syncCjTrackingToEbay($ebayToken, $cjToken);
        return 'CJ tracking to eBay sync complete. Pushed ' . count((array) ($result['synced'] ?? [])) . ', errors ' . count((array) ($result['errors'] ?? [])) . $this->formatSyncErrors($result);
    }

    private function handleSyncOrdersTwoWay(): string
    {
        [$ebayToken, $cjToken] = $this->resolveCjOrderSyncTokens(true);
        $result = $this->createCjOrderSyncService()->syncOrdersTwoWay($ebayToken, $cjToken);
        $push = (array) ($result['ebay_to_cj'] ?? []);
        $pull = (array) ($result['cj_status'] ?? []);
        $tracking = (array) ($result['tracking_to_ebay'] ?? []);
        $errors = array_merge((array) ($push['errors'] ?? []), (array) ($pull['errors'] ?? []), (array) ($tracking['errors'] ?? []));

        $message = 'Two-way order sync complete. eBay to CJ: ' . count((array) ($push['synced'] ?? [])) . ', CJ status updated: ' . count((array) ($pull['updated'] ?? [])) . ', tracking pushed: ' . count((array) ($tracking['synced'] ?? [])) . '.';
        if ($errors !== []) {
            $message .= ' Errors: ' . implode(' | ', $this->safeArraySlice(array_map('strval', $errors), 0, 4));
        }

        return $message;
    }

    private function handleConfigureCjWebhooks(): string
    {
        $cjTokenSource = 'none';
        $cjToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjToken === '') {
            throw new RuntimeException('CJ access token is required before configuring CJ webhooks.');
        }

        $url = trim((string) ($_POST['cj_webhook_url'] ?? $this->defaultCjWebhookUrl()));
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('CJ webhooks require a public HTTPS callback URL. Localhost and http URLs are rejected by CJ.');
        }

        $target = ['type' => 'ENABLE', 'callbackUrls' => [$url]];
        $result = $this->cjService->setWebhook($cjToken, [
            'product' => $target,
            'stock' => $target,
            'order' => $target,
            'logistics' => $target,
        ]);

        return 'CJ webhooks enabled for product, stock, order, and logistics messages at ' . $url . '. Request: ' . (string) ($result['requestId'] ?? 'ok');
    }

    /** @return array{0:string,1:string} */
    private function resolveCjOrderSyncTokens(bool $requireEbayToken): array
    {
        $cjTokenSource = 'none';
        $cjToken = $this->resolveCjAccessToken($cjTokenSource);
        if ($cjToken === '') {
            throw new RuntimeException('CJ access token is required for order sync.');
        }

        $ebayToken = '';
        if ($requireEbayToken) {
            $ebayTokenSource = 'user';
            $ebayToken = $this->resolveToken('user', $ebayTokenSource);
            if ($ebayToken === '') {
                throw new RuntimeException('A user eBay token is required for order sync.');
            }
        }

        return [$ebayToken, $cjToken];
    }

    private function createCjOrderSyncService(): CjOrderFulfillmentSync
    {
        return new CjOrderFulfillmentSync($this->cjService, $this->apiClient, $this->cjMappingRepository);
    }

    /** @param array<string, mixed> $result */
    private function formatSyncErrors(array $result): string
    {
        $errors = array_values(array_filter(array_map('strval', (array) ($result['errors'] ?? []))));
        return $errors !== [] ? '. Errors: ' . implode(' | ', $this->safeArraySlice($errors, 0, 4)) : '.';
    }

    private function handleCreateShippingFulfillment(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to create shipping fulfillment.');
        }

        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $lineItemId = trim((string) ($_POST['line_item_id'] ?? ''));
        if ($orderId === '' || $lineItemId === '') {
            throw new RuntimeException('Order ID and line item ID are required.');
        }

        $payload = [
            'lineItems' => [[
                'lineItemId' => $lineItemId,
                'quantity' => max(1, (int) ($_POST['quantity'] ?? '1')),
            ]],
        ];
        $carrier = trim((string) ($_POST['shipping_carrier_code'] ?? ''));
        $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
        $shippedDate = trim((string) ($_POST['shipped_date'] ?? ''));
        if ($carrier !== '') {
            $payload['shippingCarrierCode'] = $carrier;
        }
        if ($tracking !== '') {
            $payload['trackingNumber'] = $tracking;
        }
        if ($shippedDate !== '') {
            $payload['shippedDate'] = $shippedDate;
        }

        $result = $this->apiClient->request(
            'POST',
            '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment',
            $token,
            [],
            ['Content-Type' => 'application/json'],
            $payload
        );

        return 'Shipping fulfillment created. HTTP ' . (string) ($result['status'] ?? 200);
    }

    private function handleIssueRefund(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to issue a refund.');
        }

        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $reason = trim((string) ($_POST['reason_for_refund'] ?? ''));
        $amount = trim((string) ($_POST['refund_amount'] ?? ''));
        $currency = trim((string) ($_POST['refund_currency'] ?? 'USD'));
        if ($orderId === '' || $reason === '' || $amount === '') {
            throw new RuntimeException('Order ID, refund reason, and refund amount are required.');
        }

        $payload = [
            'reasonForRefund' => $reason,
            'comment' => trim((string) ($_POST['refund_comment'] ?? '')),
        ];

        $lineItemId = trim((string) ($_POST['line_item_id'] ?? ''));
        if ($lineItemId !== '') {
            $payload['refundItems'] = [[
                'lineItemId' => $lineItemId,
                'refundAmount' => [
                    'currency' => $currency,
                    'value' => $amount,
                ],
            ]];
        } else {
            $payload['orderLevelRefundAmount'] = [
                'currency' => $currency,
                'value' => $amount,
            ];
        }

        $result = $this->apiClient->request(
            'POST',
            '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/issue_refund',
            $token,
            [],
            ['Content-Type' => 'application/json'],
            $payload
        );

        return 'Refund request sent. HTTP ' . (string) ($result['status'] ?? 200);
    }

    private function handleCampaignControl(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to manage campaigns.');
        }

        $campaignId = trim((string) ($_POST['campaign_id'] ?? ''));
        $operation = trim((string) ($_POST['campaign_operation'] ?? ''));
        if ($campaignId === '' || !in_array($operation, ['pause', 'resume', 'end'], true)) {
            throw new RuntimeException('Campaign ID and a valid campaign operation are required.');
        }

        $result = $this->apiClient->request(
            'POST',
            '/sell/marketing/v1/ad_campaign/' . rawurlencode($campaignId) . '/' . $operation,
            $token,
            [],
            ['Content-Type' => 'application/json'],
            (object) []
        );

        return 'Campaign ' . $operation . ' request sent. HTTP ' . (string) ($result['status'] ?? 200);
    }

    private function handleOfferControl(): string
    {
        $tokenSource = 'user';
        $token = $this->resolveToken('user', $tokenSource);
        if ($token === '') {
            throw new RuntimeException('A user token is required to manage offers.');
        }

        $offerId = trim((string) ($_POST['offer_id'] ?? ''));
        $operation = trim((string) ($_POST['offer_operation'] ?? ''));
        if ($offerId === '' || !in_array($operation, ['publish', 'withdraw'], true)) {
            throw new RuntimeException('Offer ID and a valid offer operation are required.');
        }

        $path = '/sell/inventory/v1/offer/' . rawurlencode($offerId) . '/' . $operation;
        $body = $operation === 'withdraw' ? (object) [] : null;
        $result = $this->apiClient->request(
            'POST',
            $path,
            $token,
            [],
            ['Content-Type' => 'application/json'],
            $body
        );

        return 'Offer ' . $operation . ' request sent. HTTP ' . (string) ($result['status'] ?? 200);
    }

    private function tryCreateVolumePricingPromotion(string $token, string $itemId): string
    {
        if ($token === '' || $itemId === '') {
            return '';
        }

        try {
            $start = gmdate('c');
            $end = gmdate('c', strtotime('+180 days'));
            $payload = [
                'name' => 'Auto volume pricing ' . $itemId,
                'description' => 'Automatic volume pricing for Queen Diana Store listings.',
                'marketplaceId' => 'EBAY_US',
                'promotionStatus' => 'ACTIVE',
                'promotionType' => 'VOLUME_DISCOUNT',
                'applyDiscountToSingleItemOnly' => true,
                'startDate' => $start,
                'endDate' => $end,
                'inventoryCriterion' => [
                    'inventoryCriterionType' => 'INVENTORY_BY_VALUE',
                    'listingIds' => [$itemId],
                ],
                'discountRules' => [
                    [
                        'discountSpecification' => ['minQuantity' => 1],
                        'discountBenefit' => ['percentageOffOrder' => '0'],
                        'ruleOrder' => 1,
                    ],
                    [
                        'discountSpecification' => ['minQuantity' => 2],
                        'discountBenefit' => ['percentageOffOrder' => '10'],
                        'ruleOrder' => 2,
                    ],
                    [
                        'discountSpecification' => ['minQuantity' => 3],
                        'discountBenefit' => ['percentageOffOrder' => '15'],
                        'ruleOrder' => 3,
                    ],
                    [
                        'discountSpecification' => ['minQuantity' => 4],
                        'discountBenefit' => ['percentageOffOrder' => '20'],
                        'ruleOrder' => 4,
                    ],
                ],
            ];

            $result = $this->apiClient->request(
                'POST',
                '/sell/marketing/v1/item_promotion',
                $token,
                [],
                ['Content-Type' => 'application/json'],
                $payload
            );

            $status = (int) ($result['status'] ?? 0);
            return ($status >= 200 && $status < 300)
                ? 'Auto volume pricing scheduled: Buy 2 save 10%, Buy 3 save 15%, Buy 4 save 20%.'
                : '';
        } catch (Throwable $throwable) {
            return 'Volume pricing not attached: ' . $throwable->getMessage();
        }
    }

    private function resolveCjPriceOverrideFromPost(array $product): string
    {
        $pid = trim((string) ($product['pid'] ?? $product['id'] ?? $_POST['cj_pid'] ?? ''));
        $raw = '';
        $overrides = $_POST['cj_price_overrides'] ?? [];
        if ($pid !== '' && is_array($overrides) && isset($overrides[$pid])) {
            $raw = trim((string) $overrides[$pid]);
        }
        if ($raw === '') {
            $raw = trim((string) ($_POST['cj_price_override'] ?? ''));
        }
        if ($raw === '') {
            return '';
        }

        $price = $this->extractNumericPrice($raw);
        if ($price <= 0) {
            throw new RuntimeException('The custom eBay price must be greater than zero.');
        }

        return number_format($price, 2, '.', '');
    }

    /** @param array<string, mixed> $product @param list<array<string, mixed>> $variants @return array<string, mixed> */
    private function buildEbayListingPayloadFromCj(array $product, array $variants, ?string $accessToken = null, string $countryCode = 'US'): array
    {
        $title = trim((string) ($_POST['ebay_title'] ?? $this->extractCjProductTitle($product)));
        $title = $this->truncateText($title, 80, '...');
        $categoryId = trim((string) ($_POST['ebay_category_id'] ?? ''));
        $conditionId = trim((string) ($_POST['ebay_condition_id'] ?? '1000'));
        $postalCode = trim((string) ($_POST['postal_code'] ?? '10001'));
        $paypalEmail = trim((string) ($_POST['paypal_email'] ?? ''));
        $description = trim((string) ($_POST['ebay_description'] ?? $this->buildCjDescriptionHtml($product)));
        $markupPercent = (float) ($_POST['markup_default_percent'] ?? $_POST['markup_percent'] ?? 55);
        $markupRules = $this->parseMarkupRulesFromPost();
        $fallbackQuantity = max(0, (int) ($_POST['fallback_quantity'] ?? 0));
        $quantityCap = $this->resolveEbayQuantityCap();
        $priceOverride = $this->resolveCjPriceOverrideFromPost($product);

        if ($title === '' || $categoryId === '' || $conditionId === '' || $postalCode === '') {
            throw new RuntimeException('Title, category ID, condition ID, and postal code are required for CJ import.');
        }
        $this->assertEbayLeafCategoryId($categoryId);

        $aspectRequirements = $this->loadEbayAspectRequirements($categoryId);
        $variants = $this->enrichCjVariantsWithInventory($variants, $product);
        $inventorySignals = count(array_filter($variants, fn (array $variant): bool => $this->hasCjInventorySignal($variant)));
        if ($inventorySignals > 0) {
            $inStockVariants = array_values(array_filter($variants, fn (array $variant): bool => $this->extractCjVariantQuantity($variant, 0) > 0));
            if ($inStockVariants === []) {
                throw new RuntimeException('CJ reports zero inventory for every variant, so the eBay listing was stopped instead of creating an out-of-stock item.');
            }
            $variants = $inStockVariants;
        } elseif ($fallbackQuantity < 1) {
            throw new RuntimeException('CJ did not return inventory for this product or its variants. The listing was stopped instead of using a fake fallback quantity.');
        }
        $images = $this->collectCjListingImages($product, $variants);
        if ($images === []) {
            throw new RuntimeException('CJ did not return a usable product or variant photo, so the eBay listing was stopped before eBay rejected it.');
        }

        $variationPairMaps = count($variants) > 1
            ? $this->buildCanonicalVariationPairMaps($product, $variants, $categoryId, $aspectRequirements)
            : [];
        $singleVariantPairs = count($variants) === 1
            ? $this->extractCjVariationPairs($product, $variants[0], $categoryId)
            : [];
        $variationAspectNames = $variationPairMaps !== []
            ? $this->collectVariationAspectNamesFromMaps($variationPairMaps)
            : [];
        $itemSpecifics = $this->buildCategoryAwareItemSpecifics($product, $aspectRequirements, $variationAspectNames, $categoryId);
        if ($singleVariantPairs !== []) {
            $itemSpecifics = $this->mergeSingleVariantItemSpecifics($itemSpecifics, $singleVariantPairs, $aspectRequirements, $categoryId);
        }
        $this->persistAspectTemplateFromPost($categoryId, $itemSpecifics);
        $payload = [
            'Title' => $title,
            'PrimaryCategory' => ['CategoryID' => $categoryId],
            'CategoryMappingAllowed' => 'true',
            'ConditionID' => $conditionId,
            'Country' => 'US',
            'Currency' => 'USD',
            'DispatchTimeMax' => '3',
            'ListingDuration' => 'GTC',
            'ListingType' => 'FixedPriceItem',
            'PostalCode' => $postalCode,
            'Description' => $description !== '' ? $description : $this->buildFallbackDescriptionHtml($product),
            'Site' => 'US',
            'ReturnPolicy' => [
                'ReturnsAcceptedOption' => 'ReturnsAccepted',
                'RefundOption' => 'MoneyBack',
                'ReturnsWithinOption' => 'Days_30',
                'ShippingCostPaidByOption' => 'Buyer',
            ],
            'ShippingDetails' => [
                'ShippingType' => 'Flat',
                'ShippingServiceOptions' => [
                    'ShippingServicePriority' => '1',
                    'ShippingService' => 'UPSGround',
                    'ShippingServiceCost' => '0.0',
                    'ShippingServiceAdditionalCost' => '0.0',
                ],
            ],
            'ItemSpecifics' => [
                'NameValueList' => $itemSpecifics,
            ],
        ];

        if ($paypalEmail !== '') {
            $payload['PaymentMethods'] = 'PayPal';
            $payload['PayPalEmailAddress'] = $paypalEmail;
        }

        $prices = [];
        foreach ($variants as $variant) {
            if ($priceOverride !== '') {
                $prices[] = $priceOverride;
                continue;
            }
            $vid = (string) ($variant['vid'] ?? '');
            $base = $this->extractFullCjCost($variant, $accessToken, 'CN', $countryCode, $vid);
            $prices[] = $this->formatMarkedUpPrice($base, $markupPercent, $markupRules);
        }
        $usablePrices = array_values(array_filter($prices, static fn (string $price): bool => $price !== ''));
        $baseStartPrice = $usablePrices !== [] ? number_format(min(array_map('floatval', $usablePrices)), 2, '.', '') : '19.99';
        $payload['StartPrice'] = $baseStartPrice;

        if (count($variants) === 1) {
            $variant = $variants[0];
            $payload['SKU'] = (string) ($variant['variantSku'] ?? ('CJ-' . ($variant['vid'] ?? '')));
            $payload['Quantity'] = $this->capEbayListingQuantity($this->extractCjVariantQuantity($variant, $fallbackQuantity), $quantityCap);
            $payload['StartPrice'] = ($prices[0] ?? '') !== '' ? $prices[0] : $payload['StartPrice'];
            $payload['PictureDetails'] = ['PictureURL' => $this->safeArraySlice($images, 0, 12)];
            return $payload;
        }

        unset($payload['StartPrice']);

        $variationList = [];
        $specificSet = [];
        foreach ($variants as $index => $variant) {
            $pairs = $variationPairMaps[$index] ?? $this->extractCjVariationPairs($product, $variant, $categoryId);
            foreach ($pairs as $name => $value) {
                $specificSet[$name][] = $value;
            }

            $variationList[] = [
                'SKU' => (string) ($variant['variantSku'] ?? ('CJ-' . ($variant['vid'] ?? $index))),
                'Quantity' => $this->capEbayListingQuantity($this->extractCjVariantQuantity($variant, $fallbackQuantity), $quantityCap),
                'StartPrice' => ($prices[$index] ?? '') !== '' ? $prices[$index] : $baseStartPrice,
                'VariationSpecifics' => [
                    'NameValueList' => $this->nameValueListFromMap($pairs),
                ],
            ];
        }

        $payload['Variations'] = [
            'Variation' => $variationList,
            'VariationSpecificsSet' => [
                'NameValueList' => $this->nameValueListFromMultiMap($specificSet),
            ],
        ];

        $variationPictures = $this->buildVariationPictures($variants, $variationList);
        if ($variationPictures !== null) {
            $payload['Variations']['Pictures'] = $variationPictures;
        }

        $payloadImages = $this->limitEbayPictureUrls($images, $variationPictures);
        if ($payloadImages === []) {
            $payloadImages = $this->safeArraySlice($this->extractVariationPictureUrls($variationPictures), 0, 12);
        }
        if ($payloadImages !== []) {
            $payload['PictureDetails'] = ['PictureURL' => $payloadImages];
        }

        return $payload;
    }

    /** @param list<array<string, mixed>> $variants @return array<string, string> */
    private function buildCjSkuVidMap(array $variants): array
    {
        $map = [];
        foreach ($variants as $variant) {
            $sku = trim((string) ($variant['variantSku'] ?? ''));
            $vid = trim((string) ($variant['vid'] ?? ''));
            if ($sku !== '' && $vid !== '') {
                $map[$sku] = $vid;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $variationList
     * @return array<string, mixed>|null
     */
    private function buildVariationPictures(array $variants, array $variationList): ?array
    {
        $groups = [];
        $attributeOrder = ['Color', 'Style', 'Size', 'Option 1', 'Option 2', 'Option 3'];

        foreach ($variants as $index => $variant) {
            $image = trim((string) ($variant['variantImage'] ?? $variant['variantImageUrl'] ?? ''));
            if ($image === '' || !isset($variationList[$index]['VariationSpecifics']['NameValueList'])) {
                continue;
            }

            $specifics = $variationList[$index]['VariationSpecifics']['NameValueList'];
            if (!is_array($specifics)) {
                continue;
            }

            $specificMap = [];
            foreach ($specifics as $specific) {
                if (!is_array($specific)) {
                    continue;
                }
                $name = trim((string) ($specific['Name'] ?? ''));
                $value = trim((string) ($specific['Value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $specificMap[$name] = $value;
                }
            }

            foreach ($attributeOrder as $attribute) {
                if (!isset($specificMap[$attribute])) {
                    continue;
                }

                $value = $specificMap[$attribute];
                $groups[$attribute][$value][] = $image;
            }
        }

        foreach ($attributeOrder as $attribute) {
            $pictureSets = $groups[$attribute] ?? null;
            if (!is_array($pictureSets) || count($pictureSets) < 2) {
                continue;
            }

            $rows = [];
            foreach ($pictureSets as $value => $images) {
                $uniqueImages = array_values(array_unique(array_filter(array_map('strval', $images))));
                if ($uniqueImages === []) {
                    continue;
                }

                $rows[] = [
                    'VariationSpecificValue' => (string) $value,
                    'PictureURL' => $uniqueImages,
                ];
            }

            if (count($rows) >= 2) {
                return [
                    'VariationSpecificName' => $attribute,
                    'VariationSpecificPictureSet' => $rows,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $variationPictures @return list<string> */
    private function limitEbayPictureUrls(array $productImages, ?array $variationPictures = null): array
    {
        $maxPictures = 12;
        $reservedVariationUrls = $this->extractVariationPictureUrls($variationPictures);
        $remainingSlots = max(0, $maxPictures - count($reservedVariationUrls));
        if ($remainingSlots === 0) {
            return [];
        }

        $filteredProductImages = [];
        foreach ($productImages as $image) {
            $url = trim((string) $image);
            if ($url === '' || in_array($url, $reservedVariationUrls, true)) {
                continue;
            }
            $filteredProductImages[] = $url;
        }

        return $this->safeArraySlice(array_values(array_unique($filteredProductImages)), 0, $remainingSlots);
    }

    /** @param array<string, mixed>|null $variationPictures @return list<string> */
    private function extractVariationPictureUrls(?array $variationPictures): array
    {
        if (!is_array($variationPictures)) {
            return [];
        }

        $sets = $variationPictures['VariationSpecificPictureSet'] ?? [];
        if (!is_array($sets)) {
            return [];
        }

        $urls = [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }

            $pictures = $set['PictureURL'] ?? [];
            if (is_string($pictures) && trim($pictures) !== '') {
                $urls[] = trim($pictures);
                continue;
            }

            if (!is_array($pictures)) {
                continue;
            }

            foreach ($pictures as $picture) {
                $url = trim((string) $picture);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @param array<string, mixed> $product */
    private function inferCjBrand(array $product): string
    {
        foreach (['brandNameEn', 'brandName', 'supplierName'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '' && !preg_match('/^(?:none|null|n\\/a)$/i', $value)) {
                return $value;
            }
        }

        return 'Unbranded';
    }

    /** @param array<string, mixed> $product */
    private function inferCjProductType(array $product): string
    {
        $candidates = [
            trim((string) ($product['entryNameEn'] ?? '')),
            trim((string) ($product['entryName'] ?? '')),
            trim((string) ($product['productType'] ?? '')),
        ];

        $categoryName = trim((string) ($product['categoryName'] ?? ''));
        if ($categoryName !== '') {
            $parts = preg_split('/\s*>\s*/', $categoryName) ?: [];
            $tail = trim((string) end($parts));
            if ($tail !== '') {
                $candidates[] = $tail;
            }
        }

        $title = trim((string) ($product['productNameEn'] ?? $this->extractCjProductTitle($product)));
        if ($title !== '') {
            $title = preg_replace('/\s+/', ' ', strip_tags($title)) ?? $title;
            $words = preg_split('/\s+/', $title) ?: [];
            $slice = $this->safeArraySlice(array_values(array_filter($words, static fn (string $word): bool => $word !== '')), 0, 4);
            if ($slice !== []) {
                $candidates[] = implode(' ', $slice);
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && !preg_match('/^(?:ordinary_product|null|n\\/a)$/i', $candidate)) {
                return substr($candidate, 0, 65);
            }
        }

        return 'General';
    }

    /** @param array<string, mixed> $product */
    private function inferCjDepartment(array $product): string
    {
        $signals = [
            strtolower(trim((string) ($product['categoryName'] ?? ''))),
            strtolower(trim((string) ($product['productNameEn'] ?? ''))),
            strtolower(trim((string) ($product['productName'] ?? ''))),
            strtolower(trim((string) ($product['entryNameEn'] ?? ''))),
        ];

        $joined = implode(' | ', array_filter($signals, static fn (string $value): bool => $value !== ''));
        if ($joined === '') {
            return '';
        }

        if (preg_match('/women|woman|lady|ladies|female|girl/', $joined) === 1) {
            return 'Women';
        }
        if (preg_match('/men|man|male|boy/', $joined) === 1) {
            return 'Men';
        }
        if (preg_match('/unisex/', $joined) === 1) {
            return 'Unisex Adults';
        }
        if (preg_match('/kid|child|children|toddler|infant|baby/', $joined) === 1) {
            return 'Kids';
        }

        return '';
    }

    /**
     * @param list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> $aspects
     * @param list<string> $variationAspectNames
     * @return list<array<string, string>>
     */
    private function buildCategoryAwareItemSpecifics(array $product, array $aspects, array $variationAspectNames, string $categoryId = ''): array
    {
        $templateValues = $categoryId !== '' ? $this->loadSavedAspectTemplateValues($categoryId) : [];
        $specifics = [
            'Brand' => $this->inferCjBrand($product),
            'Type' => $this->inferCjProductType($product),
            'Source' => 'Queen Diana Store',
            'CJ PID' => (string) ($product['pid'] ?? ''),
        ];

        $department = $this->inferCjDepartment($product);
        if ($department !== '') {
            $specifics['Department'] = $department;
        }

        foreach ($aspects as $aspect) {
            $name = trim((string) ($aspect['name'] ?? ''));
            if ($name === '' || in_array($name, $variationAspectNames, true)) {
                continue;
            }

            $posted = trim((string) ($_POST['ebay_aspects'][$name] ?? ''));
            if ($posted !== '') {
                $specifics[$name] = $this->normalizeEbayAspectValue($name, $posted, (array) ($aspect['values'] ?? []), (bool) ($aspect['required'] ?? false));
                continue;
            }

            $templateValue = trim((string) ($templateValues[$name] ?? ''));
            if ($templateValue !== '') {
                $specifics[$name] = $this->normalizeEbayAspectValue($name, $templateValue, (array) ($aspect['values'] ?? []), (bool) ($aspect['required'] ?? false));
                continue;
            }

            $inferred = $this->inferCjAspectValue($product, $name);
            $inferred = $this->normalizeEbayAspectValue($name, $inferred, (array) ($aspect['values'] ?? []), (bool) ($aspect['required'] ?? false));
            if ($inferred !== '') {
                $specifics[$name] = $inferred;
            }
        }

        $rows = [];
        foreach ($specifics as $name => $value) {
            $value = trim((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $rows[] = ['Name' => $name, 'Value' => $value];
        }

        return $rows;
    }

    /**
     * Single-SKU eBay listings do not have a Variations block, so eBay expects
     * variant-like values such as Color and US Shoe Size in ItemSpecifics.
     *
     * @param list<array<string, string>> $itemSpecifics
     * @param array<string, string> $variantPairs
     * @param list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> $aspects
     * @return list<array<string, string>>
     */
    private function mergeSingleVariantItemSpecifics(array $itemSpecifics, array $variantPairs, array $aspects, string $categoryId): array
    {
        $specifics = [];
        foreach ($itemSpecifics as $row) {
            $name = trim((string) ($row['Name'] ?? ''));
            $value = trim((string) ($row['Value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $specifics[$name] = $value;
            }
        }

        $aspectLookup = [];
        foreach ($aspects as $aspect) {
            $name = trim((string) ($aspect['name'] ?? ''));
            if ($name !== '') {
                $aspectLookup[strtolower($name)] = $aspect;
            }
        }

        foreach ($variantPairs as $rawName => $rawValue) {
            $name = $this->normalizeEbayVariationName((string) $rawName, $categoryId);
            $value = trim((string) $rawValue);
            if ($name === '' || $value === '') {
                continue;
            }

            if ($name === 'Size' && isset($aspectLookup['us shoe size'])) {
                $name = 'US Shoe Size';
            }
            if ($name === 'Width' && isset($aspectLookup['shoe width'])) {
                $name = 'Shoe Width';
            }

            $aspect = $aspectLookup[strtolower($name)] ?? null;
            $safeSingleSpecifics = ['Color', 'Size', 'US Shoe Size', 'EU Shoe Size', 'UK Shoe Size', 'Width', 'Shoe Width', 'Material'];
            if ($aspect === null && !in_array($name, $safeSingleSpecifics, true)) {
                continue;
            }

            $specifics[$name] = $this->normalizeEbayAspectValue(
                $name,
                $value,
                is_array($aspect) ? (array) ($aspect['values'] ?? []) : [],
                is_array($aspect) ? (bool) ($aspect['required'] ?? false) : false
            );
        }

        $rows = [];
        foreach ($specifics as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $rows[] = ['Name' => $name, 'Value' => $value];
            }
        }

        return $rows;
    }

    /** @param list<array<string, string>> $itemSpecifics */
    private function persistAspectTemplateFromPost(string $categoryId, array $itemSpecifics): void
    {
        if ($categoryId === '') {
            return;
        }

        $aspectValues = [];
        foreach ($itemSpecifics as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            $value = trim((string) ($row['Value'] ?? ''));
            if ($name !== '' && $value !== '' && !in_array($name, ['Source', 'CJ PID'], true)) {
                $aspectValues[$name] = $value;
            }
        }

        if ($aspectValues === []) {
            return;
        }

        $categoryName = $this->resolveCategorySuggestionName($categoryId);
        $this->cjMappingRepository->saveAspectTemplate($categoryId, $categoryName, $aspectValues);
    }

    /** @param list<array<string, mixed>> $variants @return list<string> */
    private function collectVariationAspectNames(array $variants, array $product): array
    {
        $names = [];
        foreach ($variants as $variant) {
            foreach ($this->extractCjVariationPairs($product, $variant) as $name => $_value) {
                $names[] = (string) $name;
                $normalized = strtolower((string) $name);
                if ($normalized === 'size') {
                    $names[] = 'US Shoe Size';
                    $names[] = 'EU Shoe Size';
                    $names[] = 'UK Shoe Size';
                }
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /** @param array<string, mixed> $product */
    private function inferCjAspectValue(array $product, string $aspectName): string
    {
        $aspect = strtolower(trim($aspectName));
        return match ($aspect) {
            'brand' => $this->inferCjBrand($product),
            'type' => $this->inferCjProductType($product),
            'department' => $this->inferCjDepartment($product),
            'color', 'main color' => $this->inferCjColor($product),
            'size', 'shoe size', 'us shoe size', 'eu shoe size', 'uk shoe size' => $this->inferCjSize($product, $aspectName),
            'style' => $this->inferCjStyle($product),
            'upper material', 'material' => $this->inferCjMaterial($product),
            'closure' => $this->inferCjClosure($product),
            'heel height' => $this->inferDescriptionLabel($product, 'heel height'),
            'toe shape' => $this->inferDescriptionLabel($product, 'toe shape'),
            'country of origin' => $this->inferCountryOfOrigin($product),
            'features' => $this->inferCjFeatures($product),
            default => '',
        };
    }

    /** @param list<string> $allowedValues */
    private function normalizeEbayAspectValue(string $aspectName, string $value, array $allowedValues, bool $required): string
    {
        $value = trim($value);
        $allowedValues = array_values(array_filter(array_map('strval', $allowedValues), static fn (string $entry): bool => trim($entry) !== ''));
        if ($allowedValues === []) {
            return $value;
        }

        foreach ($allowedValues as $allowed) {
            if (strcasecmp($allowed, $value) === 0) {
                return $allowed;
            }
        }

        $aspect = strtolower(trim($aspectName));
        $lowerValue = strtolower($value);
        foreach ($allowedValues as $allowed) {
            $lowerAllowed = strtolower($allowed);
            if ($value !== '' && ($lowerAllowed === $lowerValue || str_contains($lowerValue, $lowerAllowed) || str_contains($lowerAllowed, $lowerValue))) {
                return $allowed;
            }
        }

        if (!$required && $value !== '') {
            return $value;
        }

        $fallbacks = match ($aspect) {
            'brand' => ['Unbranded'],
            'color', 'main color' => ['Multicolor', 'Multi-Color', 'Black', 'White'],
            'department' => ['Unisex Adults', 'Women', 'Men'],
            'size', 'shoe size', 'us shoe size' => ['One Size', 'One Size Fits All'],
            'condition' => ['New'],
            default => [],
        };

        foreach ($fallbacks as $fallback) {
            foreach ($allowedValues as $allowed) {
                if (strcasecmp($allowed, $fallback) === 0) {
                    return $allowed;
                }
            }
        }

        return $required ? (string) ($allowedValues[0] ?? $value) : $value;
    }

    /** @param array<string, mixed> $product */
    private function inferCjColor(array $product): string
    {
        foreach (['color', 'colorName', 'colour', 'mainColor'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $colors = [];
        foreach ($this->normalizeCjVariants($product['_variants'] ?? []) as $variant) {
            $pairs = \App\Services\CjVariantParser::parseVariantKey((string) ($variant['variantKey'] ?? $variant['variantNameEn'] ?? $variant['variantName'] ?? ''));
            $color = trim((string) ($pairs['Color'] ?? ''));
            if ($color !== '') {
                $colors[] = $color;
            }
        }
        $colors = array_values(array_unique($colors));
        if (count($colors) === 1) {
            return $colors[0];
        }
        if (count($colors) > 1) {
            return 'Multicolor';
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function inferCjSize(array $product, string $aspectName): string
    {
        foreach ($this->normalizeCjVariants($product['_variants'] ?? []) as $variant) {
            $pairs = \App\Services\CjVariantParser::parseVariantKey((string) ($variant['variantKey'] ?? $variant['variantNameEn'] ?? $variant['variantName'] ?? ''));
            $size = trim((string) ($pairs['Size'] ?? $pairs['US Shoe Size'] ?? ''));
            if ($size !== '') {
                return $size;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function inferCjStyle(array $product): string
    {
        foreach (['entryNameEn', 'entryName'] as $key) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $title = trim((string) ($product['productNameEn'] ?? ''));
        if ($title !== '') {
            return substr($title, 0, 65);
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function inferCjMaterial(array $product): string
    {
        $value = $this->firstDecodedString($product['materialNameEn'] ?? null)
            ?: $this->firstDecodedString($product['materialName'] ?? null)
            ?: trim((string) ($product['materialNameEn'] ?? $product['materialName'] ?? ''));

        return preg_match('/^(?:others?|other)$/i', $value) === 1 ? '' : $value;
    }

    /** @param array<string, mixed> $product */
    private function inferCjClosure(array $product): string
    {
        $description = $this->extractCjDescriptionText($product);
        foreach (['lace up' => 'Lace Up', 'slip on' => 'Slip On', 'zip' => 'Zip', 'pull on' => 'Pull On', 'buckle' => 'Buckle'] as $needle => $label) {
            if (str_contains(strtolower($description), $needle)) {
                return $label;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function inferCountryOfOrigin(array $product): string
    {
        $description = $this->extractCjDescriptionText($product);
        if (preg_match('/made in\s+([a-z ]+)/i', $description, $matches) === 1) {
            return ucwords(trim($matches[1]));
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function inferCjFeatures(array $product): string
    {
        $description = strtolower($this->extractCjDescriptionText($product));
        $features = [];
        foreach (['breathable' => 'Breathable', 'lightweight' => 'Lightweight', 'non-slip' => 'Non-Slip', 'slip-resistant' => 'Slip Resistant', 'mesh' => 'Mesh'] as $needle => $label) {
            if (str_contains($description, $needle)) {
                $features[] = $label;
            }
        }

        return implode(', ', array_values(array_unique($features)));
    }

    /** @param array<string, mixed> $product */
    private function inferDescriptionLabel(array $product, string $label): string
    {
        $description = $this->extractCjDescriptionText($product);
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([^\r\n]+)/i';
        if (preg_match($pattern, $description, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /** @param array<string, mixed> $product */
    private function extractCjDescriptionText(array $product): string
    {
        $raw = (string) ($product['productDescription'] ?? $product['description'] ?? '');
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text);
    }

    /** @param array<string, mixed> $product */
    private function buildCjDescriptionHtml(array $product): string
    {
        $raw = trim((string) ($product['productDescription'] ?? $product['description'] ?? ''));
        if ($raw === '') {
            return $this->buildFallbackDescriptionHtml($product);
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', '', $decoded) ?? $decoded;
        $decoded = str_replace(['<br>', '<br/>', '<br />'], '<br>', $decoded);
        $allowed = '<p><br><div><span><strong><b><em><i><ul><ol><li><table><thead><tbody><tr><td><th><img><h1><h2><h3><h4><h5><h6>';
        $sanitized = trim(strip_tags($decoded, $allowed));
        if ($sanitized !== '') {
            return $sanitized;
        }

        return $this->buildFallbackDescriptionHtml($product);
    }

    /** @param array<string, mixed> $product */
    private function buildFallbackDescriptionHtml(array $product): string
    {
        $title = htmlspecialchars($this->extractCjProductTitle($product), ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($this->extractCjDescriptionText($product), ENT_QUOTES, 'UTF-8');
        $images = $this->extractCjImages($product);
        $html = '<div><h2>' . $title . '</h2>';
        if ($text !== '') {
            $html .= '<p>' . $text . '</p>';
        }
        foreach ($this->safeArraySlice($images, 0, 8) as $image) {
            $html .= '<p><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '"></p>';
        }
        $html .= '</div>';

        return $html;
    }

    private function firstDecodedString(mixed $value): string
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $candidate = trim((string) $entry);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return '';
    }

    private function resolveCategorySuggestionName(string $categoryId): string
    {
        $seed = trim((string) ($_POST['ebay_title'] ?? $_GET['cj_query'] ?? ''));
        if ($seed === '') {
            return $categoryId;
        }

        foreach ($this->loadEbayCategorySuggestions($seed) as $suggestion) {
            if ((string) ($suggestion['id'] ?? '') === $categoryId) {
                return (string) ($suggestion['path'] ?? $suggestion['name'] ?? $categoryId);
            }
        }

        return $categoryId;
    }

    /** @param array<string, mixed> $result */
    private function formatTradingWarnings(array $result): string
    {
        $warnings = $result['_warnings'] ?? [];
        if (!is_array($warnings) || $warnings === []) {
            return '';
        }

        $warnings = array_values(array_filter(array_map('strval', $warnings), static fn (string $warning): bool => $warning !== ''));
        return implode(' | ', $warnings);
    }

    /** @param array<string, mixed> $variant */
    private function extractCjVariantQuantity(array $variant, int $fallbackQuantity): int
    {
        $quantity = $this->extractCjInventoryQuantity($variant);
        return $quantity !== null ? max(0, $quantity) : max(0, $fallbackQuantity);
    }

    private function resolveEbayQuantityCap(): int
    {
        $raw = (int) ($_POST['max_listing_quantity'] ?? $_POST['ebay_quantity_cap'] ?? 5);
        return max(1, min(50, $raw));
    }

    private function capEbayListingQuantity(int $quantity, int $cap): int
    {
        if ($quantity <= 0) {
            return 0;
        }

        return min($quantity, max(1, $cap));
    }

    /** @param array<string, mixed> $product @param array<string, mixed> $variant @return array<string, string> */
    private function extractCjVariationPairs(array $product, array $variant, string $categoryId = ''): array
    {
        $rawKey = trim((string) ($variant['variantKey'] ?? $variant['variantNameEn'] ?? $variant['variantName'] ?? $variant['variantSku'] ?? ''));
        if ($rawKey === '') {
            $rawKey = 'Style';
        }

        $pairs = \App\Services\CjVariantParser::parseVariantKey($rawKey);
        foreach ($this->mapDeclaredCjVariationNames($product, $variant, $categoryId) as $name => $value) {
            if (!isset($pairs[$name]) && $value !== '') {
                $pairs[$name] = $value;
            }
        }

        $pairs = $this->normalizeEbayVariationPairNames($pairs, $categoryId);
        if ($categoryId !== '' && isset($pairs['Size']) && $this->categoryPrefersUsShoeSize($categoryId)) {
            $pairs['US Shoe Size'] = $pairs['Size'];
            unset($pairs['Size']);
        }

        return $this->cleanVariationPairMap($pairs);
    }

    /** @param list<array<string, string>> $variationMaps @return list<string> */
    private function collectVariationAspectNamesFromMaps(array $variationMaps): array
    {
        $names = [];
        foreach ($variationMaps as $map) {
            foreach ($map as $name => $_value) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => trim($name) !== '')));
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> $aspects
     * @return list<array<string, string>>
     */
    private function buildCanonicalVariationPairMaps(array $product, array $variants, string $categoryId, array $aspects): array
    {
        $rawMaps = [];
        $counts = [];
        $valueSets = [];

        foreach ($variants as $variant) {
            $map = $this->extractCjVariationPairs($product, $variant, $categoryId);
            $rawMaps[] = $map;
            foreach ($map as $name => $value) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $valueSets[$name][$value] = true;
            }
        }

        if ($rawMaps === []) {
            return [];
        }

        $preferredNames = $this->preferredVariationAspectOrder($aspects);
        $canonicalNames = [];
        foreach ($preferredNames as $name) {
            if (isset($counts[$name])) {
                $canonicalNames[] = $name;
            }
        }
        foreach ($counts as $name => $_count) {
            if (!in_array($name, $canonicalNames, true)) {
                $canonicalNames[] = $name;
            }
        }

        $canonicalNames = $this->safeArraySlice(array_values(array_filter(
            $canonicalNames,
            static fn (string $name): bool => trim($name) !== ''
        )), 0, 5);

        if ($canonicalNames === []) {
            $canonicalNames = ['Style'];
        }

        $resolved = [];
        foreach ($rawMaps as $index => $map) {
            $variant = $variants[$index] ?? [];
            $row = [];
            foreach ($canonicalNames as $name) {
                $value = trim((string) ($map[$name] ?? ''));
                if ($value === '') {
                    $value = $this->inferMissingVariationValue($name, $map, is_array($variant) ? $variant : [], $index);
                }
                if ($value !== '') {
                    $row[$name] = $value;
                }
            }

            if ($row === []) {
                $row['Style'] = $this->fallbackVariationValue(is_array($variant) ? $variant : [], $index);
            }
            $resolved[] = $this->cleanVariationPairMap($row);
        }

        return $this->ensureUniqueVariationCombinations($resolved, $variants);
    }

    /** @param list<array{name:string,required:bool,mode:string,values:list<string>,maxValues:int}> $aspects @return list<string> */
    private function preferredVariationAspectOrder(array $aspects): array
    {
        $names = ['Color', 'US Shoe Size', 'Size', 'Shoe Width', 'Width', 'Material', 'Length', 'Volume', 'Style'];
        foreach ($aspects as $aspect) {
            $name = trim((string) ($aspect['name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        foreach (['Option 1', 'Option 2', 'Option 3'] as $name) {
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /** @param array<string, string> $map @param array<string, mixed> $variant */
    private function inferMissingVariationValue(string $name, array $map, array $variant, int $index): string
    {
        if (($name === 'Size' || $name === 'US Shoe Size') && isset($map['Style']) && preg_match('/^(?:\d{1,3}(?:\.\d{1,2})?|[XSML]{1,4}|[3-9]XL)$/i', $map['Style']) === 1) {
            return $map['Style'];
        }
        if ($name === 'Color' && isset($map['Style']) && $this->looksLikeColor($map['Style'])) {
            return $map['Style'];
        }
        if (str_starts_with($name, 'Option ')) {
            return $this->fallbackVariationValue($variant, $index);
        }

        foreach (['Option 1', 'Style', 'Size', 'Color'] as $fallbackName) {
            $value = trim((string) ($map[$fallbackName] ?? ''));
            if ($value !== '' && $fallbackName !== $name) {
                return $value;
            }
        }

        if ($name === 'Color') {
            return 'Multicolor';
        }
        if ($name === 'Size' || $name === 'US Shoe Size') {
            return 'One Size';
        }

        return $this->fallbackVariationValue($variant, $index);
    }

    /** @param list<array<string, string>> $maps @param list<array<string, mixed>> $variants @return list<array<string, string>> */
    private function ensureUniqueVariationCombinations(array $maps, array $variants): array
    {
        $seen = [];
        $needsTieBreaker = false;
        foreach ($maps as $map) {
            $signature = $this->variationCombinationSignature($map);
            if (isset($seen[$signature])) {
                $needsTieBreaker = true;
                break;
            }
            $seen[$signature] = true;
        }

        if (!$needsTieBreaker) {
            return $maps;
        }

        foreach ($maps as $index => $map) {
            $optionName = $this->nextOptionName($map);
            $map[$optionName] = $this->fallbackVariationValue($variants[$index] ?? [], $index);
            $maps[$index] = $this->cleanVariationPairMap($map);
        }

        return $maps;
    }

    /** @param array<string, string> $map */
    private function variationCombinationSignature(array $map): string
    {
        ksort($map);
        return strtolower(json_encode($map, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<string, string> $map */
    private function nextOptionName(array $map): string
    {
        for ($index = 1; $index <= 3; $index++) {
            $name = 'Option ' . $index;
            if (!isset($map[$name])) {
                return $name;
            }
        }

        return 'Style';
    }

    /** @param array<string, mixed> $variant */
    private function fallbackVariationValue(array $variant, int $index): string
    {
        foreach (['variantSku', 'variantNameEn', 'variantName', 'vid'] as $key) {
            $value = trim((string) ($variant[$key] ?? ''));
            if ($value !== '') {
                $parts = preg_split('/[-_\s]+/', $value) ?: [];
                $tail = trim((string) end($parts));
                return $tail !== '' ? $tail : $value;
            }
        }

        return 'Option ' . ($index + 1);
    }

    /** @param array<string, mixed> $product @param array<string, mixed> $variant @return array<string, string> */
    private function mapDeclaredCjVariationNames(array $product, array $variant, string $categoryId): array
    {
        $names = $this->extractCjDeclaredVariationNames($product, $categoryId);
        if ($names === []) {
            return [];
        }

        $tokens = $this->extractCjVariationTokens($variant);
        if ($tokens === []) {
            return [];
        }

        $pairs = [];
        foreach ($names as $index => $name) {
            $value = trim((string) ($tokens[$index] ?? ''));
            if ($value !== '') {
                $pairs[$name] = $value;
            }
        }

        return $pairs;
    }

    /** @param array<string, mixed> $product @return list<string> */
    private function extractCjDeclaredVariationNames(array $product, string $categoryId): array
    {
        $rawNames = [];
        foreach (['productKeyEn', 'productKey', 'variantKeyNameEn', 'variantKeyName'] as $key) {
            $raw = $product[$key] ?? null;
            if (is_array($raw)) {
                foreach ($raw as $name) {
                    $candidate = trim((string) $name);
                    if ($candidate !== '') {
                        $rawNames[] = $candidate;
                    }
                }
                continue;
            }
            $rawString = trim((string) ($raw ?? ''));
            if ($rawString === '') {
                continue;
            }
            $decoded = json_decode($rawString, true);
            if (is_array($decoded)) {
                foreach ($decoded as $name) {
                    $candidate = trim((string) $name);
                    if ($candidate !== '') {
                        $rawNames[] = $candidate;
                    }
                }
                continue;
            }
            $parts = preg_match('/[,|\/]/', $rawString) === 1 ? (preg_split('/[,|\/]+/', $rawString) ?: []) : [$rawString];
            foreach ($parts as $name) {
                $candidate = trim((string) $name);
                if ($candidate !== '') {
                    foreach ($this->expandCjDeclaredVariationName($candidate, $categoryId) as $expandedName) {
                        $rawNames[] = $expandedName;
                    }
                }
            }
        }

        $names = [];
        foreach ($rawNames as $rawName) {
            foreach ($this->expandCjDeclaredVariationName($rawName, $categoryId) as $expandedRawName) {
                $name = $this->normalizeEbayVariationName($expandedRawName, $categoryId);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function expandCjDeclaredVariationName(string $name, string $categoryId): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $lower = strtolower($name);
        $hasColor = preg_match('/color|colour|颜色|顏色/i', $name) === 1;
        $hasSize = preg_match('/size|shoe|尺码|尺寸|码/i', $name) === 1;
        if ($hasColor && $hasSize) {
            return ['Color', $categoryId !== '' && $this->categoryPrefersUsShoeSize($categoryId) ? 'US Shoe Size' : 'Size'];
        }

        if (preg_match('/[-_+&]/', $name) === 1) {
            $parts = preg_split('/\s*[-_+&]\s*/', $name) ?: [];
            $expanded = [];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $expanded[] = $part;
                }
            }
            if (count($expanded) > 1) {
                return $expanded;
            }
        }

        return [$name];
    }

    /** @param array<string, mixed> $variant @return list<string> */
    private function extractCjVariationTokens(array $variant): array
    {
        $raw = trim((string) ($variant['variantKey'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($variant['variantNameEn'] ?? $variant['variantName'] ?? $variant['variantSku'] ?? ''));
        }
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(static fn (mixed $entry): string => trim((string) $entry), $decoded)));
        }

        $tokens = preg_split('/[-,\/|]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $tokens), static fn (string $value): bool => $value !== ''));
    }

    /** @param array<string, string> $pairs @return array<string, string> */
    private function normalizeEbayVariationPairNames(array $pairs, string $categoryId): array
    {
        $normalized = [];
        foreach ($pairs as $name => $value) {
            $canonical = $this->normalizeEbayVariationName((string) $name, $categoryId);
            $value = trim((string) $value);
            if ($canonical === 'Attribute' && $this->looksLikeSizeValue($value)) {
                $canonical = $categoryId !== '' && $this->categoryPrefersUsShoeSize($categoryId) ? 'US Shoe Size' : 'Size';
            }
            if ($canonical !== '' && $value !== '') {
                $normalized[$canonical] = $value;
            }
        }

        if (isset($normalized['Style'])) {
            $style = $normalized['Style'];
            if ($this->looksLikeSizeValue($style)) {
                $target = $categoryId !== '' && $this->categoryPrefersUsShoeSize($categoryId) ? 'US Shoe Size' : 'Size';
                if (!isset($normalized[$target])) {
                    $normalized[$target] = $style;
                }
                unset($normalized['Style']);
            } elseif (!isset($normalized['Color']) && $this->looksLikeColor($style)) {
                $normalized['Color'] = $style;
                unset($normalized['Style']);
            } elseif (isset($normalized['Color']) && strcasecmp($normalized['Color'], $style) === 0) {
                unset($normalized['Style']);
            }
        }

        foreach (['Option 1', 'Option 2', 'Option 3', 'Attribute', 'Style'] as $genericName) {
            $value = trim((string) ($normalized[$genericName] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($this->looksLikeSizeValue($value)) {
                $target = $categoryId !== '' && $this->categoryPrefersUsShoeSize($categoryId) ? 'US Shoe Size' : 'Size';
                if (!isset($normalized[$target])) {
                    $normalized[$target] = $value;
                }
                unset($normalized[$genericName]);
                continue;
            }
            foreach (['Color', 'US Shoe Size', 'Size', 'Width', 'Shoe Width'] as $specificName) {
                if (isset($normalized[$specificName]) && strcasecmp((string) $normalized[$specificName], $value) === 0) {
                    unset($normalized[$genericName]);
                    continue 2;
                }
            }
        }

        return $normalized;
    }

    private function looksLikeSizeValue(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/^(?:US\s*)?\d{1,3}(?:\.\d{1,2})?(?:\s*(?:-|to|\/)\s*\d{1,3}(?:\.\d{1,2})?)?$/i', $value) === 1
            || preg_match('/^(?:[XSML]{1,4}|[3-9]XL)$/i', $value) === 1
            || preg_match('/^\d{1,3}\s*(?:to|-)\s*\d{1,3}$/i', $value) === 1;
    }

    private function normalizeEbayVariationName(string $name, string $categoryId): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        if ($normalized === '') {
            return '';
        }

        $aliases = [
            '颜色' => 'Color',
            '顏色' => 'Color',
            'colour' => 'Color',
            'color' => 'Color',
            '颜色分类' => 'Color',
            '尺码' => 'Size',
            '尺寸' => 'Size',
            '大小' => 'Size',
            '鞋码' => 'US Shoe Size',
            'size' => 'Size',
            'shoe size' => 'US Shoe Size',
            'us size' => 'US Shoe Size',
            'us shoe size' => 'US Shoe Size',
            'width' => 'Width',
            'shoe width' => 'Shoe Width',
            'style' => 'Style',
            '款式' => 'Style',
            '型号' => 'Model',
            'model' => 'Model',
            'material' => 'Material',
            '材质' => 'Material',
            'length' => 'Length',
            'volume' => 'Volume',
            '容量' => 'Volume',
        ];

        $canonical = $aliases[$normalized] ?? ucwords($normalized);
        if ($canonical === 'Size' && $categoryId !== '' && $this->categoryPrefersUsShoeSize($categoryId)) {
            return 'US Shoe Size';
        }

        return $canonical;
    }

    /** @param array<string, string> $pairs @return array<string, string> */
    private function cleanVariationPairMap(array $pairs): array
    {
        $clean = [];
        foreach ($pairs as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            if (in_array(strtolower($name), ['quantity', 'startprice', 'price', 'condition', 'sku', 'upc', 'isbn', 'ean'], true)) {
                $name .= ' Option';
            }
            $clean[$name] = $value;
        }

        return $clean;
    }

    private function looksLikeColor(string $value): bool
    {
        $parsed = \App\Services\CjVariantParser::parseVariantKey($value);
        if (isset($parsed['Color']) && trim((string) $parsed['Color']) !== '') {
            return true;
        }

        return preg_match('/\b(?:black|white|red|blue|green|yellow|orange|purple|pink|grey|gray|brown|beige|gold|silver|transparent|multicolor|multi-color|coffee|khaki|navy|wine|ivory|cream|tan)\b/i', $value) === 1;
    }

    /** @param list<array<string, mixed>> $variants @return list<array<string, mixed>> */
    private function mergeCjVariantSources(mixed ...$sources): array
    {
        $merged = [];
        foreach ($sources as $source) {
            foreach ($this->normalizeCjVariants($source) as $index => $variant) {
                $key = trim((string) ($variant['vid'] ?? $variant['variantSku'] ?? ''));
                if ($key === '') {
                    $key = 'index-' . $index . '-' . count($merged);
                }
                $merged[$key] = isset($merged[$key])
                    ? $this->mergeNonEmptyVariantData($merged[$key], $variant)
                    : $variant;
            }
        }

        return array_values($merged);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, mixed> */
    private function mergeNonEmptyVariantData(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = array_replace_recursive((array) $left[$key], $value);
                continue;
            }
            $left[$key] = $value;
        }

        return $left;
    }

    /** @param list<array<string, mixed>> $variants @return list<array<string, mixed>> */
    private function enrichCjVariantsWithInventory(array $variants, array $product): array
    {
        $inventoryByVid = [];
        $inventoryData = is_array($product['_inventory'] ?? null) ? (array) $product['_inventory'] : [];
        foreach ((array) ($inventoryData['variantInventories'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $vid = trim((string) ($entry['vid'] ?? ''));
            if ($vid === '') {
                continue;
            }
            $quantity = $this->sumCjInventoryRows($entry['inventory'] ?? []);
            if ($quantity !== null) {
                $inventoryByVid[$vid] = $quantity;
            }
        }

        foreach ($variants as $index => $variant) {
            $vid = trim((string) ($variant['vid'] ?? ''));
            if ($vid !== '' && isset($inventoryByVid[$vid])) {
                $variant['_inventory_total'] = $inventoryByVid[$vid];
                $variant['_inventory_source'] = 'product_inventory';
            }
            $variants[$index] = $variant;
        }

        return $variants;
    }

    /** @param list<array<string, mixed>> $variants @return list<array<string, mixed>> */
    private function hydrateMissingCjVariantInventoryBySku(string $accessToken, array $variants): array
    {
        $missingIndexes = [];
        foreach ($variants as $index => $variant) {
            if (!$this->hasCjInventorySignal($variant) && trim((string) ($variant['variantSku'] ?? '')) !== '') {
                $missingIndexes[] = $index;
            }
        }

        if ($missingIndexes === [] || count($missingIndexes) > 50) {
            return $variants;
        }

        foreach ($missingIndexes as $index) {
            $sku = trim((string) ($variants[$index]['variantSku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            try {
                $stock = $this->cjService->getStockBySku($accessToken, $sku);
                $quantity = $this->sumCjInventoryRows($stock['data'] ?? []);
                if ($quantity !== null) {
                    $variants[$index]['_inventory_total'] = $quantity;
                    $variants[$index]['_inventory_source'] = 'sku_inventory';
                }
            } catch (Throwable) {
            }
        }

        return $variants;
    }

    /** @param array<string, mixed> $variant */
    private function hasCjInventorySignal(array $variant): bool
    {
        return $this->extractCjInventoryQuantity($variant) !== null;
    }

    /** @param array<string, mixed> $variant */
    private function extractCjInventoryQuantity(array $variant): ?int
    {
        $candidates = [];
        foreach (['_inventory_total', 'totalInventoryNum', 'totalInventory', 'inventoryNum', 'warehouseInventoryNum', 'storageNum'] as $key) {
            if (isset($variant[$key]) && is_numeric($variant[$key])) {
                $candidates[] = (int) $variant[$key];
            }
        }
        if (isset($variant['inventory']) && is_numeric($variant['inventory'])) {
            $candidates[] = (int) $variant['inventory'];
        }

        foreach (['inventories', 'inventory'] as $key) {
            if (isset($variant[$key]) && is_array($variant[$key])) {
                $quantity = $this->sumCjInventoryRows($variant[$key]);
                if ($quantity !== null) {
                    $candidates[] = $quantity;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        return max($candidates);
    }

    private function sumCjInventoryRows(mixed $rows): ?int
    {
        if (!is_array($rows)) {
            return is_numeric($rows) ? (int) $rows : null;
        }

        if ($rows === []) {
            return null;
        }

        $list = array_is_list($rows) ? $rows : [$rows];
        $total = 0;
        $found = false;
        foreach ($list as $row) {
            if (!is_array($row)) {
                if (is_numeric($row)) {
                    $total += (int) $row;
                    $found = true;
                }
                continue;
            }

            foreach (['totalInventoryNum', 'totalInventory', 'storageNum'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $total += (int) $row[$key];
                    $found = true;
                    continue 2;
                }
            }

            if (isset($row['stock']) && is_array($row['stock'])) {
                $stockTotal = 0;
                $stockFound = false;
                foreach ($row['stock'] as $stock) {
                    if (!is_array($stock)) {
                        continue;
                    }
                    foreach (['inventory', 'factoryInventory'] as $key) {
                        if (isset($stock[$key]) && is_numeric($stock[$key])) {
                            $stockTotal += (int) $stock[$key];
                            $stockFound = true;
                        }
                    }
                }
                if ($stockFound) {
                    $total += $stockTotal;
                    $found = true;
                    continue;
                }
            }

            $fallbackTotal = 0;
            $fallbackFound = false;
            foreach (['cjInventoryNum', 'factoryInventoryNum', 'cjInventory', 'factoryInventory'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $fallbackTotal += (int) $row[$key];
                    $fallbackFound = true;
                }
            }
            if ($fallbackFound) {
                $total += $fallbackTotal;
                $found = true;
            }
        }

        return $found ? max(0, $total) : null;
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param array<string, string> $skuVidMap
     * @return list<array{ItemID:string,SKU?:string,Quantity:int}>
     */
    private function buildEbayInventoryStatusRows(string $itemId, array $variants, array $skuVidMap, int $quantityCap): array
    {
        $byVid = [];
        $bySku = [];
        foreach ($variants as $variant) {
            $vid = trim((string) ($variant['vid'] ?? ''));
            $sku = trim((string) ($variant['variantSku'] ?? ''));
            if ($vid !== '') {
                $byVid[$vid] = $variant;
            }
            if ($sku !== '') {
                $bySku[$sku] = $variant;
            }
        }

        $rows = [];
        if ($skuVidMap !== []) {
            foreach ($skuVidMap as $sku => $vid) {
                $sku = trim((string) $sku);
                $variant = $byVid[trim((string) $vid)] ?? $bySku[$sku] ?? null;
                if (!is_array($variant) || !$this->hasCjInventorySignal($variant)) {
                    continue;
                }
                $row = [
                    'ItemID' => $itemId,
                    'Quantity' => $this->capEbayListingQuantity($this->extractCjVariantQuantity($variant, 0), $quantityCap),
                ];
                if ($sku !== '') {
                    $row['SKU'] = $sku;
                }
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            foreach ($variants as $variant) {
                if (!$this->hasCjInventorySignal($variant)) {
                    continue;
                }
                $sku = trim((string) ($variant['variantSku'] ?? ''));
                $row = [
                    'ItemID' => $itemId,
                    'Quantity' => $this->capEbayListingQuantity($this->extractCjVariantQuantity($variant, 0), $quantityCap),
                ];
                if ($sku !== '') {
                    $row['SKU'] = $sku;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, string> $map @return list<array<string, mixed>> */
    private function nameValueListFromMap(array $map): array
    {
        $rows = [];
        foreach ($map as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $rows[] = ['Name' => $name, 'Value' => $value];
        }
        return $rows;
    }

    /** @param array<string, list<string>> $map @return list<array<string, mixed>> */
    private function nameValueListFromMultiMap(array $map): array
    {
        $rows = [];
        foreach ($map as $name => $values) {
            $name = trim((string) $name);
            $values = array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $values
            ), static fn (string $value): bool => $value !== '')));
            if ($name === '' || $values === []) {
                continue;
            }
            $rows[] = ['Name' => $name, 'Value' => $values];
        }
        return $rows;
    }

    /** @param array<string, mixed> $account */
    private function formatSellingLimit(array $account): string
    {
        $body = (array) ($account['body'] ?? []);
        $amount = (array) (($body['sellingLimit'] ?? [])['amount'] ?? []);
        $quantity = (int) (($body['sellingLimit'] ?? [])['quantity'] ?? 0);
        if (!isset($amount['currency'], $amount['value'])) {
            return 'Unavailable';
        }

        return (string) $amount['currency'] . ' ' . (string) $amount['value'] . ' / ' . $quantity . ' items';
    }

    /** @param array<string, mixed> $store */
    private function storeHeadline(array $store): string
    {
        if (($store['ok'] ?? false) === true) {
            $name = trim((string) (($store['body'] ?? [])['storeName'] ?? ''));
            return $name !== '' ? $name : 'Store available';
        }

        $error = strtolower((string) ($store['error'] ?? ''));
        if (str_contains($error, 'active store subscription')) {
            return 'No active store subscription';
        }

        return 'Store status unavailable';
    }

    /** @param array<string, mixed> $store */
    private function storeSubline(array $store): string
    {
        if (($store['ok'] ?? false) === true) {
            $url = trim((string) (($store['body'] ?? [])['storeUrl'] ?? ''));
            return $url !== '' ? 'Storefront is available at ' . $url : 'The Stores API is live for this account.';
        }

        $error = (string) ($store['error'] ?? '');
        if ($error !== '') {
            return $error;
        }

        return 'The store endpoint did not return usable storefront data.';
    }

    /** @param array<string, mixed> $body */
    private function extractError(array $body): string
    {
        $errors = $body['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            return (string) ($errors[0]['longMessage'] ?? $errors[0]['message'] ?? '');
        }

        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return '';
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

        if ($this->config->userRefreshToken !== '') {
            try {
                $token = $this->oauthService->refreshAccessToken($this->config->userRefreshToken);
                $this->tokenRepository->save($token);
                $this->persistUserTokenToEnv((string) ($token['access_token'] ?? ''), (string) ($token['refresh_token'] ?? $this->config->userRefreshToken));
                $resolvedTokenSource = 'env_refresh';
                return (string) ($token['access_token'] ?? '');
            } catch (Throwable) {
            }
        }

        $latest = $this->tokenRepository->latest();
        if (is_array($latest) && isset($latest['access_token'])) {
            $resolvedTokenSource = 'stored_oauth';
            return (string) $latest['access_token'];
        }

        if ($this->config->appToken !== '') {
            $resolvedTokenSource = 'app_fallback';
            return $this->config->appToken;
        }

        $resolvedTokenSource = 'none';
        return '';
    }

    /** @param list<mixed> $campaigns */
    private function countRunningCampaigns(array $campaigns): int
    {
        $count = 0;
        foreach ($campaigns as $campaign) {
            if (is_array($campaign) && (string) ($campaign['campaignStatus'] ?? '') === 'RUNNING') {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string, mixed> $budget */
    private function formatCampaignBudget(array $budget): string
    {
        $daily = (array) ($budget['daily'] ?? []);
        $amount = (array) ($daily['amount'] ?? []);
        if (isset($amount['currency'], $amount['value'])) {
            return (string) $amount['currency'] . ' ' . (string) $amount['value'] . '/day';
        }

        return 'Not set';
    }

    private function statusLabel(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'HTTP ' . $status;
        }
        return $status > 0 ? 'HTTP ' . $status : 'No response';
    }

    private function tokenSourceLabel(string $source): string
    {
        return match ($source) {
            'stored_oauth', 'user' => 'Stored OAuth token',
            'env_user' => 'Manual user token',
            'env_refresh' => 'Refreshed OAuth token',
            'app' => 'App token',
            'app_fallback' => 'App token fallback',
            default => 'No token',
        };
    }

    /** @return list<string> */
    private function normalizeStrings(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $items[] = $entry;
            }
        }
        return $items;
    }

    /** @return array<int|string, mixed> */
    private function safeArraySlice(mixed $value, int $offset, ?int $length = null, bool $preserveKeys = false): array
    {
        if (!is_array($value)) {
            return [];
        }

        return $length === null
            ? array_slice($value, $offset, null, $preserveKeys)
            : array_slice($value, $offset, $length, $preserveKeys);
    }

    private function truncateText(string $value, int $limit = 32, string $suffix = '........'): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $suffixLength = function_exists('mb_strlen') ? mb_strlen($suffix) : strlen($suffix);
        $sliceLength = max(0, $limit - $suffixLength);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $limit ? mb_substr($value, 0, $sliceLength) . $suffix : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $sliceLength) . $suffix : $value;
    }

    /** @return list<array{threshold:float,percent:float}> */
    private function parseMarkupRulesFromPost(): array
    {
        $thresholds = (array) ($_POST['markup_thresholds'] ?? []);
        $percents = (array) ($_POST['markup_percents'] ?? []);
        $rules = [];
        $count = max(count($thresholds), count($percents));
        for ($index = 0; $index < $count; $index++) {
            $thresholdRaw = trim((string) ($thresholds[$index] ?? ''));
            $percentRaw = trim((string) ($percents[$index] ?? ''));
            if ($thresholdRaw === '' || $percentRaw === '') {
                continue;
            }
            $threshold = (float) $thresholdRaw;
            $percent = (float) $percentRaw;
            if ($threshold <= 0) {
                continue;
            }
            $rules[] = [
                'threshold' => $threshold,
                'percent' => $percent,
            ];
        }

        usort($rules, static fn (array $left, array $right): int => $left['threshold'] <=> $right['threshold']);

        return $rules;
    }

    /** @param list<array{threshold:float,percent:float}> $rules */
    private function resolveMarkupPercentForBasePrice(float $basePrice, float $defaultPercent, array $rules): float
    {
        foreach ($rules as $rule) {
            if ($basePrice <= $rule['threshold']) {
                return $rule['percent'];
            }
        }

        return $defaultPercent;
    }

    private function categoryPrefersUsShoeSize(string $categoryId): bool
    {
        if (array_key_exists($categoryId, $this->categoryUsShoeSizePreferenceCache)) {
            return $this->categoryUsShoeSizePreferenceCache[$categoryId];
        }

        foreach ($this->loadEbayAspectRequirements($categoryId) as $aspect) {
            $name = strtolower(trim((string) ($aspect['name'] ?? '')));
            if ($name === 'us shoe size') {
                return $this->categoryUsShoeSizePreferenceCache[$categoryId] = true;
            }
        }

        return $this->categoryUsShoeSizePreferenceCache[$categoryId] = false;
    }

    private function renderCjPaginationLinks(int $currentPage, int $totalPages, string $pageSizeQuery): string
    {
        $marketSampleSize = (string) $this->resolveMarketSampleSizeFromRequest();
        if ($totalPages <= 1) {
            return '<a class="button button-primary" href="' . htmlspecialchars($this->appUrl([
                'page' => 'listings',
                'cj_query' => (string) ($_GET['cj_query'] ?? ''),
                'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
                'cj_category' => (string) ($_GET['cj_category'] ?? ''),
                'cj_category_id' => (string) ($_GET['cj_category_id'] ?? ''),
                'cj_page' => '1',
                'cj_page_size' => $pageSizeQuery,
                'cj_sort' => (string) ($_GET['cj_sort'] ?? 'desc'),
                'market_sample_size' => $marketSampleSize,
            ]), ENT_QUOTES, 'UTF-8') . '">1</a>';
        }

        $pages = [1, $totalPages];
        for ($page = $currentPage - 2; $page <= $currentPage + 2; $page++) {
            if ($page >= 1 && $page <= $totalPages) {
                $pages[] = $page;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '';
        $previous = null;
        foreach ($pages as $page) {
            if ($previous !== null && $page > $previous + 1) {
                $html .= '<span class="mini" style="padding:0 6px">...</span>';
            }

            $pageUrl = $this->appUrl([
                'page' => 'listings',
                'cj_query' => (string) ($_GET['cj_query'] ?? ''),
                'cj_country' => (string) ($_GET['cj_country'] ?? 'US'),
                'cj_category' => (string) ($_GET['cj_category'] ?? ''),
                'cj_category_id' => (string) ($_GET['cj_category_id'] ?? ''),
                'cj_page' => (string) $page,
                'cj_page_size' => $pageSizeQuery,
                'cj_sort' => (string) ($_GET['cj_sort'] ?? 'desc'),
                'market_sample_size' => $marketSampleSize,
            ]);
            $html .= '<a class="button ' . ($page === $currentPage ? 'button-primary' : 'button-secondary') . '" href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '">' . $page . '</a>';
            $previous = $page;
        }

        return $html;
    }

    /** @return array<string, string> */
    private function normalizeNameValueList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (!array_is_list($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $raw = $entry['Value'] ?? '';
            if (is_array($raw)) {
                $values = [];
                foreach ($raw as $candidate) {
                    if (is_string($candidate) && $candidate !== '') {
                        $values[] = $candidate;
                    }
                }
                $items[$name] = implode(', ', $values);
            } else {
                $items[$name] = (string) $raw;
            }
        }
        return $items;
    }

    /** @return list<array<string, string>> */
    private function normalizeVariations(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (!array_is_list($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $specifics = $this->normalizeNameValueList($entry['VariationSpecifics']['NameValueList'] ?? []);
            $items[] = [
                'sku' => (string) ($entry['SKU'] ?? ''),
                'price' => $this->formatTradingPrice($entry['StartPrice'] ?? ''),
                'quantity' => (string) ($entry['Quantity'] ?? ''),
                'specifics' => implode(' | ', array_map(static fn (string $name, string $val): string => $name . ': ' . $val, array_keys($specifics), array_values($specifics))),
            ];
        }
        return $items;
    }

    /** @param array<int, mixed> $lineItems @return list<array<string, string>> */
    private function normalizeOrderLineItems(array $lineItems): array
    {
        $items = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }
            $id = (string) ($lineItem['lineItemId'] ?? '');
            if ($id === '') {
                continue;
            }
            $items[] = [
                'lineItemId' => $id,
                'label' => $id . ' - ' . (string) ($lineItem['title'] ?? $lineItem['sku'] ?? 'Line item'),
            ];
        }
        return $items;
    }

    private function formatTradingPrice(mixed $value): string
    {
        if (is_array($value)) {
            $currency = (string) ($value['@currencyID'] ?? 'USD');
            $amount = (string) ($value['#text'] ?? '');
            return trim($currency . ' ' . $amount);
        }
        return is_scalar($value) ? (string) $value : 'Not available';
    }

    /** @return list<string> */
    private function parseMultilineList(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function parseNameValueLines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$name, $val] = array_map('trim', explode('=', $line, 2));
            if ($name === '' || $val === '') {
                continue;
            }
            $items[] = [
                'Name' => $name,
                'Value' => [$val],
            ];
        }
        return $items;
    }

    private function statusBadgeHtml(string $status): string
    {
        $upper = strtoupper($status);
        $tone = str_contains($upper, 'RUN') || str_contains($upper, 'PUBLISH') || str_contains($upper, 'ENABLE') || str_contains($upper, 'COMPLETE')
            ? 'good'
            : (str_contains($upper, 'ERROR') || str_contains($upper, 'FAIL') ? 'warn' : 'neutral');

        return '<span class="badge ' . $tone . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
