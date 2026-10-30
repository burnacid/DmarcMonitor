<?php

namespace Database\Factories;

use App\Models\DashboardSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardSetting>
 */
class DashboardSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day_ranges' => DashboardSetting::DEFAULT_DAY_RANGES,
            'default_days' => DashboardSetting::DEFAULT_DAYS,
        ];
    }
}
