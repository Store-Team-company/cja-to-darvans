<?php

namespace Botble\DatapilotConnector\Models;

use Illuminate\Database\Eloquent\Model;

class VariantMapping extends Model
{
    protected $table = 'datapilot_connector_variant_mappings';

    protected $guarded = ['id'];
}
