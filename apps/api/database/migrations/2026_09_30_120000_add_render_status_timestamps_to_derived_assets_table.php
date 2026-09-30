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
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->string('render_status')->nullable()->after('render_error');
            $table->timestamp('render_started_at')->nullable()->after('render_status');
            $table->timestamp('render_completed_at')->nullable()->after('render_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropColumn([
                'render_status',
                'render_started_at',
                'render_completed_at',
            ]);
        });
    }
};