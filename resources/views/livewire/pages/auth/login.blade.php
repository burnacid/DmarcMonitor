<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $user = $this->form->authenticate();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Not logged in yet — the challenge page completes the login
            // once the TOTP/recovery code is verified.
            session([
                'login.id' => $user->id,
                'login.remember' => $this->form->remember,
            ]);

            $this->redirect(route('two-factor.challenge'), navigate: true);

            return;
        }

        Auth::login($user, $this->form->remember);

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    <div
        x-data="{
            loading: false,
            error: null,
            supported: false,
            init() { this.supported = window.Passkeys?.isSupported() ?? false; },
            async signIn() {
                this.error = null;
                this.loading = true;
                try {
                    const remember = document.getElementById('remember')?.checked ?? false;
                    const response = await window.Passkeys.verify({ remember });
                    window.location.href = response.redirect;
                } catch (e) {
                    this.loading = false;
                    if (e?.name !== 'UserCancelledError') {
                        this.error = e?.message ?? '{{ __('Unable to sign in with a passkey.') }}';
                    }
                }
            },
        }"
        x-show="supported"
        x-cloak
        class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700"
    >
        <x-secondary-button type="button" x-on:click="signIn" x-bind:disabled="loading" class="w-full justify-center">
            <span x-show="!loading">{{ __('Sign in with a passkey') }}</span>
            <span x-show="loading" x-cloak>{{ __('Signing in…') }}</span>
        </x-secondary-button>
        <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
    </div>
</div>
