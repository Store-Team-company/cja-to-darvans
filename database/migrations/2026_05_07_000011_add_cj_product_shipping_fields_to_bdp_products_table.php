<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (! Schema::hasColumn('bdp_products', 'cj_shipping_country')) $table->string('cj_shipping_country', 10)->nullable()->after('cost_per_item');
            if (! Schema::hasColumn('bdp_products', 'cj_shipping_fee')) $table->decimal('cj_shipping_fee', 12, 2)->nullable()->after('cj_shipping_country');
            if (! Schema::hasColumn('bdp_products', 'cj_shipping_method')) $table->string('cj_shipping_method')->nullable()->after('cj_shipping_fee');
            if (! Schema::hasColumn('bdp_products', 'cj_shipping_details')) $table->longText('cj_shipping_details')->nullable()->after('cj_shipping_method');
            if (! Schema::hasColumn('bdp_products', 'landed_cost')) $table->decimal('landed_cost', 12, 2)->nullable()->after('cj_shipping_details');
        });
    }

    public function down(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            foreach (['cj_shipping_country','cj_shipping_fee','cj_shipping_method','cj_shipping_details','landed_cost'] as $column) {
                if (Schema::hasColumn('bdp_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
