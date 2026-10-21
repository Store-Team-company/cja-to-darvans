<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bdp_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('cja_woocommerce_api')->index();
            $table->string('method', 12)->nullable();
            $table->string('path')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('ip')->nullable();
            $table->boolean('authorized')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('response_body')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_api_logs');
    }
};
