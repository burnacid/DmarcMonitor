<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.manage-passkeys')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }

    public function test_user_can_see_their_registered_passkeys(): void
    {
        $user = User::factory()->create();
        $passkey = $user->passkeys()->create([
            'name' => 'MacBook Pro',
            'credential_id' => 'credential-id-1',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user);

        Volt::test('profile.manage-passkeys')
            ->assertSee($passkey->name);
    }

    public function test_user_can_delete_their_own_passkey(): void
    {
        $user = User::factory()->create();
        $passkey = $user->passkeys()->create([
            'name' => 'MacBook Pro',
            'credential_id' => 'credential-id-1',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user);

        Volt::test('profile.manage-passkeys')
            ->call('delete', $passkey->id);

        $this->assertNull(Passkey::find($passkey->id));
    }

    public function test_user_cannot_delete_another_users_passkey(): void
    {
        $owner = User::factory()->create();
        $passkey = $owner->passkeys()->create([
            'name' => 'MacBook Pro',
            'credential_id' => 'credential-id-1',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs(User::factory()->create());

        Volt::test('profile.manage-passkeys')
            ->call('delete', $passkey->id)
            ->assertForbidden();

        $this->assertNotNull(Passkey::find($passkey->id));
    }
}
