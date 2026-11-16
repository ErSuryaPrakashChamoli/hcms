{{--
    The "talk to sales" form: posts to website.enquiry (rate-limited, a hidden field for bots); shows a thank-you after.
    Name and work e-mail are always required. The mobile number follows the block's `phone` setting: verify (required and
    confirmed with an SMS code: resources/js/site.js calls website.enquiry.code / .verify), optional, or hidden.
--}}
@php
    $points = array_values(array_filter((array) ($d['points'] ?? []), fn ($p) => is_array($p) && filled($p['text'] ?? null)));
    $errors = session('errors')?->getBag('enquiry') ?? new \Illuminate\Support\MessageBag;
    $sent = session('website.enquiry_sent');
    $sizes = \App\Domain\Website\Services\Enquiries::TEAM_SIZES;
    $countries = \App\Domain\Website\Services\PhoneVerifications::COUNTRIES;
    $phoneMode = \App\Domain\Website\Support\SiteContent::phoneMode($d);
    $workOnly = \App\Domain\Website\Support\PersonalEmailDomains::workOnly($d);
    $blockedDomains = $workOnly ? [...\App\Domain\Website\Support\PersonalEmailDomains::DOMAINS, ...\App\Domain\Website\Support\PersonalEmailDomains::parse($d['blocked_domains'] ?? null)] : [];
    $preview = $preview ?? request()->routeIs('website.preview');
    $req = '<span class="ps-req" aria-hidden="true">*</span>';
@endphp
<section class="ps-section" id="contact" style="padding-top: clamp(56px, 8vw, 96px)">
    <div class="ps-wrap ps-contact">
        <div class="ps-contact-copy">
            @if (filled($d['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $d['eyebrow'] }}</p>@endif
            <h1 class="ps-h1" style="font-size: clamp(40px, 5vw, 60px)">{{ $d['heading'] ?? 'Talk to us' }}</h1>
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
            @if ($points !== [])
                <ul class="ps-checks">@foreach ($points as $p)<li>{{ svg('heroicon-m-check') }}<span>{{ $p['text'] }}</span></li>@endforeach</ul>
            @endif
        </div>
        @if ($sent)
            <div class="ps-success" role="status">
                <i>{{ svg('heroicon-o-check') }}</i>
                <h2 class="ps-h3">Message received</h2>
                <p class="ps-lead" style="margin:0">{{ $d['success'] ?? 'Thank you — we will be in touch soon.' }}</p>
            </div>
        @else
            <form class="ps-form" method="post" action="{{ route('website.enquiry') }}" novalidate data-ps-enquiry data-phone-mode="{{ $phoneMode }}"
                @if ($workOnly) data-work-email="{{ json_encode(['domains' => $blockedDomains, 'families' => \App\Domain\Website\Support\PersonalEmailDomains::FAMILIES, 'message' => \App\Domain\Website\Support\PersonalEmailDomains::MESSAGE]) }}" @endif
                data-code-url="{{ route('website.enquiry.code') }}" data-verify-url="{{ route('website.enquiry.verify') }}">
                @csrf
                <p class="ps-form-required"><span class="ps-req" aria-hidden="true">*</span> Required</p>
                <div class="ps-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                <div class="ps-field">
                    <label for="enq-name">Full name {!! $req !!}</label>
                    <input id="enq-name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" required aria-required="true" maxlength="120"
                        @if ($errors->has('name')) aria-invalid="true" aria-describedby="enq-name-error" @endif>
                    @if ($errors->has('name'))<span class="ps-field-error" id="enq-name-error">{{ $errors->first('name') }}</span>@endif
                </div>
                <div class="ps-field">
                    <label for="enq-email">Work e-mail {!! $req !!}</label>
                    <input id="enq-email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required aria-required="true" maxlength="190" placeholder="you@company.com"
                        aria-describedby="{{ $workOnly ? 'enq-email-hint ' : '' }}enq-email-error" @if ($errors->has('email')) aria-invalid="true" @endif>
                    @if ($workOnly)<span class="ps-field-hint" id="enq-email-hint">Use your company address; personal e-mail such as Gmail is not accepted.</span>@endif
                    <span class="ps-field-error" id="enq-email-error" data-ps-email-error @if (! $errors->has('email')) hidden @endif>{{ $errors->first('email') }}</span>
                </div>

                @if ($phoneMode !== 'hidden')
                    <div class="ps-field is-wide ps-mobile" data-ps-phone>
                        <label for="enq-phone">Mobile number @if ($phoneMode === 'verify'){!! $req !!}@else<span>(optional)</span>@endif</label>
                        <div class="ps-phone-row">
                            <select name="phone_country" aria-label="Country code" data-ps-country>
                                @foreach ($countries as $code => $label)<option value="{{ $code }}" @selected(old('phone_country', '+91') === $code)>{{ $label }}</option>@endforeach
                            </select>
                            <input id="enq-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel-national" value="{{ old('phone') }}" maxlength="20" placeholder="98765 43210"
                                @if ($phoneMode === 'verify') required aria-required="true" @endif data-ps-number
                                aria-describedby="enq-phone-help{{ $errors->has('phone') ? ' enq-phone-error' : '' }}" @if ($errors->has('phone')) aria-invalid="true" @endif>
                            @if ($phoneMode === 'verify')
                                <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm ps-phone-send" data-ps-send @if ($preview) disabled @endif>Send code</button>
                            @endif
                        </div>
                        @if ($phoneMode === 'verify')
                            <input type="hidden" name="phone_verification" value="" data-ps-token>
                            <div class="ps-otp" data-ps-otp hidden>
                                <label for="enq-code">Enter the {{ (int) config('peopleos.sms.otp_length', 6) }}-digit code sent to <b data-ps-masked></b></label>
                                <div class="ps-phone-row">
                                    <input id="enq-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9]*" placeholder="••••••" data-ps-code data-length="{{ (int) config('peopleos.sms.otp_length', 6) }}">
                                    <button type="button" class="ps-btn ps-btn-primary ps-btn-sm" data-ps-verify>Verify</button>
                                </div>
                                <p class="ps-otp-meta"><button type="button" class="ps-textbtn" data-ps-resend disabled>Resend code</button> <span data-ps-timer></span> · <button type="button" class="ps-textbtn" data-ps-change>Change number</button></p>
                            </div>
                            <p class="ps-verified" data-ps-verified hidden>{{ svg('heroicon-m-check-badge', 'ps-icon') }} Mobile number verified</p>
                        @endif
                        <span class="ps-field-hint" id="enq-phone-help" data-ps-phone-status role="status">{{ $phoneMode === 'verify' ? 'We will text you a code to confirm the number.' : '' }}</span>
                        @if ($errors->has('phone'))<span class="ps-field-error" id="enq-phone-error">{{ $errors->first('phone') }}</span>@endif
                    </div>
                @endif

                <div class="ps-field">
                    <label for="enq-company">Company <span>(optional)</span></label>
                    <input id="enq-company" name="company" type="text" autocomplete="organization" value="{{ old('company') }}" maxlength="160">
                </div>
                <div class="ps-field">
                    <label for="enq-team_size">Team size <span>(optional)</span></label>
                    <select id="enq-team_size" name="team_size">
                        <option value="">Choose…</option>
                        @foreach ($sizes as $value => $label)<option value="{{ $value }}" @selected(old('team_size') === $value)>{{ $label }} employees</option>@endforeach
                    </select>
                </div>
                <div class="ps-field is-wide">
                    <label for="enq-message">What would you like to see? <span>(optional)</span></label>
                    <textarea id="enq-message" name="message" maxlength="2000">{{ old('message') }}</textarea>
                </div>
                <button class="ps-btn ps-btn-primary" type="submit" @if ($preview) disabled title="Forms are off in preview" @endif>Send message {{ svg('heroicon-m-arrow-right', 'ps-icon') }}</button>
                <p class="ps-form-legal">We use these details only to reply to you.</p>
            </form>
        @endif
    </div>
</section>
