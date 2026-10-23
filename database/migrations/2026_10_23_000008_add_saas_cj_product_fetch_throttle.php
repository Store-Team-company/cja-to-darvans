<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->timestamp('last_product_fetch_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->dropColumn('last_product_fetch_at');
        });
    }
};
