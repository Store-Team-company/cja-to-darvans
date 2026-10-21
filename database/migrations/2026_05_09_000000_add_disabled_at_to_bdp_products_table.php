<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bdp_products') && ! Schema::hasColumn('bdp_products', 'disabled_at')) {
            Schema::table('bdp_products', function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bdp_products') && Schema::hasColumn('bdp_products', 'disabled_at')) {
            Schema::table('bdp_products', function (Blueprint $table) {
                $table->dropColumn('disabled_at');
            });
        }
    }
};
