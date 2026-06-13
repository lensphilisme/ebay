<?php
declare(strict_types=1);

namespace App\Config;

use App\Support\Env;

final class EbayConfig
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $devId,
        public readonly string $ruName,
        public readonly string $appBaseUrl,
        public readonly string $verificationToken,
        public readonly string $webhookEndpoint,
        public readonly string $appToken,
        public readonly string $userToken,
        public readonly string $userRefreshToken,
        public readonly string $cjApiKey,
        public readonly string $cjAccessToken,
        public readonly string $cjRefreshToken,
        public readonly string $cjApiBase,
        public readonly string $apiBase,
        public readonly string $dbPath,
        public readonly bool $disableSslVerify
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            Env::get('EBAY_CLIENT_ID'),
            Env::get('EBAY_CLIENT_SECRET'),
            Env::get('EBAY_DEV_ID'),
            Env::get('EBAY_RUNAME'),
            rtrim(Env::get('APP_BASE_URL'), '/'),
            Env::get('EBAY_VERIFICATION_TOKEN'),
            Env::get('EBAY_WEBHOOK_ENDPOINT'),
            Env::get('EBAY_APP_TOKEN'),
            Env::get('EBAY_USER_TOKEN'),
            Env::get('EBAY_USER_REFRESH_TOKEN'),
            Env::get('CJ_API_KEY'),
            Env::get('CJ_ACCESS_TOKEN'),
            Env::get('CJ_REFRESH_TOKEN'),
            Env::get('CJ_API_BASE', 'https://developers.cjdropshipping.com/api2.0/v1'),
            Env::get('EBAY_API_BASE', 'https://api.ebay.com'),
            Env::get('WEBHOOK_DB_PATH', 'database/webhooks.sqlite'),
            self::toBool(Env::get('EBAY_DISABLE_SSL_VERIFY'))
        );
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
