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
        Schema::table('aggregate_report_records', function (Blueprint $table) {
            $table->boolean('is_null_sender')->default(false)->after('spf_auth_result')->index();
            $table->foreignId('expected_source_id')->nullable()->after('is_null_sender')->constrained()->nullOnDelete();
        });

        DB::table('aggregate_report_records')
            ->where(fn ($query) => $query->where('envelope_from', '=', '<>')->orWhere('spf_scope', 'helo'))
            ->update(['is_null_sender' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aggregate_report_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expected_source_id');
            $table->dropIndex(['is_null_sender']);
            $table->dropColumn('is_null_sender');
        });
    }
};
