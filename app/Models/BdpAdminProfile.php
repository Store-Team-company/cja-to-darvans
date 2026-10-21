<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpAdminProfile extends Model
{
    protected $table = 'bdp_admin_profiles';

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
    ];
}
