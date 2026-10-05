<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Carbon;

/*
| UX.18: the visual-regression clock freezes time only for the disposable *_visual_showcase database outside
| production. Anywhere else the variable is ignored, so it can never change dates in a real tenant.
*/

function ux18FreezeVisualClock(): void
{
    $method = new ReflectionMethod(AppServiceProvider::class, 'freezeVisualClock');
    $method->invoke(new AppServiceProvider(app()));
}

afterEach(function () {
    Carbon::setTestNow();
});

it('freezes the clock for a *_visual_showcase database outside production', function () {
    Carbon::setTestNow();
    config(['peopleos.visual.frozen_now' => '2026-10-05 09:00:00', 'database.connections.'.config('database.default').'.database' => 'hcm_ux_visual_showcase']);

    ux18FreezeVisualClock();

    expect(now()->toDateTimeString())->toBe('2026-10-05 09:00:00');
});

it('ignores the variable for any other database', function (string $database) {
    Carbon::setTestNow();
    config(['peopleos.visual.frozen_now' => '2001-01-01 00:00:00', 'database.connections.'.config('database.default').'.database' => $database]);

    ux18FreezeVisualClock();

    expect(Carbon::hasTestNow())->toBeFalse();
})->with(['hcm', 'hcm_ux_showcase', 'peopleos_production', 'visual_showcase_backup']);

it('ignores the variable in production, whatever the database is called', function () {
    Carbon::setTestNow();
    config(['peopleos.visual.frozen_now' => '2001-01-01 00:00:00', 'database.connections.'.config('database.default').'.database' => 'hcm_ux_visual_showcase']);
    app()->detectEnvironment(fn () => 'production');

    try {
        ux18FreezeVisualClock();
        expect(Carbon::hasTestNow())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('does nothing when the variable is not set', function () {
    Carbon::setTestNow();
    config(['peopleos.visual.frozen_now' => null, 'database.connections.'.config('database.default').'.database' => 'hcm_ux_visual_showcase']);

    ux18FreezeVisualClock();

    expect(Carbon::hasTestNow())->toBeFalse();
});
