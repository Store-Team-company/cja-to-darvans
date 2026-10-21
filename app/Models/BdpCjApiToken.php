<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpCjApiToken extends Model
{
    protected $table = 'bdp_cj_api_tokens';
    protected $guarded = [];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
