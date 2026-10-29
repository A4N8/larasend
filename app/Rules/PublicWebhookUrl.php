<?php

namespace App\Rules;

use App\Services\WebhookUrlGuard;
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
        if (! is_string($value) || ! app(WebhookUrlGuard::class)->isSafe($value)) {
            $fail('The :attribute must use a publicly reachable HTTP or HTTPS address.');
        }
    }
}
