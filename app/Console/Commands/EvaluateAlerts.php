<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertEvaluationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:evaluate-alerts')]
#[Description('Evaluate active alert rules against recent DMARC data and fire alert events')]
class EvaluateAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertEvaluationService $service): int
    {
        $service->evaluateAll();

        $this->info('Alert rules evaluated.');

        return self::SUCCESS;
    }
}
