<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Machine-to-machine WooCommerce-compatible API uses consumer credentials,
        // not a browser session/CSRF token. Admin approval remains CSRF protected.
        'wp-json/wc/*',
        'wc-api/v3/*',
        'login/wp-json/wc/*',
        'login/wc-api/v3/*',
    ];
}
