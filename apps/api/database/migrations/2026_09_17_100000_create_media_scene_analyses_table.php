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
        Schema::create('media_scene_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('detector', 32)->nullable();
            $table->string('detector_version', 16)->nullable();
            $table->json('parameters')->nullable();
            $table->json('scenes')->nullable();
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
        Schema::dropIfExists('media_scene_analyses');
    }
};
