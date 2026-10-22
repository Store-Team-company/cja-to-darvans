<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaasBotbleStore extends Model
{
    protected $table = 'saas_botble_stores';

    protected $guarded = ['id', 'workspace_id', 'shared_secret', 'public_id'];

    protected $casts = [
        'shared_secret' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];

    protected $hidden = ['shared_secret'];

    protected static function booted(): void
    {
        static::creating(function (self $store): void {
            $store->public_id ??= (string) Str::uuid();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SaasWorkspace::class, 'workspace_id');
    }
}
