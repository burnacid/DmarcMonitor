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
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['pass_rate_drop', 'new_source_detected', 'spf_fail_spike', 'dkim_fail_spike']);
            $table->decimal('threshold_percent', 5, 2)->nullable();
            $table->string('lookback_window')->default('24h');
            $table->json('channels');
            $table->string('webhook_url')->nullable();
            $table->json('notify_emails')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
