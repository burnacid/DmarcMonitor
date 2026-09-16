<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
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
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
            'lookback_window' => '24h',
            'channels' => ['email'],
            'notify_emails' => [$this->faker->safeEmail()],
            'is_active' => true,
        ];
    }
}
