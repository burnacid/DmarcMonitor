<?php

namespace Tests\Feature;

use App\Models\DashboardSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_settings_page(): void
    {
        $this->actingAs(User::factory()->editor()->create())->get(route('admin.dashboard-settings'))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('admin.dashboard-settings'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard-settings'))->assertOk();
    }

    public function test_the_dashboard_offers_14_days_out_of_the_box(): void
    {
        Livewire::withoutLazyLoading()->actingAs(User::factory()->create())->test('dashboard')
            ->assertViewHas('dayRanges', [7, 14, 30, 90])
            ->assertSet('days', 30)
            ->set('days', 14)
            ->assertSet('days', 14);
    }

    public function test_admin_can_change_which_periods_are_offered(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.dashboard-settings')
            ->call('toggleDayRange', 365)
            ->call('toggleDayRange', 7);

        $this->assertSame([14, 30, 90, 365], DashboardSetting::dayRanges());

        // A period that isn't offered falls back to the default.
        Livewire::withoutLazyLoading()->actingAs(User::factory()->create())->test('dashboard')
            ->set('days', 7)
            ->assertSet('days', 30);
    }

    public function test_the_last_offered_period_cannot_be_switched_off(): void
    {
        DashboardSetting::factory()->create(['day_ranges' => [30]]);

        Volt::actingAs(User::factory()->create())->test('admin.dashboard-settings')
            ->call('toggleDayRange', 30);

        $this->assertSame([30], DashboardSetting::dayRanges());
    }

    public function test_removing_the_default_period_falls_back_to_the_closest_offered_one(): void
    {
        DashboardSetting::factory()->create(['day_ranges' => [7, 14, 90], 'default_days' => 30]);

        $this->assertSame(14, DashboardSetting::defaultDays());
    }

    public function test_the_dashboard_opens_with_the_site_default_period(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.dashboard-settings')
            ->call('setDefaultDays', 14);

        Livewire::withoutLazyLoading()->actingAs(User::factory()->create())->test('dashboard')->assertSet('days', 14);
    }

    public function test_the_dashboard_opens_with_the_users_own_default_period(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.dashboard-preferences')
            ->set('defaultDays', '7')
            ->call('updateDashboardPreferences')
            ->assertHasNoErrors();

        $this->assertSame(7, $user->fresh()->dashboard_default_days);

        Livewire::withoutLazyLoading()->actingAs($user->fresh())->test('dashboard')->assertSet('days', 7);
    }

    public function test_a_period_in_the_url_wins_over_the_users_default(): void
    {
        $user = User::factory()->create(['dashboard_default_days' => 7]);

        Livewire::withoutLazyLoading()->withQueryParams(['days' => 90])->actingAs($user)->test('dashboard')->assertSet('days', 90);
    }

    public function test_a_user_default_that_is_no_longer_offered_falls_back_to_the_site_default(): void
    {
        DashboardSetting::factory()->create(['day_ranges' => [30, 90], 'default_days' => 90]);
        $user = User::factory()->create(['dashboard_default_days' => 7]);

        Livewire::withoutLazyLoading()->actingAs($user)->test('dashboard')->assertSet('days', 90);
    }

    public function test_a_user_cannot_pick_a_period_that_is_not_offered(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.dashboard-preferences')
            ->set('defaultDays', '365')
            ->call('updateDashboardPreferences')
            ->assertHasErrors('defaultDays');

        $this->assertNull($user->fresh()->dashboard_default_days);
    }

    public function test_admin_can_change_the_low_pass_rate_period(): void
    {
        $this->assertSame(30, DashboardSetting::passRateDays());

        Volt::actingAs(User::factory()->create())->test('admin.dashboard-settings')
            ->call('setPassRateDays', 14)
            ->assertViewHas('passRateDays', 14)
            ->call('setPassRateDays', 90);

        $this->assertSame(14, DashboardSetting::passRateDays());
    }
}
