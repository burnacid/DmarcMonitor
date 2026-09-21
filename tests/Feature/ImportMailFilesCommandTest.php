<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportEmlReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_the_configured_inbox_when_no_paths_are_given(): void
    {
        Queue::fake();

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eml-cmd-test-'.Str::random(8);
        config(['dmarc.eml_import_path' => $base]);

        copy(
            base_path('tests/Fixtures/dmarc/eml/aggregate-report.eml'),
            $this->makeInbox($base).'/aggregate-report.eml',
        );

        $this->artisan('dmarc:import-eml')
            ->expectsOutputToContain('fetched=1 parsed=1 failed=0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('aggregate_reports', 1);
    }

    public function test_it_reports_an_error_for_a_path_that_does_not_exist(): void
    {
        $this->artisan('dmarc:import-eml', ['paths' => ['/no/such/path.eml']])
            ->expectsOutputToContain('Path not found: /no/such/path.eml')
            ->assertExitCode(0);
    }

    private function makeInbox(string $base): string
    {
        $inbox = $base.DIRECTORY_SEPARATOR.'inbox';
        mkdir($inbox, 0755, true);

        return $inbox;
    }
}
