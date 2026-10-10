<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropUnique('derived_assets_render_unique');
            $table->unique(['media_asset_id', 'type', 'candidate_index', 'render_profile_version', 'transcript_hash'], 'derived_assets_render_unique');
        });
    }

    public function down(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropUnique('derived_assets_render_unique');
            $table->unique(['media_asset_id', 'type', 'candidate_index', 'render_profile_version'], 'derived_assets_render_unique');
        });
    }
};
