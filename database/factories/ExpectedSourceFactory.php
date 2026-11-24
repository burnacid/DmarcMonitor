<?php

namespace Database\Factories;

use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Support\ExpectedSourceMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpectedSource>
 */
class ExpectedSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'domain_id' => null,
            'label' => $this->faker->company(),
            'match_type' => ExpectedSourceMatch::HeloDomain,
            'match_value' => $this->faker->domainName(),
            'note' => null,
            'null_sender_only' => false,
            'exclude_from_alerts' => true,
            'hide_from_dashboard' => false,
            'is_active' => true,
        ];
    }
}
