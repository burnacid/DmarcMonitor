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
        Schema::create('forensic_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imap_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('arrival_date')->nullable();
            $table->string('source_ip')->nullable();
            $table->string('original_envelope_id')->nullable();
            $table->text('authentication_results')->nullable();
            $table->enum('delivery_result', ['delivered', 'spam', 'policy', 'reject', 'other'])->nullable();
            $table->string('header_from')->nullable();
            $table->string('envelope_from')->nullable();
            $table->string('envelope_to')->nullable();
            $table->string('dkim_domain')->nullable();
            $table->string('dkim_result')->nullable();
            $table->string('spf_domain')->nullable();
            $table->string('spf_result')->nullable();
            $table->string('subject')->nullable();
            $table->string('raw_message_path')->nullable();
            $table->string('message_uid')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('source_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forensic_reports');
    }
};
