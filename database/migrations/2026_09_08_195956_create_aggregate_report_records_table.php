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
        Schema::create('aggregate_report_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aggregate_report_id')->constrained()->cascadeOnDelete();
            $table->string('source_ip');
            $table->unsignedInteger('count');
            $table->enum('disposition', ['none', 'quarantine', 'reject', 'pass']);
            $table->enum('dkim_result', ['pass', 'fail']);
            $table->enum('spf_result', ['pass', 'fail']);
            $table->string('header_from')->nullable();
            $table->string('envelope_from')->nullable();
            $table->string('envelope_to')->nullable();
            $table->string('dkim_domain')->nullable();
            $table->string('dkim_selector')->nullable();
            $table->string('dkim_auth_result')->nullable();
            $table->string('spf_domain')->nullable();
            $table->string('spf_scope')->nullable();
            $table->string('spf_auth_result')->nullable();
            $table->string('ptr_hostname')->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('asn_org')->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamps();

            $table->index(['aggregate_report_id', 'source_ip']);
            $table->index('source_ip');
            $table->index('asn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregate_report_records');
    }
};
