<?php

namespace Botble\DatapilotConnector\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMapping extends Model
{
    protected $table = 'datapilot_connector_product_mappings';

    protected $guarded = ['id'];
}
