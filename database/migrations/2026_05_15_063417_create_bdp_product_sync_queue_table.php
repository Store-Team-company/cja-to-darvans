<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bdp_product_sync_queue')) {
            Schema::create('bdp_product_sync_queue', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique();
                $table->string('status', 30)->default('queued')->index(); // queued, processing, synced, failed
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('queued_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'queued_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_product_sync_queue');
    }
};