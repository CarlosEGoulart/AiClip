<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The sessions table is created by
     * 0001_01_01_000000_create_users_table.php.
     * This migration is intentionally a no-op to avoid a
     * "Duplicate table: sessions already exists" error.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
