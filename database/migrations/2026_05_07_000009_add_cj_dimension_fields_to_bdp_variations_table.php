<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_product_variations', function (Blueprint $table) {
            if (! Schema::hasColumn('bdp_product_variations', 'weight')) $table->decimal('weight', 12, 2)->nullable()->after('image');
            if (! Schema::hasColumn('bdp_product_variations', 'length')) $table->decimal('length', 12, 2)->nullable()->after('weight');
            if (! Schema::hasColumn('bdp_product_variations', 'width')) $table->decimal('width', 12, 2)->nullable()->after('length');
            if (! Schema::hasColumn('bdp_product_variations', 'height')) $table->decimal('height', 12, 2)->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('bdp_product_variations', function (Blueprint $table) {
            foreach (['weight','length','width','height'] as $column) {
                if (Schema::hasColumn('bdp_product_variations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
