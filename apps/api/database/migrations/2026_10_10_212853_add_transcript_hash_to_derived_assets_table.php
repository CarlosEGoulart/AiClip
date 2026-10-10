<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->string('transcript_hash', 64)->nullable()->after('render_parameters');
            $table->index(['media_asset_id', 'type', 'candidate_index', 'render_profile_version', 'transcript_hash'], 'idx_render_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropIndex('idx_render_idempotency');
            $table->dropColumn('transcript_hash');
        });
    }
};
