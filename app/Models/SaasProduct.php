<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasProduct extends Model
{
    protected $table = 'saas_products';

    // Never allow a user-supplied workspace_id through mass assignment.
    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'source_snapshot' => 'array',
        'selling_price_usd' => 'decimal:2',
        'merchant_edited_at' => 'datetime',
        'sale_price_usd' => 'decimal:2',
        'gallery_images' => 'array',
        'source_variants' => 'array',
        'cj_details_fetched_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SaasWorkspace::class, 'workspace_id');
    }
}
