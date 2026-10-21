<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BdpProductVariation extends Model
{
    protected $table = 'bdp_product_variations';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'cost_per_item' => 'decimal:2',
        'is_default' => 'boolean',
        'cj_shipping_fee' => 'decimal:2',
        'landed_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(BdpProduct::class, 'bdp_product_id');
    }
}
