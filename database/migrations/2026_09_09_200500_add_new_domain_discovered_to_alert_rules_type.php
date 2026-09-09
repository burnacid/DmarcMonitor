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
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->enum('type', ['pass_rate_drop', 'spf_fail_spike', 'dkim_fail_spike', 'new_source_detected', 'new_domain_discovered'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->enum('type', ['pass_rate_drop', 'spf_fail_spike', 'dkim_fail_spike', 'new_source_detected'])->change();
        });
    }
};
