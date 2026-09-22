<?php

use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticationService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $code = '';

    public string $recovery_code = '';

    public bool $usingRecoveryCode = false;

    /**
     * Bail out to the login form if this page is reached without a pending
     * login — it only makes sense as the second step of one.
     */
    public function mount(): void
    {
        if (! session('login.id')) {
            $this->redirect(route('login'), navigate: true);
        }
    }

    public function verifyCode(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->pendingUser();

        if (! app(TwoFactorAuthenticationService::class)->verify($user->two_factor_secret, $this->code)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'code' => __('The provided code is invalid.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $this->completeLogin($user);
    }

    public function verifyRecoveryCode(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->pendingUser();
        $codes = $user->recoveryCodes();

        if (! in_array($this->recovery_code, $codes, true)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'recovery_code' => __('This recovery code is invalid.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Recovery codes are single-use — remove the one just spent.
        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$this->recovery_code])),
        ])->save();

        AuditLogger::record(
            action: 'auth.two_factor_recovery_code_used',
            description: "{$user->name} logged in with a two-factor recovery code",
            userId: $user->id,
        );

        $this->completeLogin($user);
    }

    private function pendingUser(): User
    {
        $user = User::find(session('login.id'));

        if (! $user) {
            $this->redirect(route('login'), navigate: true);
            abort(404);
        }

        return $user;
    }

    private function completeLogin(User $user): void
    {
        Auth::login($user, (bool) session('login.remember', false));

        session()->forget(['login.id', 'login.remember']);

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'code' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate('two-factor|'.session('login.id').'|'.request()->ip());
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        @if (! $usingRecoveryCode)
            {{ __('Enter the 6-digit code from your authenticator app.') }}
        @else
            {{ __('Enter one of your recovery codes.') }}
        @endif
    </div>

    @if (! $usingRecoveryCode)
        <form wire:submit="verifyCode">
            <div>
                <x-input-label for="code" :value="__('Code')" />
                <x-text-input wire:model="code" id="code" class="block mt-1 w-full" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus required />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>

            <div class="flex items-center justify-between mt-4">
                <button type="button" wire:click="$set('usingRecoveryCode', true)" class="text-sm text-gray-600 dark:text-gray-400 underline">
                    {{ __('Use a recovery code instead') }}
                </button>

                <x-primary-button>{{ __('Verify') }}</x-primary-button>
            </div>
        </form>
    @else
        <form wire:submit="verifyRecoveryCode">
            <div>
                <x-input-label for="recovery_code" :value="__('Recovery code')" />
                <x-text-input wire:model="recovery_code" id="recovery_code" class="block mt-1 w-full font-mono" type="text" autofocus required />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
            </div>

            <div class="flex items-center justify-between mt-4">
                <button type="button" wire:click="$set('usingRecoveryCode', false)" class="text-sm text-gray-600 dark:text-gray-400 underline">
                    {{ __('Use an authenticator code instead') }}
                </button>

                <x-primary-button>{{ __('Verify') }}</x-primary-button>
            </div>
        </form>
    @endif
</div>
