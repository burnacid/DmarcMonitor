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
        Schema::table('imap_accounts', function (Blueprint $table) {
            $table->boolean('include_read_messages')->default(false)->after('mark_as_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imap_accounts', function (Blueprint $table) {
            $table->dropColumn('include_read_messages');
        });
    }
};
