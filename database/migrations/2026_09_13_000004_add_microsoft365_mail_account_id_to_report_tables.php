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
        Schema::table('aggregate_reports', function (Blueprint $table) {
            $table->foreignId('microsoft365_mail_account_id')->nullable()->after('imap_account_id')->constrained()->nullOnDelete();
        });

        Schema::table('forensic_reports', function (Blueprint $table) {
            $table->foreignId('microsoft365_mail_account_id')->nullable()->after('imap_account_id')->constrained()->nullOnDelete();
            $table->unique(['microsoft365_mail_account_id', 'message_uid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aggregate_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('microsoft365_mail_account_id');
        });

        Schema::table('forensic_reports', function (Blueprint $table) {
            $table->dropUnique(['microsoft365_mail_account_id', 'message_uid']);
            $table->dropConstrainedForeignId('microsoft365_mail_account_id');
        });
    }
};
