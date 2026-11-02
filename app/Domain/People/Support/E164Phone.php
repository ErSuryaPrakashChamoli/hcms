<?php

namespace App\Domain\People\Support;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * HROne parity MS-4: validation rule for a phone whose country code is another field of the same data (the People /
 * Employment actions, imports). Without a code the number is accepted as typed, as before; with one it must make a valid
 * E.164 number (PhoneNumbers::normalise).
 */
final class E164Phone implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $codeField) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = data_get($this->data, $this->codeField);
        if (is_string($value) && trim($value) !== '' && ($problem = PhoneNumbers::problem($code === null ? null : (string) $code, $value)) !== null) {
            $fail($problem);
        }
    }
}
