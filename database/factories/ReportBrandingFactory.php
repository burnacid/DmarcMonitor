<?php

namespace Database\Factories;

use App\Models\ReportBranding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportBranding>
 */
class ReportBrandingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accent_color' => $this->faker->hexColor(),
            'logo_path' => null,
        ];
    }
}
