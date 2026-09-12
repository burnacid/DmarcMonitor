<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\ForensicReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForensicReport>
 */
class ForensicReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = $this->faker->domainName();

        return [
            'domain_id' => Domain::factory(),
            'arrival_date' => $this->faker->dateTimeBetween('-1 month'),
            'source_ip' => $this->faker->unique()->ipv4(),
            'original_envelope_id' => $this->faker->uuid(),
            'authentication_results' => "mx.{$domain}; dkim=fail; spf=fail",
            'delivery_result' => 'reject',
            'header_from' => $domain,
            'envelope_from' => "bounce.{$domain}",
            'envelope_to' => $this->faker->safeEmail(),
            'dkim_domain' => $domain,
            'dkim_result' => 'fail',
            'spf_domain' => $domain,
            'spf_result' => 'fail',
            'subject' => 'FW: complaint about message from '.$domain,
            'message_uid' => (string) $this->faker->unique()->numberBetween(1, 1000000),
            'processed_at' => now(),
        ];
    }
}
