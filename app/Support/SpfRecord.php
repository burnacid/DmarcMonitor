<?php

namespace App\Support;

/**
 * Parses and builds SPF TXT records (RFC 7208), for editing a domain's
 * record term by term in the SPF editor.
 */
class SpfRecord
{
    /** A single TXT string holds at most 255 characters (RFC 7208 §3.3). */
    public const int MAX_STRING_LENGTH = 255;

    /** Mechanisms and modifiers that cost a DNS lookup (RFC 7208 §4.6.4). */
    public const array LOOKUP_TERMS = ['include', 'a', 'mx', 'ptr', 'exists', 'redirect'];

    /** The mechanisms the editor's "add" row offers. */
    public const array ADDABLE_MECHANISMS = ['include', 'ip4', 'ip6', 'a', 'mx'];

    /**
     * Common sending services and the include each one documents.
     *
     * @var array<string, array{label: string, include: string}>
     */
    public const array PROVIDER_PRESETS = [
        'microsoft365' => ['label' => 'Microsoft 365', 'include' => 'spf.protection.outlook.com'],
        'google' => ['label' => 'Google Workspace', 'include' => '_spf.google.com'],
        'mailchimp' => ['label' => 'Mailchimp', 'include' => 'servers.mcsv.net'],
        'sendgrid' => ['label' => 'SendGrid', 'include' => 'sendgrid.net'],
        'amazonses' => ['label' => 'Amazon SES', 'include' => 'amazonses.com'],
        'mailgun' => ['label' => 'Mailgun', 'include' => 'mailgun.org'],
    ];

    public static function isSpfRecord(string $txt): bool
    {
        return (bool) preg_match('/^v=spf1(\s|$)/i', trim($txt));
    }

    /**
     * The record's terms, without the "v=spf1" version. The argument is
     * everything after the name, separator included (":host/24", "/24",
     * "=host"), so a term is rebuilt exactly as written.
     *
     * @return list<array{qualifier: string, name: string, argument: string}>
     */
    public static function parse(?string $record): array
    {
        $terms = [];

        foreach (preg_split('/\s+/', trim((string) $record), -1, PREG_SPLIT_NO_EMPTY) as $index => $part) {
            if ($index === 0 && strtolower($part) === 'v=spf1') {
                continue;
            }

            if (! preg_match('/^([+\-~?]?)([a-z][a-z0-9_.\-]*)(.*)$/i', $part, $matches)) {
                continue;
            }

            $terms[] = [
                'qualifier' => $matches[1] === '' ? '+' : $matches[1],
                'name' => strtolower($matches[2]),
                'argument' => $matches[3],
            ];
        }

        return $terms;
    }

    /**
     * Writes the terms after "v=spf1" without duplicates, mechanisms first,
     * then "all", then the redirect/exp modifiers.
     *
     * @param  list<array{qualifier: string, name: string, argument: string}>  $terms
     */
    public static function build(array $terms): string
    {
        $mechanisms = [];
        $all = null;
        $modifiers = [];

        foreach ($terms as $term) {
            $text = self::termToString($term);

            if ($term['name'] === 'all') {
                $all ??= $text;
            } elseif (self::isModifier($term)) {
                $modifiers[$term['name']] ??= $text;
            } elseif (! in_array(strtolower($text), array_map('strtolower', $mechanisms), true)) {
                $mechanisms[] = $text;
            }
        }

        return implode(' ', array_filter(['v=spf1', ...$mechanisms, $all, ...array_values($modifiers)]));
    }

    /**
     * @param  array{qualifier: string, name: string, argument: string}  $term
     */
    public static function termToString(array $term): string
    {
        $qualifier = $term['qualifier'] === '+' || self::isModifier($term) ? '' : $term['qualifier'];

        return $qualifier.$term['name'].$term['argument'];
    }

    /**
     * @param  array{qualifier: string, name: string, argument: string}  $term
     */
    public static function isModifier(array $term): bool
    {
        return str_starts_with($term['argument'], '=');
    }

    /**
     * The domain a term points at ("include:x" → x, "a:x/24" → x,
     * "redirect=x" → x), or null when it has none and uses the record's own.
     *
     * @param  array{qualifier: string, name: string, argument: string}  $term
     */
    public static function domainSpec(array $term): ?string
    {
        if (! preg_match('/^[:=]([^\/]+)/', $term['argument'], $matches)) {
            return null;
        }

        return strtolower(rtrim($matches[1], '.'));
    }

    /**
     * The IPv4 and IPv6 prefix lengths on an a/mx term ("a/24", "mx:x//64").
     *
     * @param  array{qualifier: string, name: string, argument: string}  $term
     * @return array{0: ?int, 1: ?int}
     */
    public static function cidrLengths(array $term): array
    {
        preg_match('/(?:\/(\d+))?(?:\/\/(\d+))?$/', $term['argument'], $matches);

        return [
            isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : null,
            isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null,
        ];
    }

    /**
     * The record as the TXT strings to publish: a record over 255
     * characters is split between terms, each string but the last ending
     * with a space, so receivers joining them get the record back unchanged.
     *
     * @return list<string>
     */
    public static function dnsStrings(string $record): array
    {
        if (strlen($record) <= self::MAX_STRING_LENGTH) {
            return [$record];
        }

        $strings = [];
        $current = '';

        foreach (explode(' ', $record) as $word) {
            if ($current !== '' && strlen($current.$word.' ') > self::MAX_STRING_LENGTH) {
                $strings[] = $current;
                $current = '';
            }

            $current .= $word.' ';
        }

        $strings[] = rtrim($current);

        return $strings;
    }
}
