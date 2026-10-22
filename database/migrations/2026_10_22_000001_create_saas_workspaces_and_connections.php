<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate schema: the legacy Balenco/CJ tables are not altered.
        Schema::create('saas_workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 150);
            $table->string('status', 24)->default('pending'); // pending, active, suspended
            $table->timestamps();
        });

        Schema::create('saas_workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20); // owner, manager, importer, viewer
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('saas_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('cj_product_id', 160)->nullable();
            $table->string('title', 250);
            $table->string('sku', 180)->nullable();
            $table->string('status', 24)->default('draft');
            $table->json('source_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'cj_product_id']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('saas_cj_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete()->unique();
            $table->longText('api_key')->nullable(); // Encrypted Eloquent cast, never plain.
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->string('status', 24)->default('disconnected');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saas_botble_stores', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('base_url', 255);
            $table->longText('shared_secret')->nullable(); // Encrypted Eloquent cast.
            $table->string('status', 24)->default('disconnected');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'base_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_botble_stores');
        Schema::dropIfExists('saas_cj_connections');
        Schema::dropIfExists('saas_products');
        Schema::dropIfExists('saas_workspace_memberships');
        Schema::dropIfExists('saas_workspaces');
    }
};
