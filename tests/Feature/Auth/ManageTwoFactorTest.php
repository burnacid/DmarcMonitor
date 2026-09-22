<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ManageTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_generates_an_unconfirmed_secret_and_recovery_codes(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->call('enableTwoFactorAuthentication')
            ->assertSet('showingRecoveryCodes', false);

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_a_valid_code_enables_two_factor_and_reveals_recovery_codes(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('profile.manage-two-factor')
            ->call('enableTwoFactorAuthentication');

        $secret = $user->fresh()->two_factor_secret;
        $validCode = app(Google2FA::class)->getCurrentOtp($secret);

        $component->set('code', $validCode)
            ->call('confirmTwoFactorAuthentication')
            ->assertHasNoErrors()
            ->assertSet('showingRecoveryCodes', true);

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_an_invalid_code_does_not_enable_two_factor(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->call('enableTwoFactorAuthentication')
            ->set('code', '000000')
            ->call('confirmTwoFactorAuthentication')
            ->assertHasErrors('code');

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_cancelling_setup_clears_the_unconfirmed_secret(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->call('enableTwoFactorAuthentication')
            ->call('cancelSetup');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_disabling_requires_the_correct_password(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => ['a-b'],
            'two_factor_confirmed_at' => now(),
        ]);

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->set('password', 'wrong-password')
            ->call('disableTwoFactorAuthentication')
            ->assertHasErrors('password');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_with_the_correct_password_clears_two_factor_state(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => ['a-b'],
            'two_factor_confirmed_at' => now(),
        ]);

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->set('password', 'password')
            ->call('disableTwoFactorAuthentication')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_regenerating_recovery_codes_replaces_the_old_ones(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => ['old-code'],
            'two_factor_confirmed_at' => now(),
        ]);

        Volt::actingAs($user)->test('profile.manage-two-factor')
            ->call('regenerateRecoveryCodes')
            ->assertSet('showingRecoveryCodes', true);

        $this->assertNotEquals(['old-code'], $user->fresh()->recoveryCodes());
        $this->assertCount(10, $user->fresh()->recoveryCodes());
    }
}
