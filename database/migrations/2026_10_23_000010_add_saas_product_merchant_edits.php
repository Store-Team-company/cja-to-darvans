<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive, workspace-owned merchant fields. Supplier snapshots stay
        // separate and never become an implicit Botble publishing action.
        Schema::table('saas_products', function (Blueprint $table) {
            $table->decimal('selling_price_usd', 12, 2)->nullable();
            $table->string('category_name', 200)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('merchant_edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_products', function (Blueprint $table) {
            $table->dropColumn([
                'selling_price_usd', 'category_name',
                'description', 'merchant_edited_at',
            ]);
        });
    }
};
