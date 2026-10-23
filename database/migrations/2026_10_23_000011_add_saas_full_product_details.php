<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_products', function (Blueprint $table) {
            $table->string('slug', 255)->nullable();
            $table->text('tags')->nullable();
            $table->longText('short_description_html')->nullable();
            $table->longText('long_description_html')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->string('focus_keyword', 255)->nullable();
            $table->decimal('sale_price_usd', 12, 2)->nullable();
            $table->string('featured_image_url', 500)->nullable();
            $table->json('gallery_images')->nullable();
            $table->json('source_variants')->nullable();
            $table->string('source_weight_g', 40)->nullable();
            $table->timestamp('cj_details_fetched_at')->nullable();
        });
        Schema::table('saas_cj_connections', function (Blueprint $table) {
            $table->timestamp('last_details_fetch_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table) {
            $table->dropColumn('last_details_fetch_at');
        });
        Schema::table('saas_products', function (Blueprint $table) {
            $table->dropColumn([
                'slug', 'tags', 'short_description_html', 'long_description_html',
                'seo_title', 'seo_description', 'focus_keyword', 'sale_price_usd',
                'featured_image_url', 'gallery_images', 'source_variants',
                'source_weight_g', 'cj_details_fetched_at',
            ]);
        });
    }
};
