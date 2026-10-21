<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (!Schema::hasColumn('bdp_products', 'seo_optimized_at')) {
                $table->timestamp('seo_optimized_at')->nullable()->after('focus_keyword');
            }

            if (!Schema::hasColumn('bdp_products', 'seo_optimized_batch')) {
                $table->string('seo_optimized_batch')->nullable()->after('seo_optimized_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (Schema::hasColumn('bdp_products', 'seo_optimized_batch')) {
                $table->dropColumn('seo_optimized_batch');
            }

            if (Schema::hasColumn('bdp_products', 'seo_optimized_at')) {
                $table->dropColumn('seo_optimized_at');
            }
        });
    }
};