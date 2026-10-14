<?php

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether the app may send a request to a user-supplied URL (alert
 * webhooks). Without this, anyone who can edit an alert rule could make the
 * server call internal services or the cloud metadata endpoint
 * (169.254.169.254) on their behalf.
 */
class OutboundUrl
{
    /**
     * @var Closure(string): list<string>
     */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  Returns the IP addresses a hostname resolves to; defaults to a DNS A/AAAA lookup.
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveHost(...);
    }

    /**
     * Whether the URL uses http(s) and, unless private networks are allowed
     * (dmarc.webhooks.allow_private_networks), every address its host
     * resolves to is public. A host that doesn't resolve is refused.
     */
    public function isAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return false;
        }

        if (config('dmarc.webhooks.allow_private_networks')) {
            return true;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (IpUtils::isPrivateIp($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
