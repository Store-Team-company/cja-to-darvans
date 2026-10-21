<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BdpProductReview extends Model
{
    protected $table = 'bdp_product_reviews';

    protected $guarded = [];

    protected $casts = [
        'score' => 'integer',
        'review_images' => 'array',
        'raw_json' => 'array',
        'is_approved' => 'boolean',
        'is_pushed' => 'boolean',
        'review_date' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(BdpProduct::class, 'bdp_product_id');
    }
}
