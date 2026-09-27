<?php

namespace App\Http\Controllers\Sso;

use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Services\Sso;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** /sso/{connection}/redirect and /sso/{connection}/callback (§109). */
class SsoController extends Controller
{
    public function __construct(private readonly Sso $sso, private readonly TenantContext $tenants) {}

    public function redirect(string $connection, Request $request): RedirectResponse
    {
        $conn = $this->connection($connection);
        $auth = $this->sso->authorizationRequest($conn, route('sso.callback', $conn->slug));
        $request->session()->put('sso.state', $auth['state']);
        $request->session()->put('sso.connection', $conn->id);

        return redirect()->away($auth['url']);
    }

    public function callback(string $connection, Request $request): RedirectResponse
    {
        $conn = $this->connection($connection);

        if ($request->query('error')) {
            return redirect('/admin/login')->withErrors(['email' => 'Sign-in was cancelled: '.$request->query('error_description', $request->query('error'))]);
        }
        if ($request->query('state') !== $request->session()->pull('sso.state') || (int) $request->session()->pull('sso.connection') !== $conn->id || blank($request->query('code'))) {
            return redirect('/admin/login')->withErrors(['email' => 'The sign-in request could not be verified. Please try again.']);
        }

        try {
            $identity = $this->sso->identity($conn, (string) $request->query('code'), route('sso.callback', $conn->slug));
            $user = $this->sso->resolveUser($conn, $identity);
        } catch (RuntimeException $e) {
            return redirect('/admin/login')->withErrors(['email' => $e->getMessage()]);
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    private function connection(string $slug): SsoConnection
    {
        return $this->tenants->bypass(fn () => SsoConnection::query()->with('tenant')->where('slug', $slug)->where('status', 'active')->firstOrFail());
    }
}
