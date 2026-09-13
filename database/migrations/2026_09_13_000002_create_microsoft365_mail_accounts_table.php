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
        Schema::create('microsoft365_mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('tenant_id');
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('mailbox');
            $table->string('folder_inbox')->default('Inbox');
            $table->string('folder_processed')->nullable();
            $table->string('folder_failed')->nullable();
            $table->boolean('mark_as_read')->default(true);
            $table->boolean('include_read_messages')->default(false);
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
        Schema::dropIfExists('microsoft365_mail_accounts');
    }
};
