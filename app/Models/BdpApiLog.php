<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpApiLog extends Model
{
    protected $table = 'bdp_api_logs';

    protected $guarded = [];

    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_body' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
