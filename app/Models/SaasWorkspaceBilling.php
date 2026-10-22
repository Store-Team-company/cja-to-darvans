<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasWorkspaceBilling extends Model
{
    protected $table = 'saas_workspace_billing';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'last_event_created' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SaasWorkspace::class, 'workspace_id');
    }
}
