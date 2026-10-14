<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Until now a user without organisations could see every organisation.
     * That access becomes explicit, so existing users without organisations
     * keep it, while a user who loses their last organisation no longer
     * silently gains access to everything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_organisations')->default(false)->after('role');
        });

        DB::table('users')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('organisation_user')
                ->whereColumn('organisation_user.user_id', 'users.id'))
            ->update(['all_organisations' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_organisations');
        });
    }
};
