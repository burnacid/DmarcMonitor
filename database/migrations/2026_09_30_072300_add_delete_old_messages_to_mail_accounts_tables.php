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
                $table->boolean('delete_old_messages')->default(false)->after('delete_after_processing');
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
                $table->dropColumn('delete_old_messages');
            });
        }
    }
};
