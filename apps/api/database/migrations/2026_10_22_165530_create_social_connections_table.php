<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // 'youtube', 'instagram', 'tiktok'
            $table->string('provider_user_id'); // Platform-specific user ID
            $table->string('display_name'); // User-facing name
            $table->string('avatar_url')->nullable();
            $table->string('channel_url')->nullable();

            // Encrypted token storage (Laravel encrypted cast)
            $table->text('access_token')->nullable(); // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->timestamp('access_token_expires_at')->nullable();

            // Connection status
            $table->string('status')->default('connected'); // connected, expired, revoked, error
            $table->text('last_error')->nullable();

            // OAuth state management (single-use, short TTL)
            $table->string('pending_oauth_state')->nullable();
            $table->timestamp('pending_oauth_state_expires_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'platform', 'provider_user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_connections');
    }
};
