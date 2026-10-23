<?php

namespace Botble\DatapilotConnector\Services;

final class ConnectorSigning
{
    public static function sign(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return hash_hmac('sha256', strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$body, $secret);
    }

    public static function valid(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        ?int $now = null
    ): bool {
        $now ??= time();
        if (!preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs($now - (int) $timestamp) > 300
            || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)) {
            return false;
        }

        return hash_equals(
            self::sign($secret, $method, $path, $timestamp, $nonce, $body),
            substr($signature, 7)
        );
    }
}
