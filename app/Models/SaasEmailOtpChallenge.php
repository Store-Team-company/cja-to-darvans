<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasEmailOtpChallenge extends Model
{
    protected $table = 'saas_email_otp_challenges';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
