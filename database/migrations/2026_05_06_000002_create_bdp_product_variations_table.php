<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bdp_product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bdp_product_id')->constrained('bdp_products')->cascadeOnDelete();
            $table->string('source_id')->nullable()->index();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('color')->nullable();
            $table->string('size_raw')->nullable();
            $table->string('size_us')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('cost_per_item', 12, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('stock_status')->default('in_stock');
            $table->string('image')->nullable();
            $table->longText('gallery_images')->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_product_variations');
    }
};
