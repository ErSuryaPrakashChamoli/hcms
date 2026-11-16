<?php

namespace App\Domain\Website\Services;

use App\Support\Sms\SmsGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mobile number verification for the website's contact form: a six-digit code sent by SMS (SmsGateway), checked here, and
 * a single-use verification token the enquiry carries. Visitors are anonymous, so everything is rate-limited: one code a
 * number every 30 seconds, five a number and ten an address an hour, five tries a code; codes live ten minutes and are
 * kept only as a keyed hash in the cache.
 */
final class PhoneVerifications
{
    /** Country codes offered beside the number (India first). */
    public const COUNTRIES = [
        '+91' => 'IN +91', '+971' => 'AE +971', '+966' => 'SA +966', '+65' => 'SG +65', '+60' => 'MY +60',
        '+44' => 'UK +44', '+1' => 'US +1', '+61' => 'AU +61',
    ];

    public const RESEND_SECONDS = 30;

    private const MAX_TRIES = 5;

    private const VERIFIED_MINUTES = 30;

    public function __construct(private readonly SmsGateway $sms) {}

    /** "+919876543210" from a country code and the number as typed, or null when it is not a valid mobile number. */
    public function normalise(string $country, string $number): ?string
    {
        if (! array_key_exists($country, self::COUNTRIES)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $number) ?? '';
        if ($country === '+91') {
            $digits = preg_replace('/^(0|91)(?=[6-9]\d{9}$)/', '', $digits) ?? $digits;

            return preg_match('/^[6-9]\d{9}$/', $digits) ? "+91{$digits}" : null;
        }
        $digits = ltrim($digits, '0');

        return preg_match('/^\d{6,12}$/', $digits) ? $country.$digits : null;
    }

    /**
     * Send a code to the number.
     *
     * @return array{token: string, masked: string, resend_in: int, expires_in: int, debug_code?: string}
     *
     * @throws InvalidArgumentException a message for the visitor (invalid number, too many requests)
     * @throws RuntimeException the code could not be sent
     */
    public function send(string $country, string $number, ?string $ip, string $siteName): array
    {
        $phone = $this->normalise($country, $number);
        if ($phone === null) {
            throw new InvalidArgumentException($country === '+91' ? 'Enter a valid 10-digit Indian mobile number.' : 'Enter a valid mobile number.');
        }
        $phoneKey = sha1($phone);
        $ipKey = sha1((string) $ip);
        if (RateLimiter::tooManyAttempts("website-otp-wait:{$phoneKey}", 1)) {
            throw new InvalidArgumentException('A code was just sent. You can ask for another in '.RateLimiter::availableIn("website-otp-wait:{$phoneKey}").' seconds.');
        }
        if (RateLimiter::tooManyAttempts("website-otp-phone:{$phoneKey}", 5) || RateLimiter::tooManyAttempts("website-otp-ip:{$ipKey}", 10)) {
            throw new InvalidArgumentException('Too many codes have been requested. Please try again in an hour.');
        }

        $length = max(4, min(8, (int) config('peopleos.sms.otp_length', 6)));
        $minutes = max(1, (int) config('peopleos.sms.otp_minutes', 10));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $token = Str::random(48);

        $this->sms->sendCode($phone, $code, "{$code} is your {$siteName} verification code. It expires in {$minutes} minutes. Do not share it with anyone.");

        RateLimiter::hit("website-otp-wait:{$phoneKey}", self::RESEND_SECONDS);
        RateLimiter::hit("website-otp-phone:{$phoneKey}", 3600);
        RateLimiter::hit("website-otp-ip:{$ipKey}", 3600);
        Cache::put($this->key($token), ['phone' => $phone, 'hash' => $this->hash($token, $code), 'tries' => 0, 'verified' => false], now()->addMinutes($minutes));

        $result = ['token' => $token, 'masked' => SmsGateway::mask($phone), 'resend_in' => self::RESEND_SECONDS, 'expires_in' => $minutes * 60];
        // Without a provider nothing reaches the phone: on a local or testing server the code is shown so the flow can be
        // tried. Never in any other environment.
        if (! $this->sms->delivers() && app()->environment(['local', 'testing'])) {
            $result['debug_code'] = $code;
        }

        return $result;
    }

    /** @throws InvalidArgumentException a message for the visitor */
    public function verify(string $token, string $code): void
    {
        $entry = Cache::get($this->key($token));
        if (! is_array($entry)) {
            throw new InvalidArgumentException('This code has expired. Ask for a new one.');
        }
        if ($entry['verified'] ?? false) {
            return;
        }
        if (($entry['tries'] ?? 0) >= self::MAX_TRIES) {
            Cache::forget($this->key($token));
            throw new InvalidArgumentException('Too many wrong codes. Ask for a new one.');
        }
        if (! hash_equals((string) $entry['hash'], $this->hash($token, preg_replace('/\D/', '', $code) ?? ''))) {
            $entry['tries'] = ($entry['tries'] ?? 0) + 1;
            Cache::put($this->key($token), $entry, now()->addMinutes((int) config('peopleos.sms.otp_minutes', 10)));
            $left = self::MAX_TRIES - $entry['tries'];
            throw new InvalidArgumentException($left > 0 ? "That code is not right. {$left} ".str('try')->plural($left).' left.' : 'Too many wrong codes. Ask for a new one.');
        }
        Cache::put($this->key($token), ['verified' => true] + $entry, now()->addMinutes(self::VERIFIED_MINUTES));
    }

    /** The verified number behind a token, or null (unknown, expired or not yet verified). */
    public function verifiedPhone(?string $token): ?string
    {
        if (blank($token) || ! is_string($token)) {
            return null;
        }
        $entry = Cache::get($this->key($token));

        return is_array($entry) && ($entry['verified'] ?? false) ? (string) $entry['phone'] : null;
    }

    /** A verification is used once: the enquiry that carried it uses it up. */
    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'website:phone-verification:'.hash('sha256', $token);
    }

    private function hash(string $token, string $code): string
    {
        return hash_hmac('sha256', $token.'|'.$code, (string) config('app.key'));
    }
}
