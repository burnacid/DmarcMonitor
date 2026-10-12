<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A single IPv4/IPv6 address, or a CIDR range such as 192.0.2.0/24.
 */
class IpOrCidr implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValid($value)) {
            $fail(__('The :attribute must be an IP address or CIDR range (e.g. 192.0.2.10 or 192.0.2.0/24).'));
        }
    }

    private function isValid(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $maxPrefix = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $maxPrefix;
    }
}
