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
            $table->integer('candidate_index')->nullable()->after('codec');
            $table->string('render_profile_version')->nullable()->after('candidate_index');
            $table->json('render_configuration')->nullable()->after('render_profile_version');
            $table->json('render_parameters')->nullable()->after('render_configuration');
            $table->string('render_error')->nullable()->after('render_parameters');
            $table->unsignedInteger('width')->nullable()->after('duration_ms');
            $table->unsignedInteger('height')->nullable()->after('width');

            $table->unique(['media_asset_id', 'type', 'candidate_index', 'render_profile_version'], 'derived_assets_render_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropUnique('derived_assets_render_unique');
            $table->dropColumn([
                'candidate_index',
                'render_profile_version',
                'render_configuration',
                'render_parameters',
                'render_error',
                'width',
                'height',
            ]);
        });
    }
};
