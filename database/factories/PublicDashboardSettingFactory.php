<?php

namespace Database\Factories;

use App\Models\PublicDashboardSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicDashboardSetting>
 */
class PublicDashboardSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'is_enabled' => true,
            'auto_scroll' => true,
            'default_days' => 30,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
