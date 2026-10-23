<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SaasAdminPlanGrant extends Model
{
    protected $table = 'saas_admin_plan_grants';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SaasWorkspace::class, 'workspace_id');
    }
}
