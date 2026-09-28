<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unshipped issue-local M5 migration: it is adjusted in place, never
     * rewritten after a non-disposable environment has applied it.
     */
    public function up(): void
    {
        Schema::create('media_clip_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('m4_analysis_id')
                ->nullable()
                ->constrained('media_clip_analyses')
                ->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('outcome', 32)->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('algorithm', 64)->nullable();
            $table->string('algorithm_version', 16)->nullable();
            $table->json('parameters')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->json('execution_parameters')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique('media_asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_clip_recommendations');
    }
};
