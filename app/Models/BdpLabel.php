<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpLabel extends Model
{
    protected $table = 'bdp_labels';

    protected $fillable = [
        'name',
        'slug',
        'color',
    ];
}
