<?php

use App\Domain\Website\Models\WebsiteEnquiry;
use App\Domain\Website\Services\PhoneVerifications;
use App\Domain\Website\Services\Website;
use App\Domain\Website\Support\PersonalEmailDomains;
use App\Support\Sms\SmsGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| The website contact form: name and work e-mail are required, and the mobile number is required and verified with an
| SMS code (by default; the contact block can make it optional or leave it out). Codes are rate-limited, expire, allow five
| tries, and a verification is used once.
*/

beforeEach(function () {
    $this->operator = platformAdmin();
    $website = app(Website::class);
    $website->saveArea('site', ['enabled' => true], $this->operator);
    $website->publish($this->operator);
});

function sendWebsiteCode(string $phone = '9876543210', string $country = '+91'): array
{
    return test()->postJson('/enquiry/code', ['country' => $country, 'phone' => $phone])->assertOk()->json();
}

function websiteEnquiry(array $overrides = []): TestResponse
{
    return test()->from('/contact')->post('/enquiry', $overrides + [
        'name' => 'Priya Nair', 'email' => 'priya@example.com', 'phone_country' => '+91', 'phone' => '9876543210',
    ]);
}

it('requires the name, the work e-mail and a verified mobile number', function () {
    $this->get('/contact')->assertOk()
        ->assertSee('Work e-mail')->assertSee('Mobile number')->assertSee('Send code')
        ->assertSee('data-phone-mode="verify"', false)->assertSee('aria-required="true"', false);

    websiteEnquiry(['name' => '', 'email' => '', 'phone' => ''])->assertSessionHasErrorsIn('enquiry', ['name', 'email', 'phone']);
    websiteEnquiry()->assertSessionHasErrorsIn('enquiry', ['phone' => 'Verify your mobile number with the code we text you.']);
    expect(WebsiteEnquiry::query()->count())->toBe(0);
});

it('verifies the number with a texted code and records it as verified, once', function () {
    $sent = sendWebsiteCode('098765 43210');
    expect($sent['masked'])->toBe('+91 98•••••210')->and($sent['debug_code'])->toMatch('/^\d{6}$/');

    $wrong = $sent['debug_code'] === '000000' ? '111111' : '000000';
    $this->postJson('/enquiry/code/verify', ['token' => $sent['token'], 'code' => $wrong])->assertStatus(422)->assertJson(['message' => 'That code is not right. 4 tries left.']);
    $this->postJson('/enquiry/code/verify', ['token' => $sent['token'], 'code' => $sent['debug_code']])->assertOk()->assertJson(['verified' => true]);

    websiteEnquiry(['phone_verification' => $sent['token']])->assertRedirect('/contact#contact')->assertSessionHas('website.enquiry_sent');
    $enquiry = WebsiteEnquiry::query()->sole();
    expect($enquiry->phone)->toBe('+919876543210')->and($enquiry->phone_verified_at)->not->toBeNull();

    // The verification is used up.
    websiteEnquiry(['phone_verification' => $sent['token']])->assertSessionHasErrorsIn('enquiry', ['phone']);
    expect(WebsiteEnquiry::query()->count())->toBe(1);
});

it('refuses a verification made for another number', function () {
    $sent = sendWebsiteCode('9876543210');
    $this->postJson('/enquiry/code/verify', ['token' => $sent['token'], 'code' => $sent['debug_code']])->assertOk();

    websiteEnquiry(['phone' => '9123456780', 'phone_verification' => $sent['token']])->assertSessionHasErrorsIn('enquiry', ['phone']);
    expect(WebsiteEnquiry::query()->count())->toBe(0);
});

it('rejects invalid numbers and locks a code after five wrong tries', function () {
    $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '12345'])->assertStatus(422)->assertJson(['message' => 'Enter a valid 10-digit Indian mobile number.']);
    $this->postJson('/enquiry/code', ['country' => '+999', 'phone' => '9876543210'])->assertStatus(422);

    $sent = sendWebsiteCode();
    $wrong = $sent['debug_code'] === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $_) {
        $this->postJson('/enquiry/code/verify', ['token' => $sent['token'], 'code' => $wrong])->assertStatus(422);
    }
    $this->postJson('/enquiry/code/verify', ['token' => $sent['token'], 'code' => $sent['debug_code']])->assertStatus(422)
        ->assertJson(['message' => 'Too many wrong codes. Ask for a new one.']);
});

it('waits 30 seconds between codes and caps codes an hour', function () {
    sendWebsiteCode();
    $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '9876543210'])->assertStatus(422)->assertJsonFragment(['message' => 'A code was just sent. You can ask for another in 30 seconds.']);

    foreach (range(1, 4) as $n) {
        $this->travel(31)->seconds();
        sendWebsiteCode();
    }
    $this->travel(31)->seconds();
    $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '9876543210'])->assertStatus(422)->assertJson(['message' => 'Too many codes have been requested. Please try again in an hour.']);
});

it('never shows the code outside a local or testing server', function () {
    app()->detectEnvironment(fn () => 'production');
    $sent = app(PhoneVerifications::class)->send('+91', '9876543210', '127.0.0.1', 'PeopleOS');
    expect($sent)->not->toHaveKey('debug_code');
});

it('lets the operator make the number optional or leave it out', function () {
    $website = app(Website::class);
    $contact = $website->published()->page('contact');
    $contact['blocks'][0]['data']['phone'] = 'optional';
    $website->savePage('contact', $contact, $this->operator);
    $website->publish($this->operator);

    $this->get('/contact')->assertOk()->assertDontSee('Send code')->assertSee('(optional)');
    websiteEnquiry(['phone' => ''])->assertRedirect('/contact#contact');
    websiteEnquiry(['email' => 'b@example.com'])->assertRedirect('/contact#contact');
    expect(WebsiteEnquiry::query()->whereNotNull('phone')->sole()->phone_verified_at)->toBeNull();

    $contact['blocks'][0]['data']['phone'] = 'hidden';
    $website->savePage('contact', $contact, $this->operator);
    $website->publish($this->operator);
    $this->get('/contact')->assertOk()->assertDontSee('Mobile number');
});

it('sends codes through MSG91 or Twilio when configured', function () {
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success']), 'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    config(['peopleos.sms.driver' => 'msg91', 'peopleos.sms.msg91.auth_key' => 'k', 'peopleos.sms.msg91.template_id' => 't1']);
    $sent = app(PhoneVerifications::class)->send('+91', '9876543210', '10.0.0.1', 'PeopleOS');
    expect($sent)->not->toHaveKey('debug_code');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'control.msg91.com') && $r->hasHeader('authkey', 'k')
        && $r['template_id'] === 't1' && $r['recipients'][0]['mobiles'] === '919876543210' && preg_match('/^\d{6}$/', $r['recipients'][0]['otp']));

    config(['peopleos.sms.driver' => 'twilio', 'peopleos.sms.twilio.account_sid' => 'AC1', 'peopleos.sms.twilio.auth_token' => 'x', 'peopleos.sms.twilio.from' => '+15550000000']);
    app(PhoneVerifications::class)->send('+44', '07911 123456', '10.0.0.2', 'PeopleOS');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.twilio.com/2010-04-01/Accounts/AC1/Messages.json') && $r['To'] === '+447911123456'
        && str_contains($r['Body'], 'is your PeopleOS verification code'));
});

it('tells the visitor when the SMS provider refuses, and lets them try again at once', function () {
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error'], 400)]);
    config(['peopleos.sms.driver' => 'msg91', 'peopleos.sms.msg91.auth_key' => 'k', 'peopleos.sms.msg91.template_id' => 't1']);
    $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '9123456780'])->assertStatus(503)->assertJson(['message' => 'The code could not be sent. Please try again in a moment.']);
    // A failed send does not start the 30-second wait.
    $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '9123456780'])->assertStatus(503);
    expect(SmsGateway::mask('+919123456780'))->toBe('+91 91•••••780');
});

it('accepts only work e-mail addresses unless the operator allows personal ones', function () {
    // The form allows five sends a minute: three through it, the rest straight through the rule.
    foreach (['asha@gmail.com', 'asha@Yahoo.co.in', 'asha@mailinator.com'] as $email) {
        websiteEnquiry(['email' => $email])->assertSessionHasErrorsIn('enquiry', ['email' => PersonalEmailDomains::MESSAGE]);
    }
    foreach (['asha@hotmail.com', 'asha@outlook.in', 'asha@rediffmail.com', 'asha@icloud.com', 'asha@gmx.de'] as $email) {
        expect(PersonalEmailDomains::isPersonal($email))->toBeTrue();
    }
    $this->travel(61)->seconds();
    expect(PersonalEmailDomains::isPersonal('asha@northwind.co.in'))->toBeFalse()
        ->and(PersonalEmailDomains::isPersonal('asha@gmail.com'))->toBeTrue()
        ->and(PersonalEmailDomains::isPersonal('asha@live.com'))->toBeTrue()
        ->and(PersonalEmailDomains::parse('Acme-Mail.com, @other.in; not a domain'))->toBe(['acme-mail.com', 'other.in']);
    $this->get('/contact')->assertOk()->assertSee('data-work-email', false)->assertSee('personal e-mail such as Gmail is not accepted');

    // The operator can block more domains, or turn the rule off.
    $website = app(Website::class);
    $contact = $website->published()->page('contact');
    $contact['blocks'][0]['data']['blocked_domains'] = 'acme-mail.com';
    $website->savePage('contact', $contact, $this->operator);
    $website->publish($this->operator);
    websiteEnquiry(['email' => 'asha@acme-mail.com'])->assertSessionHasErrorsIn('enquiry', ['email']);
    $this->travel(61)->seconds();

    $contact['blocks'][0]['data']['work_email_only'] = false;
    $contact['blocks'][0]['data']['phone'] = 'optional';
    $website->savePage('contact', $contact, $this->operator);
    $website->publish($this->operator);
    websiteEnquiry(['email' => 'asha@gmail.com', 'phone' => ''])->assertRedirect('/contact#contact')->assertSessionHasNoErrors();
    expect(WebsiteEnquiry::query()->sole()->email)->toBe('asha@gmail.com');
});
