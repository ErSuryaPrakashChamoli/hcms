<?php

namespace App\Http\Controllers\Website;

use App\Domain\Website\Services\Enquiries;
use App\Domain\Website\Services\PhoneVerifications;
use App\Domain\Website\Services\Website;
use App\Domain\Website\Support\PersonalEmailDomains;
use App\Domain\Website\Support\SiteContent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The public website (App\Domain\Website\Services\Website): the home page at "/", the operator's pages, the draft
 * preview for platform operators, robots.txt, the sitemap and the "talk to sales" form. Before the first publish (or
 * with the site switched off) "/" goes to the workspace sign-in as it always did and site pages are not found.
 *
 * Search engines (security invariant 118): every response stays noindex unless the operator turned on "Allow search
 * engines" for the website, and then only the published site pages say index; the workspace never does.
 */
final class SiteController extends Controller
{
    public function __construct(private readonly Website $website) {}

    public function home(): Response|RedirectResponse
    {
        if (! $this->website->isLive()) {
            return redirect('/admin');
        }

        return $this->render($this->website->published(), '', preview: false);
    }

    public function page(string $slug): Response
    {
        abort_unless($this->website->isLive(), 404);

        return $this->render($this->website->published(), $slug, preview: false);
    }

    /** Platform operators see the draft (or the live site when there is no draft) exactly as visitors would. */
    public function preview(Request $request, string $slug = ''): Response
    {
        abort_unless($request->user()?->isPlatformAdmin() ?? false, 404);

        return $this->render($this->website->draft(), $slug, preview: true);
    }

    public function robots(): Response
    {
        $site = $this->website->published();
        if (! $this->website->isLive() || ! $site->allowsIndexing()) {
            $body = "# PeopleOS is a private HR platform: nothing here is for search engines (SaaS.11).\nUser-agent: *\nDisallow: /\n";
        } else {
            $lines = ['# The public website may be indexed (Platform → Public website → Search engines); the workspace never is.', 'User-agent: *'];
            foreach ($this->website->reservedSlugs() as $segment) {
                if (! in_array($segment, ['robots.txt', 'sitemap.xml', 'favicon.ico', 'favicon.svg'], true)) {
                    $lines[] = "Disallow: /{$segment}";
                }
            }
            $lines[] = 'Allow: /';
            $lines[] = 'Sitemap: '.url('/sitemap.xml');
            $body = implode("\n", $lines)."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $site = $this->website->published();
        abort_unless($this->website->isLive() && $site->allowsIndexing(), 404);
        $updated = $this->website->live()?->published_at?->toAtomString();

        return response()->view('website.sitemap', ['slugs' => $site->publishedSlugs(), 'updated' => $updated], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function enquire(Request $request, Enquiries $enquiries, PhoneVerifications $phones): RedirectResponse
    {
        abort_unless($this->website->isLive(), 404);
        $back = url()->previous() !== url()->current() ? url()->previous() : url('/contact');
        $path = parse_url($back, PHP_URL_PATH);
        $path = is_string($path) ? $path : null;

        // A field people never see: a bot that fills it is thanked and ignored.
        if (filled($request->input('website'))) {
            return redirect()->to($back.'#contact')->with('website.enquiry_sent', true);
        }

        // The form's own rule for the mobile number (Platform → Public website → the contact block): verify (required and
        // checked with an SMS code, the default), optional or hidden. Name and work e-mail are always required.
        $contact = $this->website->published()->contactBlock($path !== null ? trim($path, '/') : null);
        $mode = SiteContent::phoneMode($contact);
        $data = $request->validateWithBag('enquiry', [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone_country' => [$mode === 'hidden' ? 'nullable' : 'required_with:phone', Rule::in(array_keys(PhoneVerifications::COUNTRIES))],
            'phone' => [$mode === 'verify' ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^[0-9()\-\s]{6,20}$/'],
            'phone_verification' => ['nullable', 'string', 'max:64'],
            'team_size' => ['nullable', Rule::in(array_keys(Enquiries::TEAM_SIZES))],
            'message' => ['nullable', 'string', 'max:2000'],
        ], ['phone.required' => 'Enter your mobile number.', 'email.required' => 'Enter your work e-mail address.'], ['team_size' => 'team size', 'phone' => 'mobile number', 'email' => 'work e-mail']);

        // Work e-mail only (the block's setting, on by default): free and throwaway providers are refused.
        if (PersonalEmailDomains::workOnly($contact) && PersonalEmailDomains::isPersonal((string) $data['email'], PersonalEmailDomains::parse($contact['blocked_domains'] ?? null))) {
            throw ValidationException::withMessages(['email' => PersonalEmailDomains::MESSAGE])->errorBag('enquiry');
        }

        $phone = null;
        $verified = false;
        if ($mode !== 'hidden' && filled($data['phone'] ?? null)) {
            $phone = $phones->normalise((string) ($data['phone_country'] ?? '+91'), (string) $data['phone']);
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number.'])->errorBag('enquiry');
            }
            $verified = $phones->verifiedPhone($data['phone_verification'] ?? null) === $phone;
            if ($mode === 'verify' && ! $verified) {
                throw ValidationException::withMessages(['phone' => 'Verify your mobile number with the code we text you.'])->errorBag('enquiry');
            }
        }

        $enquiries->submit(['phone' => $phone, 'phone_verified' => $verified] + $data + ['interest' => $request->input('interest')], $request->ip(), $path);
        if ($verified) {
            $phones->consume((string) $data['phone_verification']);
        }

        return redirect()->to($back.'#contact')->with('website.enquiry_sent', true);
    }

    /** Text a one-time code to the visitor's mobile number (the contact form, before it is sent). */
    public function sendCode(Request $request, PhoneVerifications $phones): JsonResponse
    {
        abort_unless($this->website->isLive() || ($request->user()?->isPlatformAdmin() ?? false), 404);
        $data = $request->validate(['country' => ['required', 'string', 'max:5'], 'phone' => ['required', 'string', 'max:20']]);
        try {
            return response()->json($phones->send((string) $data['country'], (string) $data['phone'], $request->ip(), $this->website->published()->name()));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /** Check the code the visitor typed. */
    public function verifyCode(Request $request, PhoneVerifications $phones): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'code' => ['required', 'string', 'max:12']]);
        try {
            $phones->verify((string) $data['token'], (string) $data['code']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['verified' => true]);
    }

    private function render(SiteContent $site, string $slug, bool $preview): Response
    {
        $page = $site->page($slug, includeHidden: $preview);
        abort_if($page === null, 404);

        $headers = [];
        if (! $preview && $site->allowsIndexing()) {
            $headers['X-Robots-Tag'] = 'index, follow';
        }
        if ($preview) {
            $headers['Cache-Control'] = 'no-store';
        }

        return response()->view('website.page', ['site' => $site, 'page' => $page, 'preview' => $preview, 'slug' => trim($slug, '/')], 200, $headers);
    }
}
