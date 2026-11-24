<?php

namespace App\Support;

use App\Models\AggregateReportRecord;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * How an expected source recognises the records it covers.
 */
enum ExpectedSourceMatch: string
{
    case HeloDomain = 'helo_domain';
    case PtrSuffix = 'ptr_suffix';
    case IpOrCidr = 'ip_or_cidr';
    case Asn = 'asn';

    public function label(): string
    {
        return match ($this) {
            self::HeloDomain => 'SPF / HELO domain',
            self::PtrSuffix => 'Hostname (PTR)',
            self::IpOrCidr => 'IP address or range',
            self::Asn => 'ASN',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::HeloDomain => 'inkyphishfence.com',
            self::PtrSuffix => 'mail.example.net',
            self::IpOrCidr => '192.0.2.0/24',
            self::Asn => '16509',
        };
    }

    /**
     * Whether the record matches $value. Domain and hostname values match the
     * name itself and any subdomain of it, case-insensitively.
     */
    public function matches(AggregateReportRecord $record, string $value): bool
    {
        return match ($this) {
            self::HeloDomain => self::isDomainOrSubdomain($record->spf_domain, $value),
            self::PtrSuffix => self::isDomainOrSubdomain($record->ptr_hostname, $value),
            self::IpOrCidr => $record->source_ip !== null && IpUtils::checkIp($record->source_ip, $value),
            self::Asn => $record->asn !== null && (int) $record->asn === (int) ltrim(strtolower($value), 'as'),
        };
    }

    private static function isDomainOrSubdomain(?string $name, string $suffix): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        $name = strtolower(rtrim($name, '.'));
        $suffix = strtolower(trim(rtrim($suffix, '.'), " \t."));

        return $suffix !== '' && ($name === $suffix || str_ends_with($name, '.'.$suffix));
    }
}
