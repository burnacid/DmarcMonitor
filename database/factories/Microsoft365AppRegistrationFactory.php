<?php

namespace Database\Factories;

use App\Models\Microsoft365AppRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Microsoft365AppRegistration>
 */
class Microsoft365AppRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => $this->faker->uuid(),
            'client_secret' => $this->faker->password(),
        ];
    }
}
