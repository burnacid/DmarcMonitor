<?php

namespace Database\Factories;

use App\Models\Microsoft365MailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Microsoft365MailAccount>
 */
class Microsoft365MailAccountFactory extends Factory
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
            'tenant_id' => $this->faker->uuid(),
            'client_id' => $this->faker->uuid(),
            'client_secret' => $this->faker->password(),
            'mailbox' => $this->faker->companyEmail(),
            'folder_inbox' => 'Inbox',
            'is_active' => true,
        ];
    }

    /**
     * An account connected through "Connect with Microsoft", which
     * authenticates with the shared app instead of its own credentials.
     */
    public function sharedApp(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
            'client_secret' => null,
        ]);
    }
}
