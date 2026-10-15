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
        foreach (['imap_accounts', 'microsoft365_mail_accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error');
                $table->timestamp('failure_notified_at')->nullable()->after('consecutive_failures');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['imap_accounts', 'microsoft365_mail_accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['consecutive_failures', 'failure_notified_at']);
            });
        }
    }
};
