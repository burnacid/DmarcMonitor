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
        Schema::create('ip_enrichment_cache', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            $table->string('ptr_hostname')->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('asn_org')->nullable();
            $table->string('country')->nullable();
            $table->timestamp('looked_up_at')->nullable();
            $table->boolean('lookup_failed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_enrichment_caches');
    }
};
