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
        Schema::table('public_dashboard_settings', function (Blueprint $table) {
            $table->boolean('auto_scroll')->default(true)->after('is_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public_dashboard_settings', function (Blueprint $table) {
            $table->dropColumn('auto_scroll');
        });
    }
};
