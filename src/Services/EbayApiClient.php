<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\EbayConfig;
use RuntimeException;

class EbayApiClient
{
    public function __construct(private readonly EbayConfig $config)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path, $this->config->appToken);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        string $token,
        array $query = [],
        array $extraHeaders = [],
        mixed $body = null
    ): array {
        if ($token === '') {
            throw new RuntimeException('OAuth token is missing.');
        }

        $path = '/' . ltrim($path, '/');
        $queryString = $query !== [] ? '?' . http_build_query($query) : '';
        $url = rtrim($this->config->apiBase, '/') . '/' . ltrim($path, '/');
        $url .= $queryString;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            return $this->requestViaCurl($method, $url, $headers, $body);
        }

        $content = null;
        if ($body !== null) {
            $content = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
            if (!is_string($content)) {
                throw new RuntimeException('Failed to encode request body.');
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 20,
                'content' => $content,
            ],
            'ssl' => [
                'verify_peer' => !$this->config->disableSslVerify,
                'verify_peer_name' => !$this->config->disableSslVerify,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = isset($matches[1]) ? (int) $matches[1] : 500;

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [
                'raw' => $raw,
                'error' => $raw === false ? 'Request failed before receiving response body.' : null,
            ];
        }

        return [
            'status' => $status,
            'url' => $url,
            'body' => $decoded,
        ];
    }

    /** @param list<string> $headers */
    private function requestViaCurl(string $method, string $url, array $headers, mixed $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                throw new RuntimeException('Failed to encode request body.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if ($this->config->disableSslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [
                'raw' => $raw,
                'error' => $error !== '' ? $error : null,
            ];
        }

        return [
            'status' => $status > 0 ? $status : 500,
            'url' => $url,
            'body' => $decoded,
        ];
    }
}
