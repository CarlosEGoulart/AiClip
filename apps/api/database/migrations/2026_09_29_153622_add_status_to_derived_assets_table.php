<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->string('render_status', 32)->nullable()->after('render_profile_version');
            $table->timestamp('render_started_at')->nullable()->after('render_error');
            $table->timestamp('render_completed_at')->nullable()->after('render_started_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE derived_assets ADD CONSTRAINT derived_assets_render_status_check "
                ."CHECK (render_status IS NULL OR render_status IN ('pending', 'rendering', 'completed', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE derived_assets DROP CONSTRAINT IF EXISTS derived_assets_render_status_check'
            );
        }

        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropColumn([
                'render_status',
                'render_started_at',
                'render_completed_at',
            ]);
        });
    }
};
