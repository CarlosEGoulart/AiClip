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
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('processing_status', 32)->default('stored')->after('status');
            $table->string('idempotency_key', 36)->nullable()->after('processing_status');
            $table->timestamp('processing_started_at')->nullable()->after('idempotency_key');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
            $table->text('processing_error')->nullable()->after('processing_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'idempotency_key',
                'processing_started_at',
                'processing_completed_at',
                'processing_error',
            ]);
        });
    }
};
