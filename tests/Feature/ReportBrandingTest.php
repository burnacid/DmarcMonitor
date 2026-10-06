<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organisation;
use App\Models\ReportBranding;
use App\Models\User;
use App\Services\Analytics\OrganisationManagementReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_admins_can_reach_the_page_and_editors_cannot(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.report-branding'))->assertOk();
        $this->actingAs(User::factory()->editor()->create())->get(route('admin.report-branding'))->assertForbidden();
    }

    public function test_admins_can_set_the_accent_colour(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.report-branding')
            ->set('accent_color', '#0F766E')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('#0f766e', ReportBranding::current()->accent_color);
        $this->assertSame('#0f766e', ReportBranding::forReports()['accent']);
        $this->assertTrue(AuditLog::where('action', 'report_branding.created')->exists());
    }

    public function test_an_invalid_accent_colour_is_rejected(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.report-branding')
            ->set('accent_color', 'red; background: url(x)')
            ->call('save')
            ->assertHasErrors('accent_color');

        $this->assertNull(ReportBranding::current());
    }

    public function test_only_images_are_accepted_as_logo(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.report-branding')
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))
            ->call('save')
            ->assertHasErrors('logo');
    }

    public function test_uploading_a_logo_replaces_the_previous_one(): void
    {
        $component = Volt::actingAs(User::factory()->create())->test('admin.report-branding')
            ->set('logo', $this->pngUpload())
            ->call('save')
            ->assertHasNoErrors();

        $firstPath = ReportBranding::current()->logo_path;
        Storage::disk('local')->assertExists($firstPath);

        $component->set('logo', $this->pngUpload())->call('save')->assertHasNoErrors();

        $secondPath = ReportBranding::current()->logo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_removing_the_logo_deletes_the_file(): void
    {
        $component = Volt::actingAs(User::factory()->create())->test('admin.report-branding')
            ->set('logo', $this->pngUpload())
            ->call('save');

        $path = ReportBranding::current()->logo_path;

        $component->call('removeLogo');

        $this->assertNull(ReportBranding::current()->logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_reports_use_defaults_without_branding(): void
    {
        $branding = ReportBranding::forReports();

        $this->assertSame(ReportBranding::DEFAULT_ACCENT, $branding['accent']);
        $this->assertNull($branding['logo']);
    }

    public function test_the_chart_uses_the_accent_colour(): void
    {
        $svg = app(OrganisationManagementReport::class)->trendChartSvg(
            collect([['date' => '2026-09-01', 'total' => 10, 'dmarc_pass_pct' => 90.0], ['date' => '2026-09-02', 'total' => 10, 'dmarc_pass_pct' => 99.0]]),
            '#0f766e',
        );

        $this->assertStringContainsString('stroke="#0f766e"', $svg);
    }

    public function test_both_pdf_reports_render_with_branding(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();
        Storage::disk('local')->put('branding/logo.png', $this->png());
        ReportBranding::factory()->create(['accent_color' => '#0f766e', 'logo_path' => 'branding/logo.png']);

        foreach (['organisations.report-pdf', 'organisations.management-report-pdf'] as $route) {
            $response = $this->actingAs($user)->get(route($route, $organisation));

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    private function pngUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', $this->png());
    }

    /**
     * A small opaque PNG built without GD, which isn't installed everywhere.
     */
    private function png(): string
    {
        $width = 4;
        $height = 2;
        $pixels = str_repeat("\0".str_repeat("\x0f\x76\x6e", $width), $height);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($pixels))
            .$chunk('IEND', '');
    }
}
