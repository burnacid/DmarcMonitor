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
            $table->string('enforced_policy', 16)->default('reject')->after('default_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public_dashboard_settings', function (Blueprint $table) {
            $table->dropColumn('enforced_policy');
        });
    }
};
