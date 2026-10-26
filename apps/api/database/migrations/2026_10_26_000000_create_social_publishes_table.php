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
        Schema::create('social_publishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->foreignId('clip_review_id')->constrained('clip_reviews')->cascadeOnDelete();
            $table->string('platform'); // 'youtube'
            $table->string('status'); // pending, uploading, published, failed
            $table->string('external_video_id')->nullable(); // YouTube video ID
            $table->string('video_url')->nullable(); // Public YouTube watch URL
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['social_connection_id']);
            $table->index(['clip_review_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_publishes');
    }
};
