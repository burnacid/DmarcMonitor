<?php

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /**
     * Set the invited user's password and mark their email as verified, since
     * the link could only have been opened from their mailbox.
     */
    public function acceptInvite(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker('invites')->reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                AuditLogger::record(
                    action: 'user.invite_accepted',
                    description: 'Accepted invitation for '.$user->email,
                    subject: $user,
                    userId: $user->id,
                );
            }
        );

        if ($status != Password::PASSWORD_RESET) {
            $this->addError('email', $status === Password::INVALID_TOKEN
                ? __('This invitation link is invalid or has expired. Ask an administrator to send a new one.')
                : __($status));

            return;
        }

        Session::flash('status', __('Your password has been set. You can now log in.'));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Set your password') }}</h2>
    <p class="mt-1 mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Welcome! Choose a password to finish setting up your account.') }}</p>

    <form wire:submit="acceptInvite">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Set password') }}
            </x-primary-button>
        </div>
    </form>
</div>
