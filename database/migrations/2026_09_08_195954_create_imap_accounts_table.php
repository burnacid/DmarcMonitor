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
        Schema::create('imap_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(993);
            $table->enum('encryption', ['ssl', 'tls', 'none'])->default('ssl');
            $table->string('username');
            $table->text('password');
            $table->string('protocol')->default('imap');
            $table->string('folder_inbox')->default('INBOX');
            $table->string('folder_processed')->nullable();
            $table->string('folder_failed')->nullable();
            $table->boolean('mark_as_read')->default(true);
            $table->boolean('delete_after_processing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imap_accounts');
    }
};
