<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_admin_plan_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('plan_code', 24);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason', 255);
            $table->string('granted_by', 80);
            $table->timestamps();
        });
        Schema::create('saas_admin_plan_grant_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('action', 24);
            $table->string('plan_code', 24)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 255);
            $table->string('actor', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_admin_plan_grant_events');
        Schema::dropIfExists('saas_admin_plan_grants');
    }
};
