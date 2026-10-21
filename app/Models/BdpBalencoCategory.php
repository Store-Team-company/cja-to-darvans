<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpBalencoCategory extends Model
{
    protected $table = 'bdp_balenco_categories';

    protected $fillable = [
        'balenco_category_id',
        'parent_id',
        'name',
        'path',
        'status',
        'raw_json',
    ];

    protected $casts = [
        'raw_json' => 'array',
    ];
}
