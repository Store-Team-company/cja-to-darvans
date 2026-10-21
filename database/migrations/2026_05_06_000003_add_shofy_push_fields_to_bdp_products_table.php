<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (!Schema::hasColumn('bdp_products', 'shofy_product_id')) {
                $table->unsignedBigInteger('shofy_product_id')->nullable()->index()->after('last_exported_at');
            }
            if (!Schema::hasColumn('bdp_products', 'shofy_pushed_at')) {
                $table->timestamp('shofy_pushed_at')->nullable()->after('shofy_product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (Schema::hasColumn('bdp_products', 'shofy_pushed_at')) {
                $table->dropColumn('shofy_pushed_at');
            }
            if (Schema::hasColumn('bdp_products', 'shofy_product_id')) {
                $table->dropColumn('shofy_product_id');
            }
        });
    }
};
