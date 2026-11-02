<?php

namespace App\Domain\People\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * HROne parity MS-4 (matrix 22 §22.2.1 #9, §22.2.2 #7): a phone with an international dialling code is stored in
 * ITU-T E.164 ("+" + country code + national number, 7 to 15 digits). A phone without a code is kept exactly as typed,
 * as before, so every number already on file stays valid.
 *
 * - The number may be typed nationally ("098450 12345": spaces, dashes, dots and brackets are dropped, and so is the
 *   leading trunk 0) or internationally ("+91 98450 12345", "0091…"): it must then start with the chosen code.
 * - The models normalise on save (Person: personal phone, Employee: work phone, PersonEmergencyContact: phone), so every
 *   write path — HR forms, the hire wizard, imports, self-service actions, the API — stores the same form.
 */
final class PhoneNumbers
{
    /** E.164: at most 15 digits including the country code; at least 7 in practice. */
    public const PATTERN = '/^\+[1-9]\d{6,14}$/';

    /** @return array<string, string> code => label (config peopleos.people.phone_country_codes) */
    public static function codes(): array
    {
        return (array) config('peopleos.people.phone_country_codes', []);
    }

    public static function validCode(?string $code): bool
    {
        return $code !== null && preg_match('/^[1-9]\d{0,2}$/', $code) === 1;
    }

    /** Why this number cannot be stored with this code, or null when it can (or there is no code: kept as typed). */
    public static function problem(?string $code, ?string $number): ?string
    {
        try {
            self::normalise($code, $number);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * The number to store: E.164 when a code is given, else the number as typed (blank = null).
     *
     * @throws InvalidArgumentException with a message for the person typing it
     */
    public static function normalise(?string $code, ?string $number): ?string
    {
        $code = $code === null ? null : trim($code);
        $number = $number === null ? null : trim($number);
        if ($number === null || $number === '') {
            return $number === '' ? null : $number;
        }
        if ($code === null || $code === '') {
            return $number;
        }
        $code = ltrim($code, '+');
        if (! self::validCode($code)) {
            throw new InvalidArgumentException('Choose a country code from the list (one to three digits).');
        }
        $compact = preg_replace('/[\s\-\.\(\)\/]/', '', $number);
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }
        if (str_starts_with($compact, '+')) {
            if (! str_starts_with($compact, '+'.$code)) {
                throw new InvalidArgumentException("The number starts with a different country code than +{$code}: type it without the code, or choose its code.");
            }
            $e164 = $compact;
        } else {
            if (preg_match('/^\d+$/', $compact) !== 1) {
                throw new InvalidArgumentException('A phone number may only contain digits (spaces, dashes and brackets are fine).');
            }
            $e164 = '+'.$code.ltrim($compact, '0');
        }
        if (preg_match(self::PATTERN, $e164) !== 1) {
            throw new InvalidArgumentException("+{$code} ".substr($e164, strlen($code) + 1).' is not a valid phone number: with the country code it has 7 to 15 digits.');
        }

        return $e164;
    }

    /** Normalise one phone attribute of a model from its code attribute (model saving hooks). */
    public static function normaliseOn(Model $model, string $phone, string $code): void
    {
        $attributes = $model->getAttributes();
        $codeValue = $attributes[$code] ?? null;
        if ($codeValue === '') {
            $model->setAttribute($code, $codeValue = null);
        }
        if ($codeValue !== null) {
            $model->setAttribute($code, ltrim(trim((string) $codeValue), '+'));
        }
        if (! $model->isDirty([$phone, $code]) || ($attributes[$phone] ?? null) === null) {
            return;
        }
        $model->setAttribute($phone, self::normalise($model->getAttributes()[$code] ?? null, (string) $attributes[$phone]));
    }

    /**
     * A Laravel validation rule for a phone field whose code is $code (forms): the same check the models make.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function rule(?string $code): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($code): void {
            if (is_string($value) && trim($value) !== '' && ($problem = self::problem($code, $value)) !== null) {
                $fail($problem);
            }
        };
    }

    /** "+91 9845012345" for an E.164 number with its code, else the number as stored. */
    public static function display(?string $code, ?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }
        if ($code !== null && $code !== '' && str_starts_with($number, '+'.$code)) {
            return '+'.$code.' '.substr($number, strlen($code) + 1);
        }

        return $number;
    }
}
