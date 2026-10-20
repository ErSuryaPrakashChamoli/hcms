<?php

namespace App\Support\Validation;

use App\Support\Http\OutboundUrlGuard;
use App\Support\Http\UnsafeOutboundUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Form-time check of a server-side destination (no DNS): https, no credentials, allowed port, not a
 * local or internal name, not a literal private address. This only helps the user; the full check,
 * after DNS and pinned, runs on every request (SafeHttp).
 */
final class SafeOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(OutboundUrlGuard::class)->staticParts((string) $value);
        } catch (UnsafeOutboundUrl $e) {
            $fail($e->getMessage());
        }
    }
}
