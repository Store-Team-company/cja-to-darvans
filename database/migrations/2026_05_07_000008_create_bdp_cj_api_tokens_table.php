<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('bdp_cj_api_tokens')) {
            Schema::create('bdp_cj_api_tokens', function (Blueprint $table) {
                $table->id();
                $table->text('api_key')->nullable();
                $table->text('access_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('refresh_token_expires_at')->nullable();
                $table->string('open_id')->nullable();
                $table->longText('last_response')->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_cj_api_tokens');
    }
};
