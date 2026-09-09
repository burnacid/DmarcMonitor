<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'editor', 'viewer'])->default('viewer')->after('password');
        });

        // Every account that already exists at the time this ships was created
        // before roles existed, so treat them all as admins rather than locking
        // anyone out of the admin pages they already had access to. Accounts
        // registered afterward pick up the "viewer" column default instead.
        DB::table('users')->update(['role' => 'admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
