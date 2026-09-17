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
        Schema::create('media_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('derived_asset_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('language', 8)->nullable();
            $table->text('full_text')->nullable();
            $table->json('segments')->nullable();
            $table->string('engine', 32)->nullable();
            $table->string('model', 32)->nullable();
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
        Schema::dropIfExists('media_transcripts');
    }
};
