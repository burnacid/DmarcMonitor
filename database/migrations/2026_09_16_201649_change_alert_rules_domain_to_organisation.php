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
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->after('domain_id')->constrained()->cascadeOnDelete();
        });

        // Existing domain-scoped rules become scoped to that domain's organisation
        // (or stay global, if the domain had none). Updated row-by-row since
        // an UPDATE ... JOIN isn't portable across the DB drivers this app supports.
        DB::table('alert_rules')
            ->join('domains', 'domains.id', '=', 'alert_rules.domain_id')
            ->whereNotNull('alert_rules.domain_id')
            ->select('alert_rules.id', 'domains.organisation_id')
            ->get()
            ->each(fn ($row) => DB::table('alert_rules')
                ->where('id', $row->id)
                ->update(['organisation_id' => $row->organisation_id]));

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->dropColumn('organisation_id');
        });
    }
};
