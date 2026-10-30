<?php

namespace App\Support;

/**
 * Hosted mail platforms recognisable from a domain's MX records that sign
 * outgoing mail with fixed, well-known DKIM selectors, so their DKIM records
 * can be checked before any aggregate report has shown a selector.
 */
enum MailProvider: string
{
    case Microsoft365 = 'microsoft365';
    case GoogleWorkspace = 'google_workspace';
    case ProtonMail = 'proton_mail';
    case Fastmail = 'fastmail';
    case ICloud = 'icloud';

    public function label(): string
    {
        return match ($this) {
            self::Microsoft365 => 'Microsoft 365',
            self::GoogleWorkspace => 'Google Workspace',
            self::ProtonMail => 'Proton Mail',
            self::Fastmail => 'Fastmail',
            self::ICloud => 'iCloud Mail',
        };
    }

    /**
     * Host name suffixes of the provider's inbound mail servers.
     *
     * @return list<string>
     */
    public function mxSuffixes(): array
    {
        return match ($this) {
            self::Microsoft365 => ['mail.protection.outlook.com', 'mx.microsoft'],
            self::GoogleWorkspace => ['aspmx.l.google.com', 'googlemail.com', 'smtp.google.com'],
            self::ProtonMail => ['protonmail.ch'],
            self::Fastmail => ['messagingengine.com'],
            self::ICloud => ['mail.icloud.com'],
        };
    }

    /**
     * The selectors the provider signs with. Microsoft 365 rotates between
     * two, Proton Mail and Fastmail use three; all of them need a CNAME, but
     * Microsoft 365 and Proton Mail only publish the keys currently in use
     * (see Domain::isStandbyDkimSelector()).
     *
     * @return list<string>
     */
    public function dkimSelectors(): array
    {
        return match ($this) {
            self::Microsoft365 => ['selector1', 'selector2'],
            self::GoogleWorkspace => ['google'],
            self::ProtonMail => ['protonmail', 'protonmail2', 'protonmail3'],
            self::Fastmail => ['fm1', 'fm2', 'fm3'],
            self::ICloud => ['sig1'],
        };
    }

    /**
     * The provider handling most of the given MX hosts' mail, judged by the
     * lowest preference (most preferred) MX that belongs to a known provider.
     *
     * @param  array<string, int>  $mxHosts  host => preference
     */
    public static function detect(array $mxHosts): ?self
    {
        asort($mxHosts);

        foreach (array_keys($mxHosts) as $host) {
            $host = strtolower(rtrim($host, '.'));

            foreach (self::cases() as $provider) {
                foreach ($provider->mxSuffixes() as $suffix) {
                    if ($host === $suffix || str_ends_with($host, ".{$suffix}")) {
                        return $provider;
                    }
                }
            }
        }

        return null;
    }
}
