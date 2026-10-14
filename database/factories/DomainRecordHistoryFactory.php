<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\DomainRecordHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainRecordHistory>
 */
class DomainRecordHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'type' => DomainRecordHistory::TYPE_DMARC,
            'record' => 'v=DMARC1; p=none',
        ];
    }

    public function spf(): static
    {
        return $this->state([
            'type' => DomainRecordHistory::TYPE_SPF,
            'record' => 'v=spf1 -all',
        ]);
    }
}
