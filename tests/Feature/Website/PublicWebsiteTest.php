<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Website\Enums\EnquiryStatus;
use App\Domain\Website\Enums\RevisionStatus;
use App\Domain\Website\Models\WebsiteEnquiry;
use App\Domain\Website\Models\WebsiteRevision;
use App\Domain\Website\Services\Website;
use App\Domain\Website\Support\SiteContent;
use App\Domain\Website\Support\SiteDefaults;
use App\Domain\Website\Support\SiteLink;
use App\Filament\Pages\PlatformWebsitePage;
use Livewire\Livewire;

/*
| Public website (Platform → Public website): a platform-owned draft that operators preview and publish. Until the first
| publish "/" keeps going to the workspace sign-in; robots.txt keeps the whole platform out of search engines unless an
| operator allows indexing of the published site pages.
*/

beforeEach(function () {
    $this->operator = platformAdmin();
    $this->website = app(Website::class);
});

function publishWebsite(?callable $change = null): void
{
    $operator = test()->operator;
    $website = app(Website::class);
    $website->saveArea('site', ['enabled' => true], $operator);
    if ($change !== null) {
        $change($website, $operator);
    }
    $website->publish($operator, 'Test publish');
}

it('keeps "/" on the workspace sign-in and site pages unknown until the website is published', function () {
    $this->get('/')->assertRedirect('/admin');
    $this->get('/pricing')->assertNotFound();
    $this->get('/sitemap.xml')->assertNotFound();
    $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /", false);
});

it('serves the published home page with its menus, mega-menu links and footer', function () {
    publishWebsite();

    $this->get('/')->assertOk()
        ->assertSee('The operating system for', false)
        ->assertSee('everyone at work.')
        ->assertSee('data-ps-menu', false)
        ->assertSee('Payroll')->assertSee('Guided runs, payslips, structures')
        ->assertSee('href="/product/payroll"', false)
        ->assertSee('Book a demo')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('renders every default page without errors', function () {
    publishWebsite();

    foreach (app(Website::class)->published()->publishedSlugs() as $slug) {
        $this->get('/'.$slug)->assertOk();
    }
    expect(count(SiteDefaults::pages()))->toBeGreaterThan(30);
});

it('links every default menu and footer path to a page that exists', function () {
    $site = new SiteContent(SiteDefaults::content());
    $slugs = $site->publishedSlugs();
    $paths = [];
    foreach ($site->menus() as $menu) {
        $paths[] = $menu['url'] ?? '';
        foreach ($menu['columns'] ?? [] as $column) {
            foreach ($column['links'] as $link) {
                $paths[] = $link['url'];
            }
        }
        $paths[] = $menu['feature']['cta_url'] ?? '';
        $paths[] = $menu['footer_link_url'] ?? '';
    }
    foreach ($site->section('footer')['columns'] as $column) {
        foreach ($column['links'] as $link) {
            $paths[] = $link['url'];
        }
    }
    foreach ($site->pages() as $page) {
        foreach ($page['blocks'] as $block) {
            foreach (['primary_url', 'secondary_url', 'cta_url'] as $key) {
                $paths[] = $block['data'][$key] ?? '';
            }
            foreach ($block['data']['items'] ?? [] as $item) {
                $paths[] = $item['url'] ?? '';
            }
        }
    }

    $missing = collect($paths)->filter(fn ($p) => is_string($p) && str_starts_with($p, '/') && ! str_starts_with($p, '/admin'))
        ->map(fn ($p) => trim($p, '/'))->unique()->reject(fn ($p) => in_array($p, $slugs, true))->values()->all();

    expect($missing)->toBe([]);
});

it('never shows draft changes to visitors before publish, and shows them after', function () {
    publishWebsite();
    $this->website->saveArea('announcement', ['enabled' => true, 'text' => 'Draft-only announcement'], $this->operator);

    $this->get('/')->assertOk()->assertDontSee('Draft-only announcement');
    expect($this->website->changedAreas())->toBe(['Announcement bar']);

    $this->website->publish($this->operator);
    $this->get('/')->assertOk()->assertSee('Draft-only announcement');
    expect(WebsiteRevision::query()->where('status', RevisionStatus::Archived->value)->count())->toBe(1)
        ->and($this->website->draftRevision())->toBeNull();
});

it('lets platform operators preview the draft, including hidden pages, but nobody else', function () {
    $this->website->savePage(null, ['slug' => 'secret-launch', 'title' => 'Launch', 'status' => 'hidden', 'blocks' => [
        ['type' => 'hero', 'data' => ['title' => 'Coming soon', 'tone' => 'light', 'visual' => 'none']],
    ]], $this->operator);

    $this->get('/site-preview/secret-launch')->assertRedirect('/login');
    $tenant = provisionTenant();
    $this->actingAs(tenantUser($tenant, ['*']))->get('/site-preview/secret-launch')->assertNotFound();

    $this->actingAs($this->operator)->get('/site-preview/secret-launch')
        ->assertOk()->assertSee('Coming soon')->assertSee('Preview')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    publishWebsite();
    $this->get('/secret-launch')->assertNotFound();
});

it('refuses page addresses that would shadow the workspace or another page', function () {
    expect(fn () => $this->website->savePage(null, ['slug' => 'admin/settings', 'title' => 'x', 'blocks' => []], $this->operator))
        ->toThrow(InvalidArgumentException::class, 'used by the workspace')
        ->and(fn () => $this->website->savePage(null, ['slug' => 'api', 'title' => 'x', 'blocks' => []], $this->operator))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->website->savePage(null, ['slug' => 'Bad Slug!', 'title' => 'x', 'blocks' => []], $this->operator))
        ->toThrow(InvalidArgumentException::class, 'lower-case')
        ->and(fn () => $this->website->savePage(null, ['slug' => 'pricing', 'title' => 'x', 'blocks' => []], $this->operator))
        ->toThrow(InvalidArgumentException::class, 'already uses /pricing');
});

it('keeps workspace routes ahead of site pages', function () {
    publishWebsite();
    $this->get('/admin/login')->assertOk()->assertSee('Sign in');
    $this->get('/up')->assertOk();
});

it('escapes operator text and drops unsafe links', function () {
    publishWebsite(function (Website $website, $operator) {
        $website->savePage('', ['slug' => '', 'title' => 'Home', 'status' => 'published', 'blocks' => [
            ['type' => 'hero', 'data' => ['title' => '<script>alert(1)</script>', 'primary_label' => 'Go', 'primary_url' => 'javascript:alert(1)', 'tone' => 'ink', 'visual' => 'none']],
            ['type' => 'text', 'data' => ['heading' => 'Notes', 'body' => '**Bold** <img src=x onerror=alert(1)> [x](javascript:alert(2))']],
        ]], $operator);
    });

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('<strong>Bold</strong>');

    expect(SiteLink::href('//evil.example'))->toBe('#')
        ->and(SiteLink::href('data:text/html,x'))->toBe('#')
        ->and(SiteLink::href('/product/payroll'))->toBe('/product/payroll')
        ->and(SiteLink::href('https://example.com/a'))->toBe('https://example.com/a')
        ->and(SiteLink::href('mailto:sales@example.com'))->toBe('mailto:sales@example.com');
});

it('lets search engines in only when the operator allows it, and never into the workspace', function () {
    publishWebsite(fn (Website $website, $operator) => $website->saveArea('seo', ['allow_indexing' => true], $operator));

    $this->get('/pricing')->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
    $robots = $this->get('/robots.txt')->assertOk()->getContent();
    expect($robots)->toContain('Disallow: /admin')->toContain('Disallow: /api')->toContain('Allow: /')->toContain('Sitemap:')
        ->and($robots)->not->toContain("Disallow: /\n");
    $this->get('/sitemap.xml')->assertOk()->assertSee(url('/product/payroll'), false);
    $this->get('/admin/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('switches the site off without unpublishing it', function () {
    publishWebsite(fn (Website $website, $operator) => $website->saveArea('site', ['enabled' => false], $operator));

    $this->get('/')->assertRedirect('/admin');
    $this->get('/pricing')->assertNotFound();
});

it('records enquiries from the contact form, ignoring bots, and lets operators work them', function () {
    publishWebsite();

    $code = $this->postJson('/enquiry/code', ['country' => '+91', 'phone' => '98765 43210'])->assertOk()->json();
    $this->postJson('/enquiry/code/verify', ['token' => $code['token'], 'code' => $code['debug_code']])->assertOk();
    $this->from('/contact')->post('/enquiry', ['name' => 'Priya N', 'email' => 'priya@example.com', 'company' => 'Example Co', 'team_size' => '51-200', 'message' => 'Demo please',
        'phone_country' => '+91', 'phone' => '98765 43210', 'phone_verification' => $code['token']])
        ->assertRedirect('/contact#contact');
    $this->from('/contact')->post('/enquiry', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example'])->assertRedirect();
    $this->from('/contact')->post('/enquiry', ['name' => '', 'email' => 'not-an-email'])->assertSessionHasErrorsIn('enquiry', ['name', 'email']);

    $enquiry = WebsiteEnquiry::query()->sole();
    expect($enquiry->email)->toBe('priya@example.com')->and($enquiry->source_path)->toBe('/contact')
        ->and($enquiry->ip_hash)->not->toBe('127.0.0.1')->and($enquiry->status)->toBe(EnquiryStatus::New);

    $this->get('/contact')->assertOk();
    $this->actingAs($this->operator);
    Livewire::test(PlatformWebsitePage::class, ['area' => 'enquiries'])
        ->assertSee('Priya N')
        ->callAction('enquiryStatus', ['status' => 'contacted'], ['enquiry' => $enquiry->id])
        ->assertNotified('Enquiry updated');
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Contacted);
});

it('is a platform-only editor that saves to the draft, publishes and restores, all audited', function () {
    $tenant = provisionTenant();
    $this->actingAs(tenantUser($tenant, ['*']))->get(PlatformWebsitePage::getUrl())->assertForbidden();

    $this->actingAs($this->operator)->get(PlatformWebsitePage::getUrl())->assertOk()->assertSee('Public website')->assertSee('Not live');

    Livewire::test(PlatformWebsitePage::class)
        ->callAction('editAnnouncement', ['enabled' => true, 'badge' => 'Hi', 'text' => 'Fresh news', 'link_label' => '', 'link_url' => ''])
        ->assertNotified('Announcement bar saved to the draft')
        ->callAction('addMenu', ['label' => 'Resources', 'url' => '/about', 'columns' => [], 'feature' => ['enabled' => false]])
        ->assertNotified('Menu added to the draft')
        ->callAction('editPage', ['title' => 'Pricing and plans', 'slug' => 'pricing', 'status' => 'published'], ['slug' => 'pricing'])
        ->assertNotified('Page saved to the draft')
        ->callAction('publish', ['note' => 'First launch'])
        ->assertNotified('The website is published');

    $site = $this->website->published();
    expect($site->section('announcement')['text'])->toBe('Fresh news')
        ->and(collect($site->menus())->pluck('label')->last())->toBe('Resources')
        ->and($site->page('pricing')['title'])->toBe('Pricing and plans')
        ->and($site->page('pricing')['blocks'])->not->toBeEmpty();
    $this->get('/')->assertOk()->assertSee('Fresh news')->assertSee('Resources');

    // Change, publish, then restore the first version into the draft.
    $this->website->saveArea('announcement', ['text' => 'Second news'], $this->operator);
    $this->website->publish($this->operator);
    $first = WebsiteRevision::query()->where('note', 'First launch')->sole();
    Livewire::test(PlatformWebsitePage::class, ['area' => 'history'])->callAction('restore', [], ['revision' => $first->id]);
    expect($this->website->draft()->section('announcement')['text'])->toBe('Fresh news')
        ->and($this->website->published()->section('announcement')['text'])->toBe('Second news');

    $events = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'website')->get();
    expect($events->count())->toBeGreaterThanOrEqual(6)
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$this->operator->id]);
});

it('reorders and removes menus, and refuses to remove the home page', function () {
    $labels = fn () => collect($this->website->draft()->menus())->pluck('label')->all();
    expect($labels())->toBe(['Product', 'Solutions', 'AI for HR', 'Pricing', 'Resources', 'Tools']);

    $this->website->moveMenu(3, -1, $this->operator);
    expect($labels())->toBe(['Product', 'Solutions', 'Pricing', 'AI for HR', 'Resources', 'Tools']);
    $this->website->deleteMenu(5, $this->operator);
    expect($labels())->toBe(['Product', 'Solutions', 'Pricing', 'AI for HR', 'Resources']);

    expect(fn () => $this->website->deletePage('', $this->operator))->toThrow(InvalidArgumentException::class, 'home page');
});

it('offers free letter generators and calculators that work in the browser', function () {
    publishWebsite();

    $this->get('/tools')->assertOk()->assertSee('Offer letter')->assertSee('Gratuity calculator');
    $this->get('/tools/offer-letter')->assertOk()
        ->assertSee('data-ps-letter', false)
        ->assertSee('data-field="candidate_name"', false)
        ->assertSee('data-field="joining_date"', false)
        ->assertSee('type="date"', false)
        ->assertSee('Stays in your browser');
    foreach (['gratuity-calculator' => 'gratuity', 'ctc-calculator' => 'ctc', 'leave-encashment-calculator' => 'leave_encashment', 'notice-period-calculator' => 'notice'] as $slug => $kind) {
        $this->get("/tools/{$slug}")->assertOk()->assertSee('data-ps-calc="'.$kind.'"', false);
    }
    $this->get('/ai')->assertOk()->assertSee('Secrets never leave');
    $this->get('/resources/glossary')->assertOk()->assertSee('Cost to company (CTC)');
    $this->get('/')->assertOk()->assertSee('href="/admin/login"', false)->assertSee('Log in');
});

it('gives the sign-in page the published copy, notice and help link', function () {
    $this->get('/admin/login')->assertOk()->assertSee('One operating system.');

    publishWebsite(fn (Website $website, $operator) => $website->saveArea('sign_in', [
        'headline' => 'Hello there.', 'highlight' => 'Welcome back to work.', 'text' => 'Short lead',
        'notice_enabled' => true, 'notice_tone' => 'warning', 'notice_text' => 'Maintenance on Sunday 02:00–03:00 IST',
        'help_label' => 'Need help?', 'help_url' => 'javascript:alert(1)',
        'apps_enabled' => true, 'play_store_url' => 'https://play.google.com/store/apps/details?id=x', 'app_store_url' => 'javascript:alert(2)',
    ], $operator));

    $html = $this->get('/admin/login')->assertOk()
        ->assertSee('Welcome back to work.')->assertSee('Maintenance on Sunday 02:00–03:00 IST')
        ->assertSee('Google Play')->assertDontSee('App Store')->assertDontSee('Need help?')
        ->assertSee('Back to the website')
        ->getContent();
    expect($html)->not->toContain('javascript:alert');
});
