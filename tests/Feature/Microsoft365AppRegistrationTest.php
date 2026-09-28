<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Microsoft365AppRegistration;
use App\Models\User;
use App\Support\Microsoft365App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Microsoft365AppRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string CLIENT_ID = '5d1f7a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b';

    public function test_admins_can_register_the_shared_app(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-app')
            ->set('client_id', self::CLIENT_ID)
            ->set('client_secret', 'app-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('client_secret', '');

        $this->assertTrue(Microsoft365App::isConfigured());
        $this->assertSame(self::CLIENT_ID, Microsoft365App::clientId());
        $this->assertSame('app-secret', Microsoft365App::clientSecret());
        $this->assertNotSame('app-secret', Microsoft365AppRegistration::query()->toBase()->value('client_secret'));
        $this->assertTrue(AuditLog::where('action', 'microsoft365_app.created')->exists());
    }

    public function test_a_new_registration_requires_a_valid_client_id_and_a_secret(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-app')
            ->set('client_id', 'not-a-uuid')
            ->call('save')
            ->assertHasErrors(['client_id', 'client_secret']);

        $this->assertFalse(Microsoft365App::isConfigured());
    }

    public function test_updating_with_a_blank_secret_keeps_the_current_secret(): void
    {
        Microsoft365AppRegistration::factory()->create(['client_secret' => 'existing-secret']);

        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-app')
            ->set('client_id', self::CLIENT_ID)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Microsoft365AppRegistration::count());
        $this->assertSame(self::CLIENT_ID, Microsoft365App::clientId());
        $this->assertSame('existing-secret', Microsoft365App::clientSecret());
    }

    public function test_removing_the_registration_disables_connect_with_microsoft(): void
    {
        Microsoft365AppRegistration::factory()->create();

        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-app')
            ->call('delete');

        $this->assertFalse(Microsoft365App::isConfigured());
        $this->assertTrue(AuditLog::where('action', 'microsoft365_app.deleted')->exists());
    }

    public function test_only_admins_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get(route('admin.microsoft365-app'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.microsoft365-app'))
            ->assertOk()
            ->assertSee(route('admin.microsoft365.callback'));
    }

    public function test_the_migration_imports_credentials_from_the_environment(): void
    {
        config([
            'services.microsoft365.client_id' => self::CLIENT_ID,
            'services.microsoft365.client_secret' => 'env-secret',
        ]);

        Schema::drop('microsoft365_app_registrations');
        (require database_path('migrations/2026_09_28_053848_create_microsoft365_app_registrations_table.php'))->up();

        $this->assertSame(self::CLIENT_ID, Microsoft365App::clientId());
        $this->assertSame('env-secret', Microsoft365App::clientSecret());
    }
}
