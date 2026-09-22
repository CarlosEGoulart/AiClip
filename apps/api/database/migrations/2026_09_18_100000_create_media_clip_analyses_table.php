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
        Schema::create('media_clip_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('algorithm', 64)->nullable();
            $table->string('algorithm_version', 16)->nullable();
            $table->json('parameters')->nullable();
            $table->json('candidates')->nullable();
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
        Schema::dropIfExists('media_clip_analyses');
    }
};
