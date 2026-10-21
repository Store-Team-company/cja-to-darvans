<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_product_variations', function (Blueprint $table) {
            if (! Schema::hasColumn('bdp_product_variations', 'cj_variant_id')) $table->string('cj_variant_id')->nullable()->after('source_id');
            if (! Schema::hasColumn('bdp_product_variations', 'cj_inventory_total')) $table->integer('cj_inventory_total')->nullable()->after('quantity');
            if (! Schema::hasColumn('bdp_product_variations', 'cj_inventory_details')) $table->longText('cj_inventory_details')->nullable()->after('cj_inventory_total');
            if (! Schema::hasColumn('bdp_product_variations', 'cj_shipping_country')) $table->string('cj_shipping_country', 10)->nullable()->after('height');
            if (! Schema::hasColumn('bdp_product_variations', 'cj_shipping_fee')) $table->decimal('cj_shipping_fee', 12, 2)->nullable()->after('cj_shipping_country');
            if (! Schema::hasColumn('bdp_product_variations', 'cj_shipping_details')) $table->longText('cj_shipping_details')->nullable()->after('cj_shipping_fee');
            if (! Schema::hasColumn('bdp_product_variations', 'landed_cost')) $table->decimal('landed_cost', 12, 2)->nullable()->after('cj_shipping_details');
        });
    }

    public function down(): void
    {
        Schema::table('bdp_product_variations', function (Blueprint $table) {
            foreach (['cj_variant_id','cj_inventory_total','cj_inventory_details','cj_shipping_country','cj_shipping_fee','cj_shipping_details','landed_cost'] as $column) {
                if (Schema::hasColumn('bdp_product_variations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
