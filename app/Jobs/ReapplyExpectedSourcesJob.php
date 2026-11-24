<?php

namespace App\Jobs;

use App\Services\Analytics\ExpectedSourceMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rematches existing report records after an expected source was added,
 * changed or removed.
 */
class ReapplyExpectedSourcesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>|null  $domainIds  null rematches every domain
     */
    public function __construct(
        public readonly ?array $domainIds = null,
    ) {}

    public function handle(ExpectedSourceMatcher $matcher): void
    {
        $matcher->reapply($this->domainIds);
    }
}
