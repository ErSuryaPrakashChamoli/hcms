<?php

namespace App\Http\Controllers\SignIn;

use App\Domain\Identity\Services\RememberedWorkspace;
use App\Domain\Platform\Models\Tenant;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Branded sign-in: an organisation's sign-in link (Setup → Core HR → Sign-in link), e.g. in a welcome e-mail. Opening it
 * remembers the organisation on this browser and shows the sign-in page in its look from the first visit. The route is
 * signed (only links PeopleOS gave out work, so nobody can try organisation names) and signs nobody in. An organisation
 * that is not open (suspended, closed) is not remembered; the sign-in page is shown either way.
 */
class SignInLinkController extends Controller
{
    public function __invoke(string $workspace, RememberedWorkspace $remembered): RedirectResponse
    {
        $tenant = Tenant::query()->where('slug', $workspace)->first();
        if ($tenant?->isAccessible()) {
            $remembered->remember($tenant);
        }

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
    }
}
