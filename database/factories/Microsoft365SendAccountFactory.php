<?php

namespace Database\Factories;

use App\Models\Microsoft365SendAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Microsoft365SendAccount>
 */
class Microsoft365SendAccountFactory extends Factory
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
            'is_active' => true,
        ];
    }
}
