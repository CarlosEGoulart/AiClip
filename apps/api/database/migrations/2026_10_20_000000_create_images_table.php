<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->json('parameters')->nullable();
            $table->string('storage_disk');
            $table->string('storage_key');
            $table->string('mime_type')->default('image/png');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique('storage_key');
            $table->index(['user_id', 'status']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
