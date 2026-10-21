<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bdp_products', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('woocommerce');
            $table->string('source_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('sku')->nullable()->index();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->longText('content')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('cost_per_item', 12, 2)->nullable();
            $table->string('stock_status')->default('in_stock');
            $table->integer('quantity')->nullable();
            $table->string('categories')->nullable();
            $table->string('tags')->nullable();
            $table->string('brand')->nullable();
            $table->string('featured_image')->nullable();
            $table->longText('gallery_images')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('last_exported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_products');
    }
};
