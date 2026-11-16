<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends a text message through the configured provider (peopleos.sms.driver): log (no provider: the message goes to the
 * application log), msg91 (India: a DLT-approved Flow template; only the one-time code is passed, as the template's
 * variable) or twilio. Numbers are E.164 ("+919876543210"). Provider URLs are fixed, never taken from user input.
 */
final class SmsGateway
{
    public const DRIVERS = ['log', 'msg91', 'twilio'];

    public function driver(): string
    {
        $driver = (string) config('peopleos.sms.driver', 'log');

        return in_array($driver, self::DRIVERS, true) ? $driver : 'log';
    }

    /** True when codes really reach phones (a provider is configured). */
    public function delivers(): bool
    {
        return $this->driver() !== 'log';
    }

    /**
     * Send a one-time code. $text is the full message for providers that take free text (twilio, log); msg91 sends its
     * approved template with the code filled in.
     *
     * @throws RuntimeException when the provider refuses or cannot be reached (the message says so without detail)
     */
    public function sendCode(string $e164, string $code, string $text): void
    {
        try {
            match ($this->driver()) {
                'msg91' => $this->msg91($e164, $code),
                'twilio' => $this->twilio($e164, $text),
                default => Log::info('SMS (log driver, not sent): to '.self::mask($e164).': '.$text),
            };
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('The code could not be sent. Please try again in a moment.');
        }
    }

    /** "+91 98•••••210": the country code, the first two and last three digits of the number. */
    public static function mask(string $e164): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        if (strlen($digits) < 6) {
            return '•••';
        }
        $national = strlen($digits) > 10 ? substr($digits, -10) : $digits;
        $country = substr($digits, 0, strlen($digits) - strlen($national));

        return ($country !== '' ? "+{$country} " : '').substr($national, 0, 2).str_repeat('•', max(1, strlen($national) - 5)).substr($national, -3);
    }

    private function msg91(string $e164, string $code): void
    {
        $c = (array) config('peopleos.sms.msg91');
        if (blank($c['auth_key'] ?? null) || blank($c['template_id'] ?? null)) {
            throw new RuntimeException('Text messages are not set up yet.');
        }
        $response = Http::timeout(10)->acceptJson()
            ->withHeaders(['authkey' => (string) $c['auth_key']])
            ->post((string) ($c['base_url'] ?? 'https://control.msg91.com/api/v5/flow'), [
                'template_id' => (string) $c['template_id'],
                'short_url' => '0',
                'recipients' => [['mobiles' => ltrim($e164, '+'), (string) ($c['otp_var'] ?? 'otp') => $code]],
            ]);
        if (! $response->successful() || ($response->json('type') !== null && $response->json('type') !== 'success')) {
            Log::warning('MSG91 refused an OTP message', ['status' => $response->status(), 'type' => $response->json('type')]);
            throw new RuntimeException('The code could not be sent. Please try again in a moment.');
        }
    }

    private function twilio(string $e164, string $text): void
    {
        $c = (array) config('peopleos.sms.twilio');
        if (blank($c['account_sid'] ?? null) || blank($c['auth_token'] ?? null) || blank($c['from'] ?? null)) {
            throw new RuntimeException('Text messages are not set up yet.');
        }
        $sid = rawurlencode((string) $c['account_sid']);
        $response = Http::timeout(10)->asForm()->withBasicAuth((string) $c['account_sid'], (string) $c['auth_token'])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", ['To' => $e164, 'From' => (string) $c['from'], 'Body' => $text]);
        if (! $response->successful()) {
            Log::warning('Twilio refused an OTP message', ['status' => $response->status(), 'code' => $response->json('code')]);
            throw new RuntimeException('The code could not be sent. Please try again in a moment.');
        }
    }
}
