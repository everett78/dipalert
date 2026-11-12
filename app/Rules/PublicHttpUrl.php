<?php

namespace App\Rules;

use App\Exceptions\BlockedUrlException;
use App\Services\Security\UrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL is a publicly reachable http/https target and not an
 * internal, loopback, link-local or otherwise reserved host. The checks live in
 * UrlGuard, which also guards every fetch.
 *
 * With $allowInternalServices the hosts in DIPALERT_ALLOWED_INTERNAL_HOSTS are
 * accepted too (for service addresses such as an Ollama base URL).
 */
class PublicHttpUrl implements ValidationRule
{
    public function __construct(protected bool $allowInternalServices = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $reason = $this->reason($value);

        match ($reason) {
            null => null,
            UrlGuard::INVALID => $fail('The :attribute must be a valid URL.'),
            UrlGuard::SCHEME => $fail('The :attribute must use the http or https scheme.'),
            UrlGuard::UNRESOLVED => $fail('The :attribute host could not be resolved.'),
            default => $fail('The :attribute must not point to an internal or reserved host.'),
        };
    }

    protected function reason(string $value): ?string
    {
        $guard = UrlGuard::new();

        if (! $this->allowInternalServices) {
            return $guard->check($value);
        }

        try {
            $guard->assertServiceAllowed($value);
        } catch (BlockedUrlException $e) {
            return $e->reason;
        }

        return null;
    }
}
