<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EbayConfig;
use RuntimeException;

final class EbayOAuthService
{
    public const ALL_AUTH_CODE_SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.marketing.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.marketing',
        'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        'https://api.ebay.com/oauth/api_scope/sell.analytics.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.finances',
        'https://api.ebay.com/oauth/api_scope/sell.payment.dispute',
        'https://api.ebay.com/oauth/api_scope/commerce.identity.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.reputation',
        'https://api.ebay.com/oauth/api_scope/sell.reputation.readonly',
        'https://api.ebay.com/oauth/api_scope/commerce.notification.subscription',
        'https://api.ebay.com/oauth/api_scope/commerce.notification.subscription.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.stores',
        'https://api.ebay.com/oauth/api_scope/sell.stores.readonly',
        'https://api.ebay.com/oauth/scope/sell.edelivery',
        'https://api.ebay.com/oauth/api_scope/commerce.vero',
        'https://api.ebay.com/oauth/api_scope/sell.inventory.mapping',
        'https://api.ebay.com/oauth/api_scope/commerce.message',
        'https://api.ebay.com/oauth/api_scope/commerce.feedback',
        'https://api.ebay.com/oauth/api_scope/commerce.shipping',
    ];

    public function __construct(private readonly EbayConfig $config)
    {
    }

    /** @param list<string> $scopes */
    public function buildConsentUrl(array $scopes, string $state): string
    {
        if ($this->config->clientId === '' || $this->config->ruName === '') {
            throw new RuntimeException('EBAY_CLIENT_ID and EBAY_RUNAME are required.');
        }

        $query = http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->ruName,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);

        return 'https://auth.ebay.com/oauth2/authorize?' . $query;
    }

    /** @return array<string, mixed> */
    public function exchangeCodeForToken(string $code): array
    {
        if ($this->config->clientId === '' || $this->config->clientSecret === '' || $this->config->ruName === '') {
            throw new RuntimeException('Missing EBAY_CLIENT_ID, EBAY_CLIENT_SECRET, or EBAY_RUNAME.');
        }

        $payload = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->ruName,
        ]);

        $headers = [
            'Authorization: Basic ' . base64_encode($this->config->clientId . ':' . $this->config->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        $ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if ($this->config->disableSslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OAuth token exchange failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OAuth token exchange returned invalid JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Unknown token exchange error');
            throw new RuntimeException('OAuth token exchange failed: ' . $message);
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function refreshAccessToken(string $refreshToken): array
    {
        if ($this->config->clientId === '' || $this->config->clientSecret === '') {
            throw new RuntimeException('Missing EBAY_CLIENT_ID or EBAY_CLIENT_SECRET.');
        }
        if ($refreshToken === '') {
            throw new RuntimeException('Missing refresh token.');
        }

        $payload = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', self::ALL_AUTH_CODE_SCOPES),
        ]);

        $headers = [
            'Authorization: Basic ' . base64_encode($this->config->clientId . ':' . $this->config->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        $ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if ($this->config->disableSslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OAuth token refresh failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OAuth token refresh returned invalid JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Unknown token refresh error');
            throw new RuntimeException('OAuth token refresh failed: ' . $message);
        }

        if (!isset($decoded['refresh_token'])) {
            $decoded['refresh_token'] = $refreshToken;
        }

        return $decoded;
    }
}
