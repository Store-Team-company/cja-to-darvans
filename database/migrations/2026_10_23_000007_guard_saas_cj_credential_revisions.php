<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_revision')->default(0);
            $table->uuid('verification_attempt_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_cj_connections', function (Blueprint $table): void {
            $table->dropColumn(['credential_revision', 'verification_attempt_id']);
        });
    }
};
