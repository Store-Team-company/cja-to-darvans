<?php

namespace App\Services\Saas;

use RuntimeException;

final class SaasBotbleDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
