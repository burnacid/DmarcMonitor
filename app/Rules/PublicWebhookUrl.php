<?php

namespace App\Rules;

use App\Support\OutboundUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PublicWebhookUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(OutboundUrl::class)->isAllowed($value)) {
            $fail(__('The webhook must be an http(s) address on the public internet. Internal and private network addresses are blocked (set DMARC_WEBHOOK_ALLOW_PRIVATE=true to allow them).'));
        }
    }
}
