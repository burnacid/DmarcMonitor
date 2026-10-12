<?php

namespace Database\Factories;

use App\Models\DashboardAllowedIp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardAllowedIp>
 */
class DashboardAllowedIpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'address' => $this->faker->unique()->ipv4(),
            'description' => $this->faker->optional()->words(2, true),
        ];
    }
}
