<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Platform admins leave the tenant they entered from the Tenants list. */
class ExitTenantController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), 403);

        $request->session()->forget(ResolveTenant::SESSION_KEY);

        return redirect()->to(filament()->getHomeUrl());
    }
}
