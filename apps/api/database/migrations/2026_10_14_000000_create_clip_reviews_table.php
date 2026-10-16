<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('derived_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'needs_changes'])
                ->default('pending');
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->unique(['derived_asset_id', 'reviewer_id']);
            $table->index(['reviewer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_reviews');
    }
};