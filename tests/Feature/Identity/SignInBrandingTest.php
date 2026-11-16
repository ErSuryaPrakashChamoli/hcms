<?php

use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Identity\Services\RememberedWorkspace;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Auth\Login;
use App\Filament\Pages\SetupCentre;
use App\Filament\Support\SignInBranding;
use App\Filament\Support\TenantBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Branded sign-in (HROne-style): after someone of an organisation signs in, the browser remembers the organisation and its
| sign-in page shows the organisation's logo, name, colour and sign-in picture; any other browser sees the PeopleOS page.
*/

beforeEach(function () {
    Storage::fake(config('peopleos.documents.disk'));
    $this->tenant = provisionTenant('Northwind Advisory');
    $this->cookie = (string) $this->tenant->id;
});

/** Stores a real image as a Setup file of the bound organisation and points the setting at it. */
function signInBrandingImage(string $key, string $name): string
{
    $path = app(SettingFiles::class)->directory().'/'.$name;
    $image = UploadedFile::fake()->image($name, 40, 40); // held, or its temporary file is gone before it is read
    Storage::disk(SettingFiles::disk())->put($path, (string) file_get_contents($image->getRealPath()));
    app(SettingsRepository::class)->set($key, $path, 'Branded sign-in test');

    return $path;
}

it('remembers the organisation on a sign-in of its people, and never for platform operators', function () {
    $person = tenantUser($this->tenant, ['employee.self']);

    Livewire::test(Login::class)->set('data.email', $person->email)->set('data.password', 'password')->call('authenticate')->assertHasNoErrors();
    expect(Cookie::queued(RememberedWorkspace::COOKIE)?->getValue())->toBe($this->cookie)
        ->and(Cookie::queued(RememberedWorkspace::COOKIE)?->getExpiresTime())->toBeGreaterThan(now()->addDays(360)->timestamp);

    Cookie::unqueue(RememberedWorkspace::COOKIE);
    auth()->logout();
    $operator = platformAdmin();
    Livewire::test(Login::class)->set('data.email', $operator->email)->set('data.password', 'password')->call('authenticate');
    expect(Cookie::hasQueued(RememberedWorkspace::COOKIE))->toBeFalse();
});

it('shows the PeopleOS sign-in page to a browser that remembers no organisation, or a cookie it did not issue', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Sign in to PeopleOS')->assertSee('One operating system.')->assertDontSee('Northwind');

    // A cookie written by hand (not encrypted by PeopleOS) is ignored, so nobody can pick another organisation's page.
    $this->withUnencryptedCookie(RememberedWorkspace::COOKIE, $this->cookie)->get('/admin/login')->assertOk()->assertDontSee('Northwind');
});

it('shows the remembered organisation by name, with its logo, picture and colour, and serves its images to that browser', function () {
    actAsTenant($this->tenant);
    signInBrandingImage('branding.logo_path', 'logo.png');
    signInBrandingImage('branding.sign_in_picture_path', 'picture.jpg');
    app(SettingsRepository::class)->set('branding.theme_colour', '#0f766e', 'Brand');
    actAsTenant(null);

    $this->withCookie(RememberedWorkspace::COOKIE, $this->cookie)->get('/admin/login')->assertOk()
        ->assertSee('Sign in to Northwind Advisory')->assertSee('Not Northwind Advisory? Use another organisation')
        ->assertSee('/sign-in/brand/logo?v=', false)->assertSee('/sign-in/brand/picture?v=', false)
        ->assertDontSee('One operating system.');
    $this->get(route('sign-in.brand', ['asset' => 'logo']))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Vary', 'Cookie');
    $this->get(route('sign-in.brand', ['asset' => 'picture']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // Signed out, the other pages (and the workspace colour) follow the remembered organisation too.
    request()->cookies->set(RememberedWorkspace::COOKIE, $this->cookie);
    expect(TenantBranding::themeColour())->toBe('#0f766e')
        ->and(TenantBranding::logoUrl())->toContain('/sign-in/brand/logo');
});

it('uses the display name, and the logo or initials where no picture was chosen', function () {
    actAsTenant($this->tenant);
    app(SettingsRepository::class)->set('branding.display_name', 'Northwind', 'Brand');
    actAsTenant(null);
    $this->withCookie(RememberedWorkspace::COOKIE, $this->cookie)->get('/admin/login')->assertOk()
        ->assertSee('Sign in to Northwind')->assertSee('pos-signin-stage-monogram', false)->assertDontSee('/sign-in/brand/', false);

    actAsTenant($this->tenant);
    signInBrandingImage('branding.logo_path', 'logo.png');
    actAsTenant(null);
    $this->get('/admin/login')->assertOk()->assertSee('pos-signin-stage-logo', false)->assertDontSee('/sign-in/brand/picture', false);
});

it('serves the images only for the organisation the browser remembers, while it is open and branded', function () {
    actAsTenant($this->tenant);
    $logo = signInBrandingImage('branding.logo_path', 'logo.png');
    actAsTenant(null);
    $logoUrl = route('sign-in.brand', ['asset' => 'logo']);

    // No cookie, or another organisation's cookie: that organisation has no logo, and the link names no organisation.
    $this->get($logoUrl)->assertNotFound();
    $other = provisionTenant('Other Org');
    actAsTenant($other);
    app(SettingsRepository::class)->set('branding.logo_path', $logo, 'Pointing at another organisation\'s file');
    actAsTenant(null);
    $this->withCookie(RememberedWorkspace::COOKIE, (string) $other->id)->get($logoUrl)->assertNotFound();

    $this->withCookie(RememberedWorkspace::COOKIE, $this->cookie)->get($logoUrl)->assertOk();
    $this->get(route('sign-in.brand', ['asset' => 'logo']).'/../picture')->assertNotFound();

    // Signed in: the workspace has its own (signed, audited) logo link; this one is for the signed-out page only.
    $this->actingAs(tenantUser($this->tenant))->get($logoUrl)->assertNotFound();
    auth()->logout();

    // Switched off: the PeopleOS page, and no images.
    actAsTenant($this->tenant);
    app(SettingsRepository::class)->set('branding.sign_in_page', false, 'Off');
    actAsTenant(null);
    $this->get('/admin/login')->assertOk()->assertSee('Sign in to PeopleOS')->assertDontSee('Northwind');
    $this->get($logoUrl)->assertNotFound();

    // A suspended organisation is not shown either.
    actAsTenant($this->tenant);
    app(SettingsRepository::class)->set('branding.sign_in_page', true, 'On');
    actAsTenant(null);
    $this->tenant->update(['status' => TenantStatus::Suspended]);
    $this->get('/admin/login')->assertOk()->assertDontSee('Northwind');
    $this->get($logoUrl)->assertNotFound();
});

it('remembers the organisation from its signed sign-in link only', function () {
    $this->get(RememberedWorkspace::signInLink($this->tenant))->assertRedirect('/admin/login')
        ->assertCookie(RememberedWorkspace::COOKIE, $this->cookie);

    // Unsigned or edited links are refused, so nobody can try organisation names.
    $this->get(route('sign-in.link', ['workspace' => $this->tenant->slug]))->assertForbidden();
    $other = provisionTenant('Other Org');
    $this->get(str_replace($this->tenant->slug, $other->slug, RememberedWorkspace::signInLink($this->tenant)))->assertForbidden();

    // A suspended organisation's link still opens the sign-in page, without remembering it. (The test's cookie jar
    // outlives requests, so the cookie queued by the first one is dropped here.)
    Cookie::unqueue(RememberedWorkspace::COOKIE);
    $this->tenant->update(['status' => TenantStatus::Suspended]);
    $this->get(RememberedWorkspace::signInLink($this->tenant))->assertRedirect('/admin/login')->assertCookieMissing(RememberedWorkspace::COOKIE);
});

it('forgets the organisation from the sign-in page ("Not … ? Use another organisation")', function () {
    Livewire::withCookie(RememberedWorkspace::COOKIE, $this->cookie)->test(Login::class)
        ->assertSee('Sign in to Northwind Advisory')
        ->call('forgetWorkspace')->assertRedirect('/admin/login');

    expect(Cookie::queued(RememberedWorkspace::COOKIE)?->isCleared())->toBeTrue();
});

it('offers the organisation\'s sign-in link on Setup → Core HR, with the sign-in page settings', function () {
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['settings.view']));

    Livewire::test(SetupCentre::class)->assertActionVisible('signInLink')
        ->assertSee('Show your organisation on the sign-in page')->assertSee('Sign-in page picture')
        ->call('selectModule', 'payroll')->assertActionHidden('signInLink');
    expect(RememberedWorkspace::signInLink($this->tenant))->toContain('/sign-in/link/'.$this->tenant->slug.'?signature=')
        ->and(SignInBranding::ASSETS)->toBe(['logo' => 'branding.logo_path', 'picture' => 'branding.sign_in_picture_path']);
});
