<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTwoFactorEnabled(): array
    {
        $service = app(TwoFactorAuthenticationService::class);
        $secret = $service->generateSecretKey();

        $user = User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
            'two_factor_confirmed_at' => now(),
        ]);

        return [$user, $secret, $service];
    }

    public function test_login_does_not_fully_authenticate_a_user_with_two_factor_enabled(): void
    {
        [$user] = $this->userWithTwoFactorEnabled();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertEquals($user->id, session('login.id'));
    }

    public function test_a_correct_totp_code_completes_the_login(): void
    {
        [$user, $secret, $service] = $this->userWithTwoFactorEnabled();

        session(['login.id' => $user->id, 'login.remember' => false]);

        $validCode = app(Google2FA::class)->getCurrentOtp($secret);

        Volt::test('pages.auth.two-factor-challenge')
            ->set('code', $validCode)
            ->call('verifyCode')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_incorrect_totp_code_is_rejected(): void
    {
        [$user] = $this->userWithTwoFactorEnabled();

        session(['login.id' => $user->id, 'login.remember' => false]);

        Volt::test('pages.auth.two-factor-challenge')
            ->set('code', '000000')
            ->call('verifyCode')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_valid_recovery_code_completes_the_login_and_is_consumed(): void
    {
        [$user] = $this->userWithTwoFactorEnabled();

        session(['login.id' => $user->id, 'login.remember' => false]);

        Volt::test('pages.auth.two-factor-challenge')
            ->set('recovery_code', 'aaaaa-bbbbb')
            ->call('verifyRecoveryCode')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertEquals(['ccccc-ddddd'], $user->fresh()->recoveryCodes());
    }

    public function test_a_used_recovery_code_cannot_be_reused(): void
    {
        [$user] = $this->userWithTwoFactorEnabled();

        session(['login.id' => $user->id, 'login.remember' => false]);
        Volt::test('pages.auth.two-factor-challenge')
            ->set('recovery_code', 'aaaaa-bbbbb')
            ->call('verifyRecoveryCode');

        Auth::logout();
        session(['login.id' => $user->id, 'login.remember' => false]);

        Volt::test('pages.auth.two-factor-challenge')
            ->set('recovery_code', 'aaaaa-bbbbb')
            ->call('verifyRecoveryCode')
            ->assertHasErrors('recovery_code');

        $this->assertGuest();
    }

    public function test_the_challenge_page_redirects_to_login_without_a_pending_login(): void
    {
        Volt::test('pages.auth.two-factor-challenge')
            ->assertRedirect(route('login'));
    }

    public function test_a_user_without_two_factor_enabled_logs_in_normally(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }
}
