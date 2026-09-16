<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('derived_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('storage_disk', 64);
            $table->string('storage_key', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->string('codec', 64)->nullable();
            $table->timestamps();

            $table->unique(['media_asset_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('derived_assets');
    }
};
