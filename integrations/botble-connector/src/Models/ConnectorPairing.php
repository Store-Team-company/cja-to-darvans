<?php

namespace Botble\DatapilotConnector\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorPairing extends Model
{
    protected $table = 'datapilot_connector_pairings';
    protected $guarded = ['id'];
    protected $casts = ['shared_secret' => 'encrypted', 'paired_at' => 'datetime'];
    protected $hidden = ['shared_secret'];
}
