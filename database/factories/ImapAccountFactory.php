<?php

namespace Database\Factories;

use App\Models\ImapAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImapAccount>
 */
class ImapAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => $this->faker->unique()->words(2, true),
            'host' => 'imap.'.$this->faker->domainName(),
            'port' => 993,
            'encryption' => 'ssl',
            'username' => $this->faker->userName(),
            'password' => $this->faker->password(),
            'protocol' => 'imap',
            'folder_inbox' => 'INBOX',
            'is_active' => true,
        ];
    }
}
