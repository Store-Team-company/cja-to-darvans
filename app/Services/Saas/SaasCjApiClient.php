<?php

namespace App\Services\Saas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CJ API v2 authentication only. Fixed HTTPS endpoints, no redirects, no
 * raw response/exception/body logging and no browser-visible credentials.
 */
final class SaasCjApiClient
{
    private const HOST = 'https://developers.cjdropshipping.com';
    private const AUTH = '/api2.0/v1/authentication/getAccessToken';
    private const REFRESH = '/api2.0/v1/authentication/refreshAccessToken';

    public function authorize(string $apiKey): array
    {
        return $this->request(self::AUTH, ['apiKey' => $apiKey], false);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->request(self::REFRESH, ['refreshToken' => $refreshToken], true);
    }

    private function request(string $path, array $payload, bool $refresh): array
    {
        try {
            $response = Http::acceptJson()->asJson()
                ->connectTimeout(5)->timeout(12)
                ->withOptions(['allow_redirects' => false])
                ->post(self::HOST.$path, $payload);
        } catch (ConnectionException) {
            throw new SaasCjApiException('unavailable');
        } catch (Throwable) {
            // Includes TLS/client errors: exception message may contain the key.
            throw new SaasCjApiException('unavailable');
        }

        if ($response->status() === 429) {
            throw new SaasCjApiException('rate_limited');
        }
        if (!$response->ok()) {
            throw new SaasCjApiException('unavailable');
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new SaasCjApiException('invalid_response');
        }
        if (($body['code'] ?? null) === 429 || in_array((int) ($body['code'] ?? 0), [1600008, 1600014], true)) {
            throw new SaasCjApiException('rate_limited');
        }
        if (($body['success'] ?? false) !== true
            || ($body['result'] ?? false) !== true
            || (int) ($body['code'] ?? 0) !== 200) {
            throw new SaasCjApiException($refresh ? 'refresh_rejected' : 'invalid_credentials');
        }
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new SaasCjApiException('invalid_response');
        }

        $access = $data['accessToken'] ?? null;
        $nextRefresh = $data['refreshToken'] ?? null;
        if (!is_string($access) || trim($access) === '' || strlen($access) > 2048
            || !is_string($nextRefresh) || trim($nextRefresh) === '' || strlen($nextRefresh) > 2048) {
            throw new SaasCjApiException('invalid_response');
        }

        try {
            $accessExpires = Carbon::parse($data['accessTokenExpiryDate'] ?? null);
            $refreshExpires = Carbon::parse($data['refreshTokenExpiryDate'] ?? null);
        } catch (Throwable) {
            throw new SaasCjApiException('invalid_response');
        }
        if (empty($data['accessTokenExpiryDate']) || empty($data['refreshTokenExpiryDate'])
            || !$accessExpires->isFuture() || !$refreshExpires->isFuture()) {
            throw new SaasCjApiException('invalid_response');
        }

        return [
            'access_token' => $access,
            'refresh_token' => $nextRefresh,
            'access_token_expires_at' => $accessExpires,
            'refresh_token_expires_at' => $refreshExpires,
        ];
    }
}
