<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BdpProduct extends Model
{
    protected $table = 'bdp_products';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'cost_per_item' => 'decimal:2',
        'cj_shipping_fee' => 'decimal:2',
        'landed_cost' => 'decimal:2',
        'is_featured' => 'boolean',
        'last_exported_at' => 'datetime',
        'shofy_pushed_at' => 'datetime',
        'shofy_variations_count' => 'integer',
        'balenco_synced_at' => 'datetime',
    ];

    public function variations(): HasMany
    {
        return $this->hasMany(BdpProductVariation::class, 'bdp_product_id')->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BdpProductReview::class, 'bdp_product_id')->latest('review_date')->latest();
    }
}
