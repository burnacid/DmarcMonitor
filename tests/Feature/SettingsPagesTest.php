<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_settings_and_microsoft365_pages(): void
    {
        foreach (['admin.settings', 'admin.microsoft365'] as $route) {
            $this->actingAs(User::factory()->editor()->create())->get(route($route))->assertForbidden();
            $this->actingAs(User::factory()->viewer()->create())->get(route($route))->assertForbidden();
            $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
        }
    }

    public function test_each_settings_tab_shows_its_own_section(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.settings'))->assertSee('Low pass rate period')->assertDontSee('Accent colour');
        $this->actingAs($admin)->get(route('admin.settings', ['tab' => 'public-dashboard']))->assertSee('Auto-scroll organisations');
        $this->actingAs($admin)->get(route('admin.settings', ['tab' => 'report-branding']))->assertSee('Accent colour')->assertDontSee('Low pass rate period');
    }

    public function test_each_microsoft365_tab_shows_its_own_section(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.microsoft365'))->assertSee('New Mailbox');
        $this->actingAs($admin)->get(route('admin.microsoft365', ['tab' => 'sending']))->assertSee('New Sending Account');
        $this->actingAs($admin)->get(route('admin.microsoft365', ['tab' => 'app']))->assertSee(route('admin.microsoft365.callback'));
    }

    public function test_an_unknown_tab_falls_back_to_the_first_one(): void
    {
        Volt::actingAs(User::factory()->create())->withQueryParams(['tab' => 'bogus'])->test('admin.settings')->assertSet('tab', 'dashboard');
        Volt::actingAs(User::factory()->create())->withQueryParams(['tab' => 'bogus'])->test('admin.microsoft365')->assertSet('tab', 'mailboxes');
    }

    public function test_switching_tabs_changes_the_section(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.settings')
            ->call('selectTab', 'report-branding')
            ->assertSet('tab', 'report-branding')
            ->call('selectTab', 'bogus')
            ->assertSet('tab', 'dashboard');
    }

    public function test_the_former_pages_redirect_to_their_tab(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.dashboard-settings'))->assertRedirect('/admin/settings?tab=dashboard');
        $this->get(route('admin.public-dashboard'))->assertRedirect('/admin/settings?tab=public-dashboard');
        $this->get(route('admin.report-branding'))->assertRedirect('/admin/settings?tab=report-branding');
        $this->get(route('admin.microsoft365-mail-accounts'))->assertRedirect('/admin/microsoft365?tab=mailboxes');
        $this->get(route('admin.microsoft365-send-account'))->assertRedirect('/admin/microsoft365?tab=sending');
        $this->get(route('admin.microsoft365-app'))->assertRedirect('/admin/microsoft365?tab=app');
    }
}
