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
        Schema::create('aggregate_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imap_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_id');
            $table->string('org_name');
            $table->string('email')->nullable();
            $table->timestamp('date_range_begin');
            $table->timestamp('date_range_end');
            $table->string('policy_domain');
            $table->enum('policy_adkim', ['r', 's'])->default('r');
            $table->enum('policy_aspf', ['r', 's'])->default('r');
            $table->enum('policy_p', ['none', 'quarantine', 'reject'])->default('none');
            $table->enum('policy_sp', ['none', 'quarantine', 'reject'])->nullable();
            $table->unsignedTinyInteger('policy_pct')->default(100);
            $table->string('raw_xml_path')->nullable();
            $table->string('message_uid')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'report_id', 'org_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregate_reports');
    }
};
