<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\Microsoft365App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Connect with Microsoft": sends a tenant admin to Microsoft's admin consent
 * page for the shared app registration, then hands the consented tenant ID
 * back to the mailbox or sending account page to finish the setup.
 */
class Microsoft365ConnectController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const array TARGET_ROUTES = [
        'mailbox' => 'admin.microsoft365-mail-accounts',
        'sending' => 'admin.microsoft365-send-account',
    ];

    public function redirect(Request $request, string $target): RedirectResponse
    {
        abort_unless(array_key_exists($target, self::TARGET_ROUTES), 404);

        if (! Microsoft365App::isConfigured()) {
            return redirect()->route(self::TARGET_ROUTES[$target])
                ->with('microsoft365_connect_error', __('Connect with Microsoft is not configured: set MICROSOFT365_CLIENT_ID and MICROSOFT365_CLIENT_SECRET.'));
        }

        $state = Str::random(40);
        $request->session()->put('microsoft365_connect', ['state' => $state, 'target' => $target]);

        return redirect()->away(Microsoft365App::adminConsentUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('microsoft365_connect');
        $route = self::TARGET_ROUTES[$pending['target'] ?? 'mailbox'] ?? self::TARGET_ROUTES['mailbox'];

        if (! is_array($pending) || ! hash_equals((string) $pending['state'], (string) $request->query('state'))) {
            return redirect()->route($route)
                ->with('microsoft365_connect_error', __('The Microsoft sign-in could not be verified. Please try connecting again.'));
        }

        if ($request->filled('error')) {
            $message = $request->query('error') === 'access_denied'
                ? __('Permission was not granted. Sign in with a Global Administrator or Privileged Role Administrator account and accept the requested permissions.')
                : (string) ($request->query('error_description') ?: $request->query('error'));

            return redirect()->route($route)->with('microsoft365_connect_error', $message);
        }

        $tenantId = (string) $request->query('tenant');

        if (! Str::isUuid($tenantId) || strtolower((string) $request->query('admin_consent')) !== 'true') {
            return redirect()->route($route)
                ->with('microsoft365_connect_error', __('Microsoft did not confirm admin consent. Please try connecting again.'));
        }

        AuditLogger::record(
            action: 'microsoft365.tenant_connected',
            description: "Connected Microsoft 365 tenant {$tenantId}",
            context: ['tenant_id' => $tenantId, 'target' => $pending['target']],
        );

        return redirect()->route($route)->with('microsoft365_connected_tenant', $tenantId);
    }
}
