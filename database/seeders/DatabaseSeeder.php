<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Stefan',
            'email' => 'stefan+claude@lenders-it.nl',
            'password' => bcrypt('password'),
        ]);

        $org = Organisation::create([
            'name' => 'Example Client',
            'notes' => 'Sample organisation for local development.',
        ]);

        Domain::create([
            'organisation_id' => $org->id,
            'fqdn' => 'example.com',
            'is_active' => true,
        ]);

        Domain::create([
            'organisation_id' => $org->id,
            'fqdn' => 'example.org',
            'is_active' => true,
        ]);
    }
}
