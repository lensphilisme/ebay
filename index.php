<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/Env.php';
require_once __DIR__ . '/src/Config/EbayConfig.php';
require_once __DIR__ . '/src/Services/ChallengeResponder.php';
require_once __DIR__ . '/src/Repositories/SqliteWebhookLogRepository.php';
require_once __DIR__ . '/src/Repositories/SqliteTokenRepository.php';
require_once __DIR__ . '/src/Controllers/EbayWebhookController.php';
require_once __DIR__ . '/src/Services/EbayApiClient.php';
require_once __DIR__ . '/src/Controllers/EbayApiController.php';
require_once __DIR__ . '/src/Services/EbayOAuthService.php';
require_once __DIR__ . '/src/Services/CjVariantParser.php';
require_once __DIR__ . '/src/Services/MarketplaceTemplateService.php';
require_once __DIR__ . '/src/Services/CjMarketplaceExportService.php';
require_once __DIR__ . '/src/Controllers/EbayDashboardController.php';
require_once __DIR__ . '/src/Services/EbayTradingService.php';
require_once __DIR__ . '/src/Services/CjDropshippingService.php';
require_once __DIR__ . '/src/Services/CjOrderFulfillmentSync.php';
require_once __DIR__ . '/src/Repositories/SqliteCjMappingRepository.php';

use App\Config\EbayConfig;
use App\Controllers\EbayApiController;
use App\Controllers\EbayDashboardController;
use App\Controllers\EbayWebhookController;
use App\Repositories\SqliteCjMappingRepository;
use App\Repositories\SqliteTokenRepository;
use App\Repositories\SqliteWebhookLogRepository;
use App\Services\ChallengeResponder;
use App\Services\CjDropshippingService;
use App\Services\EbayApiClient;
use App\Services\EbayOAuthService;
use App\Services\EbayTradingService;
use App\Support\Env;

Env::load(__DIR__ . '/.env');
session_start();
$config = EbayConfig::fromEnv();
$dbPath = __DIR__ . '/' . $config->dbPath;
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$queryAction = (string) ($_GET['action'] ?? '');

$dashboardFactory = static fn (): EbayDashboardController => new EbayDashboardController(
    $config,
    new EbayOAuthService($config),
    new SqliteTokenRepository($dbPath),
    new EbayApiClient($config),
    new EbayTradingService($config),
    new CjDropshippingService($config),
    new SqliteCjMappingRepository($dbPath)
);

if ($uriPath === '/ebay/ebay.php' || $uriPath === '/ebay.php') {
    $webhook = new EbayWebhookController(
        $config,
        new ChallengeResponder(),
        new SqliteWebhookLogRepository($dbPath)
    );
    $webhook->handle();
    exit;
}

if ($uriPath === '/ebay/cj-webhook.php' || $uriPath === '/ebay/cj/webhook') {
    $webhook = new EbayWebhookController(
        $config,
        new ChallengeResponder(),
        new SqliteWebhookLogRepository($dbPath),
        new SqliteCjMappingRepository($dbPath)
    );
    $webhook->handleCj();
    exit;
}

if ($uriPath === '/ebay/api/subscriptions') {
    $api = new EbayApiController(
        new EbayApiClient($config),
        new SqliteTokenRepository($dbPath),
        $config,
        new EbayTradingService($config)
    );
    $api->subscriptions();
    exit;
}

if ($uriPath === '/ebay/api/action') {
    $api = new EbayApiController(
        new EbayApiClient($config),
        new SqliteTokenRepository($dbPath),
        $config,
        new EbayTradingService($config)
    );
    $actionName = (string) ($_GET['name'] ?? '');
    $api->action($actionName);
    exit;
}

if ($uriPath === '/ebay/dashboard') {
    header('Location: /ebay/');
    exit;
}

if ($uriPath === '/ebay/export/download') {
    $dashboard = $dashboardFactory();
    $dashboard->downloadExport();
    exit;
}

if ($uriPath === '/ebay/oauth/start' || (($uriPath === '/ebay' || $uriPath === '/ebay/' || $uriPath === '/ebay/index.php') && $queryAction === 'oauth_start')) {
    $dashboard = $dashboardFactory();
    try {
        $dashboard->oauthStart();
    } catch (Throwable $throwable) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($uriPath === '/ebay/oauth/callback' || $uriPath === '/ebay/oauth/callback/' || $uriPath === '/ebay/callback' || $uriPath === '/ebay/callback/') {
    $dashboard = $dashboardFactory();
    try {
        $dashboard->oauthCallback();
    } catch (Throwable $throwable) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (
    $uriPath === '/ebay/manage'
    || ((($uriPath === '/ebay' || $uriPath === '/ebay/' || $uriPath === '/ebay/index.php')) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $queryAction === 'manage')
) {
    $dashboard = $dashboardFactory();
    try {
        $dashboard->manageAction();
    } catch (Throwable $throwable) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($uriPath === '/ebay' || $uriPath === '/ebay/' || $uriPath === '/ebay/index.php' || $uriPath === '/') {
    $dashboard = $dashboardFactory();
    $dashboard->home();
    exit;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'Route not found',
    'known_routes' => [
        '/ebay/',
        '/ebay/oauth/start',
        '/ebay/oauth/callback',
        '/ebay/api/action?name=inventory_items',
        '/ebay/api/subscriptions',
        '/ebay/ebay.php',
        '/ebay/cj-webhook.php',
    ],
], JSON_UNESCAPED_SLASHES);
