<?php

namespace Database\Factories;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregateReportRecord>
 */
class AggregateReportRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aggregate_report_id' => AggregateReport::factory(),
            'source_ip' => $this->faker->unique()->ipv4(),
            'count' => $this->faker->numberBetween(1, 50),
            'disposition' => 'none',
            'dkim_result' => 'pass',
            'spf_result' => 'pass',
            'header_from' => $this->faker->domainName(),
            'envelope_from' => $this->faker->domainName(),
            'envelope_to' => $this->faker->domainName(),
        ];
    }

    /**
     * A record the enrichment job has processed, optionally with the
     * organisation and hostname it resolved to.
     */
    public function enriched(?string $asnOrg = null, ?string $ptrHostname = null): static
    {
        return $this->state(fn () => [
            'asn_org' => $asnOrg,
            'ptr_hostname' => $ptrHostname,
            'enriched_at' => now(),
        ]);
    }
}
