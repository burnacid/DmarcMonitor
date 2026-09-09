<?php

namespace Database\Factories;

use App\Models\AggregateReport;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregateReport>
 */
class AggregateReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $begin = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'domain_id' => Domain::factory(),
            'report_id' => $this->faker->unique()->uuid(),
            'org_name' => $this->faker->company(),
            'email' => $this->faker->companyEmail(),
            'date_range_begin' => $begin,
            'date_range_end' => (clone $begin)->modify('+1 day'),
            'policy_domain' => $this->faker->domainName(),
            'policy_adkim' => 'r',
            'policy_aspf' => 'r',
            'policy_p' => 'none',
            'policy_pct' => 100,
            'processed_at' => now(),
        ];
    }
}
