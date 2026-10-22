<?php

namespace Botble\DatapilotConnector\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingDraft extends Model
{
    protected $table = 'datapilot_connector_drafts';
    protected $guarded = ['id', 'pairing_id'];
    protected $casts = ['payload' => 'encrypted:array'];
    protected $hidden = ['payload'];
}
