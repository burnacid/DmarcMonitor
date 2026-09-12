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
        Schema::table('domains', function (Blueprint $table) {
            $table->string('dmarc_status')->nullable();
            $table->text('dmarc_record')->nullable();
            $table->string('spf_status')->nullable();
            $table->text('spf_record')->nullable();
            $table->string('dkim_status')->nullable();
            $table->string('dkim_selector')->nullable();
            $table->text('dkim_record')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'dmarc_status', 'dmarc_record', 'spf_status', 'spf_record',
                'dkim_status', 'dkim_selector', 'dkim_record', 'dns_checked_at',
            ]);
        });
    }
};
