<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SaasBotbleProductDelivery extends Model
{
    protected $table = 'saas_botble_product_deliveries';

    protected $guarded = ['id', 'workspace_id', 'product_id', 'store_id'];

    protected $hidden = ['payload_json'];

    protected $casts = [
        'lease_until' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
