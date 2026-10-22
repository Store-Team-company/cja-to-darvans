<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasCjConnection extends Model
{
    protected $table = 'saas_cj_connections';

    protected $guarded = ['id', 'workspace_id'];

    // Laravel encrypted casts require APP_KEY and cannot be queried as plaintext.
    protected $casts = [
        'api_key' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];

    protected $hidden = ['api_key', 'access_token', 'refresh_token'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SaasWorkspace::class, 'workspace_id');
    }
}
