<?php

namespace App\Services\Saas;

use RuntimeException;

/** Only pre-approved, credential-free status codes cross the API boundary. */
final class SaasCjApiException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function safeMessage(): string
    {
        return match ($this->reason) {
            'invalid_credentials' => 'CJ rejected these credentials. Check the API key and its authorization in your CJ account.',
            'rate_limited' => 'CJ is temporarily limiting requests. Try again later.',
            'refresh_rejected' => 'CJ rejected the existing refresh token. Reconnect using a valid API key.',
            'invalid_response' => 'CJ returned an unexpected authentication response. Please retry later.',
            default => 'Unable to reach CJ securely. Please retry later.',
        };
    }
}
