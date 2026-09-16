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
        Schema::create('microsoft365_mail_account_domain', function (Blueprint $table) {
            $table->id();
            $table->foreignId('microsoft365_mail_account_id')
                ->constrained(indexName: 'm365_mail_account_domain_account_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['microsoft365_mail_account_id', 'domain_id'], 'm365_mail_account_domain_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft365_mail_account_domain');
    }
};
