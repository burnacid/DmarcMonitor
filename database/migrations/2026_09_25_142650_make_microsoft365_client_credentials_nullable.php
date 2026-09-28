<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts connected through "Connect with Microsoft" authenticate with the
 * installation-wide shared app registration, so they store no client ID or
 * secret of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['microsoft365_send_accounts', 'microsoft365_mail_accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('client_id')->nullable()->change();
                $table->text('client_secret')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['microsoft365_send_accounts', 'microsoft365_mail_accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('client_id')->nullable(false)->change();
                $table->text('client_secret')->nullable(false)->change();
            });
        }
    }
};
