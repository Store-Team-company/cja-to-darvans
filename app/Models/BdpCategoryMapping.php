<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpCategoryMapping extends Model
{
    protected $table = 'bdp_category_mappings';

    protected $fillable = [
        'source_category',
        'balenco_category_id',
        'balenco_category_name',
        'balenco_category_path',
        'notes',
    ];
}
