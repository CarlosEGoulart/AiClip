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
        Schema::table('social_publishes', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->constrained('social_publish_campaigns')->nullOnDelete();
            $table->index(['campaign_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_publishes', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
    }
};
