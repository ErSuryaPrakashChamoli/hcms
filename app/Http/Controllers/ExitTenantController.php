<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Services\PlatformTenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Platform operators end their controlled access to a tenant (SaaS.2: audited on both chains). */
class ExitTenantController extends Controller
{
    public function __invoke(Request $request, PlatformTenantAccess $access): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), 403);

        $access->end($request->user(), $request->session(), 'exit');

        return redirect()->to(filament()->getHomeUrl());
    }
}
