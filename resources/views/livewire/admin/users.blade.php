<?php

use App\Models\Organisation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public const array ROLES = ['admin', 'editor', 'viewer'];

    public string $errorMessage = '';

    public string $statusMessage = '';

    public ?int $editingId = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'viewer';

    /**
     * When creating a user: mail them an invitation to choose their own
     * password instead of setting one here.
     */
    public bool $sendInvite = true;

    /**
     * Access to every organisation, including ones added later. Without it
     * the user only sees the organisations selected below.
     */
    public bool $all_organisations = false;

    /** @var array<int> */
    public array $organisation_ids = [];

    private function isLastAdmin(User $user): bool
    {
        return $user->role === 'admin' && User::where('role', 'admin')->count() <= 1;
    }

    /**
     * The last admin with access to all organisations must keep it, or no one
     * could see unassigned domains or grant that access again.
     */
    private function isLastFullAccessAdmin(User $user): bool
    {
        return $user->role === 'admin'
            && $user->all_organisations
            && User::where('role', 'admin')->where('all_organisations', true)->count() <= 1;
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'organisation_ids', 'sendInvite']);
        $this->role = 'viewer';
        $this->all_organisations = ! Organisation::exists();
        $this->dispatch('open-modal', 'user-form');
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->role;
        $this->all_organisations = $user->all_organisations;
        $this->organisation_ids = $user->organisations()->pluck('organisations.id')->all();
        $this->dispatch('open-modal', 'user-form');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$this->editingId,
            'password' => ($this->editingId || $this->sendInvite ? 'nullable' : 'required').'|string|min:8',
            'role' => 'required|in:'.implode(',', self::ROLES),
            'all_organisations' => 'boolean',
            'organisation_ids' => $this->all_organisations ? 'array' : 'array|min:1',
            'organisation_ids.*' => 'exists:organisations,id',
        ], [
            'organisation_ids.min' => __('Pick at least one organisation, or give access to all organisations.'),
        ]);

        $organisationIds = $validated['all_organisations'] ? [] : ($validated['organisation_ids'] ?? []);
        unset($validated['organisation_ids']);

        if ($this->editingId && ! $validated['all_organisations'] && $this->isLastFullAccessAdmin(User::findOrFail($this->editingId))) {
            $this->addError('all_organisations', __('At least one admin needs access to all organisations.'));

            return;
        }

        if ($this->editingId && $validated['role'] !== 'admin') {
            $existing = User::findOrFail($this->editingId);

            if ($this->isLastAdmin($existing)) {
                $this->addError('role', __('You can\'t change the role of the last remaining admin.'));

                return;
            }
        }

        $wasNew = $this->editingId === null;
        $isInvite = $wasNew && $this->sendInvite;

        if ($isInvite) {
            $validated['password'] = Hash::make(Str::password(64));
        } elseif (empty($validated['password'])) {
            unset($validated['password']);
        } else {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user = User::updateOrCreate(['id' => $this->editingId], $validated);
        $user->organisations()->sync($organisationIds);

        if ($wasNew && ! $isInvite) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        AuditLogger::record(
            action: $isInvite ? 'user.invited' : ($wasNew ? 'user.created' : 'user.updated'),
            description: ($isInvite ? 'Invited user ' : ($wasNew ? 'Created user ' : 'Updated user ')).$user->email,
            subject: $user,
            context: $wasNew ? null : AuditLogger::describeChanges($user),
        );

        if ($isInvite) {
            $user->sendInvitation();
            $this->statusMessage = __('An invitation has been sent to :email.', ['email' => $user->email]);
        }

        $this->dispatch('close-modal', 'user-form');
        $this->reset(['editingId', 'name', 'email', 'password', 'organisation_ids', 'all_organisations']);
    }

    public function resendInvite(int $id): void
    {
        $this->errorMessage = '';
        $this->statusMessage = '';

        $user = User::findOrFail($id);

        if (! $user->isInvitePending()) {
            $this->errorMessage = __(':email has already accepted their invitation.', ['email' => $user->email]);

            return;
        }

        $user->sendInvitation();

        AuditLogger::record(
            action: 'user.invite_resent',
            description: 'Resent invitation to '.$user->email,
            subject: $user,
        );

        $this->statusMessage = __('A new invitation has been sent to :email.', ['email' => $user->email]);
    }

    public function delete(int $id): void
    {
        $this->errorMessage = '';

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            $this->errorMessage = __('You can\'t delete your own account.');

            return;
        }

        if ($this->isLastAdmin($user)) {
            $this->errorMessage = __('You can\'t delete the last remaining admin.');

            return;
        }

        AuditLogger::record(
            action: 'user.deleted',
            description: 'Deleted user '.$user->email,
            context: ['email' => $user->email, 'role' => $user->role],
        );

        $user->delete();
    }

    public function with(): array
    {
        return [
            'users' => User::with('organisations:id,name')->orderBy('name')->paginate(15),
            'roles' => self::ROLES,
            'organisations' => Organisation::orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('User Management') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-4">
                <x-primary-button wire:click="create">{{ __('New User') }}</x-primary-button>
            </div>

            @if ($statusMessage)
                <div class="mb-4 rounded-md bg-green-50 dark:bg-green-950 p-4 text-sm text-green-700 dark:text-green-300">
                    {{ $statusMessage }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="mb-4 rounded-md bg-red-50 dark:bg-red-950 p-4 text-sm text-red-700 dark:text-red-300">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Email') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Verified') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Access') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($users as $user)
                            <tr wire:key="user-{{ $user->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Name') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">
                                    {{ $user->name }}
                                    @if ($user->id === auth()->id())
                                        <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">({{ __('you') }})</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('Email') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $user->email }}</td>
                                <td data-label="{{ __('Verified') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($user->email_verified_at)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Verified') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">{{ __('Invite pending') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('Role') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ ucfirst($user->role) }}</span>
                                </td>
                                <td data-label="{{ __('Access') }}" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($user->all_organisations)
                                        {{ __('All organisations') }}
                                    @elseif ($user->organisations->isEmpty())
                                        <span class="text-red-600 dark:text-red-400">{{ __('No organisations') }}</span>
                                    @else
                                        {{ $user->organisations->pluck('name')->join(', ') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button wire:click="edit({{ $user->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    @if ($user->isInvitePending())
                                        <button wire:click="resendInvite({{ $user->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Resend invite') }}</button>
                                    @endif
                                    @unless ($user->id === auth()->id())
                                        <button wire:click="delete({{ $user->id }})" wire:confirm="{{ __('Delete this user?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No users yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $users->links() }}
            </div>
        </div>
    </div>

    <x-modal name="user-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit User') : __('New User') }}
            </h2>

            <div class="mt-6">
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-6">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="email" id="email" type="email" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            @unless ($editingId)
                <div class="mt-6">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input wire:model.live="sendInvite" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        {{ __('Send an invitation so the user can choose their own password') }}
                    </label>
                </div>
            @endunless

            @if ($editingId || ! $sendInvite)
                <div class="mt-6">
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input wire:model="password" id="password" type="password" :placeholder="$editingId ? __('Leave blank to keep current') : ''" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
            @endif

            <div class="mt-6">
                <x-input-label for="role" :value="__('Role')" />
                <select wire:model="role" id="role" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @foreach ($roles as $roleOption)
                        <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>

            <div class="mt-6">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input wire:model.live="all_organisations" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    {{ __('Access to all organisations') }}
                </label>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Includes unassigned domains and organisations added later.') }}</p>
                <x-input-error :messages="$errors->get('all_organisations')" class="mt-2" />
            </div>

            @unless ($all_organisations)
                <div class="mt-6">
                    <x-input-label for="organisation_ids" :value="__('Organisations')" />
                    <select wire:model="organisation_ids" id="organisation_ids" multiple size="4" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        @foreach ($organisations as $organisation)
                            <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('The user only sees these organisations. If an organisation is deleted, the user loses access to it.') }}</p>
                    <x-input-error :messages="$errors->get('organisation_ids')" class="mt-2" />
                </div>
            @endunless

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ ! $editingId && $sendInvite ? __('Send invite') : __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
