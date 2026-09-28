<?php

namespace App\Support;

/**
 * Parses and builds DMARC TXT records (RFC 7489), for showing a domain's
 * current policy and generating the record to hand to a client.
 */
class DmarcRecord
{
    /**
     * Tags written in this order by build(); any other tag found in an
     * existing record is kept and appended after them.
     */
    private const array TAG_ORDER = ['p', 'sp', 'pct', 'rua', 'ruf', 'adkim', 'aspf', 'fo'];

    /**
     * Tag values that are the RFC default and so are left out of a built record.
     */
    private const array DEFAULTS = ['pct' => '100', 'adkim' => 'r', 'aspf' => 'r', 'fo' => '0'];

    /**
     * @return array<string, string> Tag name (lowercase, without "v") => value.
     */
    public static function parse(?string $record): array
    {
        if ($record === null || trim($record) === '') {
            return [];
        }

        $tags = [];

        foreach (explode(';', $record) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $part, 2));
            $name = strtolower($name);

            if ($name === '' || $name === 'v') {
                continue;
            }

            $tags[$name] = in_array($name, ['p', 'sp', 'adkim', 'aspf'], true) ? strtolower($value) : $value;
        }

        return $tags;
    }

    /**
     * @param  array<string, string|int|null>  $tags
     */
    public static function build(array $tags): string
    {
        $parts = ['v=DMARC1'];
        $names = array_unique([...self::TAG_ORDER, ...array_keys($tags)]);

        foreach ($names as $name) {
            $value = trim((string) ($tags[$name] ?? ''));

            if ($name === 'v' || $value === '' || (self::DEFAULTS[$name] ?? null) === $value) {
                continue;
            }

            $parts[] = "{$name}={$value}";
        }

        return implode('; ', $parts);
    }

    /**
     * The plain addresses in a rua/ruf value, without "mailto:" or a "!10m" size limit.
     *
     * @return list<string>
     */
    public static function addresses(?string $uriList): array
    {
        if ($uriList === null || trim($uriList) === '') {
            return [];
        }

        return collect(explode(',', $uriList))
            ->map(fn (string $uri) => strtolower(trim(preg_replace(['/^\s*mailto:/i', '/!.*$/'], '', $uri))))
            ->filter(fn (string $address) => str_contains($address, '@'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $addresses
     */
    public static function uriList(array $addresses): string
    {
        return collect($addresses)
            ->map(fn (string $address) => trim($address))
            ->filter()
            ->map(fn (string $address) => 'mailto:'.$address)
            ->implode(',');
    }

    /**
     * The DNS name the report receiver's domain must publish "v=DMARC1" at
     * before mailbox providers will send $domain's reports to an address on
     * another domain (RFC 7489 §7.1), or null when no such record is needed.
     */
    public static function externalAuthorizationHost(string $domain, string $address): ?string
    {
        $domain = strtolower(rtrim($domain, '.'));
        $reportDomain = strtolower(rtrim((string) substr(strrchr($address, '@') ?: '', 1), '.'));

        if ($reportDomain === ''
            || $reportDomain === $domain
            || str_ends_with($domain, '.'.$reportDomain)
            || str_ends_with($reportDomain, '.'.$domain)) {
            return null;
        }

        return "{$domain}._report._dmarc.{$reportDomain}";
    }
}
