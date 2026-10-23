<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamp('last_auth_attempt_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'access_token_expires_at', 'refresh_token_expires_at',
                'last_auth_attempt_at', 'last_error_code',
            ]);
        });
    }
};
