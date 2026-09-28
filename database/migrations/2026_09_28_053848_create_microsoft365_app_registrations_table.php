<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing installations that configured the shared app through
     * MICROSOFT365_CLIENT_ID / MICROSOFT365_CLIENT_SECRET get those values
     * imported, after which the app is managed from the web interface.
     */
    public function up(): void
    {
        Schema::create('microsoft365_app_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret');
            $table->timestamps();
        });

        $clientId = config('services.microsoft365.client_id');
        $clientSecret = config('services.microsoft365.client_secret');

        if (filled($clientId) && filled($clientSecret)) {
            DB::table('microsoft365_app_registrations')->insert([
                'client_id' => $clientId,
                'client_secret' => Crypt::encryptString($clientSecret),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft365_app_registrations');
    }
};
