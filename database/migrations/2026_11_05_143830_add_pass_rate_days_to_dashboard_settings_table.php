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
        Schema::table('dashboard_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('pass_rate_days')->nullable()->after('default_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboard_settings', function (Blueprint $table) {
            $table->dropColumn('pass_rate_days');
        });
    }
};
