@props([
    'purpose',
    'permission',
    'redirectUri',
    'sharedAppConfigured',
    'examplePolicyMailbox',
])

<details {{ $attributes->merge(['class' => 'mb-6 bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300']) }}>
    <summary class="cursor-pointer font-medium text-gray-900 dark:text-gray-100">{{ __('How to set up Microsoft 365 for :purpose', ['purpose' => $purpose]) }}</summary>

    <h3 class="mt-4 font-medium text-gray-900 dark:text-gray-100">{{ __('Connect with Microsoft (recommended)') }}</h3>
    @if ($sharedAppConfigured)
        <p class="mt-2">{{ __('Click "Connect with Microsoft", sign in as a Global Administrator (or Privileged Role Administrator) of the tenant, and accept the permissions. You then only enter the mailbox address — shared mailboxes are supported and the admin does not need a mailbox of their own. Each tenant only needs to be connected once.') }}</p>
    @else
        <p class="mt-2">{{ __('Not enabled yet. The installation administrator registers one app, once, and every tenant can then be connected with a single sign-in:') }}</p>
        <ol class="mt-2 list-decimal list-inside space-y-2">
            <li>{{ __('In the Microsoft Entra admin center, go to App registrations → New registration. Under Supported account types, choose "Accounts in any organizational directory (Multitenant)".') }}</li>
            <li>{{ __('Under Redirect URI, choose platform "Web" and enter:') }} <code class="px-1 rounded bg-gray-100 dark:bg-gray-900 break-all">{{ $redirectUri }}</code></li>
            <li>{{ __('Go to API permissions → Add a permission → Microsoft Graph → Application permissions, and add Mail.ReadWrite and Mail.Send (one app serves both collecting and sending).') }}</li>
            <li>{{ __('Go to Certificates & secrets → New client secret, and copy the value immediately — it is only shown once.') }}</li>
            <li>{{ __('Set MICROSOFT365_CLIENT_ID (the Application ID) and MICROSOFT365_CLIENT_SECRET in the environment configuration, and clear the config cache if it is cached.') }}</li>
        </ol>
    @endif

    <h3 class="mt-4 font-medium text-gray-900 dark:text-gray-100">{{ __('Alternative: your own app registration') }}</h3>
    <ol class="mt-2 list-decimal list-inside space-y-2">
        <li>{{ __('In the Microsoft Entra admin center, go to App registrations → New registration. No redirect URI is needed — this is an app-only, non-interactive app.') }}</li>
        <li>{{ __('Go to API permissions → Add a permission → Microsoft Graph → Application permissions, add :permission, then Grant admin consent.', ['permission' => $permission]) }}</li>
        <li>{{ __('Go to Certificates & secrets → New client secret, and copy the value immediately — it is only shown once.') }}</li>
        <li>{{ __('Create the account below with "Own app registration", and copy in the Application (client) ID, Directory (tenant) ID and secret.') }}</li>
    </ol>
    {{ $slot }}

    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
        {{ __('Recommended either way: the app permission covers every mailbox in the tenant. Restrict it to only this mailbox with an Exchange Online application access policy (use the app\'s Application ID):') }}
        <code class="block mt-1 p-2 rounded bg-gray-100 dark:bg-gray-900 overflow-x-auto">New-ApplicationAccessPolicy -AppId "&lt;client-id&gt;" -PolicyScopeGroupId "{{ $examplePolicyMailbox }}" -AccessRight RestrictAccess -Description "DMARC monitor"</code>
    </p>
</details>
