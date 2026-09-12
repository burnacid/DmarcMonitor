<?php

namespace Database\Factories;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertEvent>
 */
class AlertEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rule = AlertRule::factory();

        return [
            'alert_rule_id' => $rule,
            'domain_id' => fn (array $attributes) => AlertRule::find($attributes['alert_rule_id'])?->domain_id ?? $rule->create()->domain_id,
            'fired_at' => now(),
            'details' => ['pass_rate' => 62.5, 'threshold' => 95.0],
            'dedup_key' => $this->faker->unique()->uuid(),
            'resolved_at' => null,
            'notified_channels' => ['in_app'],
        ];
    }

    /**
     * A resolved (no longer open) alert event.
     */
    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
