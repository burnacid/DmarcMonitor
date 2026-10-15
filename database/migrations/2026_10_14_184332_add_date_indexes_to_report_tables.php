<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The dashboard, overview and cleanup filter reports on the period they
     * cover (date_range_begin), alerts and "last report" on when they were
     * received (created_at); without indexes these scan the whole table.
     */
    public function up(): void
    {
        Schema::table('aggregate_reports', function (Blueprint $table) {
            $table->index('date_range_begin');
            $table->index(['domain_id', 'date_range_begin']);
            $table->index('created_at');
        });

        Schema::table('forensic_reports', function (Blueprint $table) {
            $table->index('arrival_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aggregate_reports', function (Blueprint $table) {
            $table->dropIndex(['date_range_begin']);
            $table->dropIndex(['domain_id', 'date_range_begin']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('forensic_reports', function (Blueprint $table) {
            $table->dropIndex(['arrival_date']);
        });
    }
};
