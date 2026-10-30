<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Services\CheckInGuard;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;
use Filament\Notifications\Notification;

/**
 * Check in / out from the browser (Home, My day). The page passes what the browser knows — its location when the
 * employee's check-in policy asks for one, and a device id kept in the browser — and the request's IP goes with
 * it, so the check-in policy (location, geo locations, devices, IP ranges, windows, punch limits) applies to web
 * punches as to the app's. A punch the policy refuses is kept as evidence and reported as not accepted.
 */
final class WebPunch
{
    /** Whether the browser should send its location (the employee's check-in policy uses it). */
    public static function needsLocation(?Employee $employee): bool
    {
        return $employee !== null && app(CheckInGuard::class)->needsLocation($employee);
    }

    /**
     * @param  array<string, mixed>  $client  latitude, longitude, device_identifier, location_error
     * @return bool whether the punch was accepted
     */
    public static function record(Employee $employee, string $direction, array $client = []): bool
    {
        $latitude = is_numeric($client['latitude'] ?? null) && abs((float) $client['latitude']) <= 90 ? (float) $client['latitude'] : null;
        $longitude = is_numeric($client['longitude'] ?? null) && abs((float) $client['longitude']) <= 180 ? (float) $client['longitude'] : null;
        $device = is_string($client['device_identifier'] ?? null) ? substr($client['device_identifier'], 0, 128) : null;

        $punch = app(PunchIngestion::class)->record($employee, now(), $direction, 'web', null, null, [
            'latitude' => $latitude, 'longitude' => $longitude, 'device_identifier' => $device, 'device_platform' => 'web', 'ip_address' => request()->ip(),
        ]);

        if ($punch === null) {
            Notification::make()->info()->title('Already recorded')->send();

            return false;
        }
        if ($punch->processing_status === 'rejected') {
            $hint = $latitude === null && filled($client['location_error'] ?? null) ? ' Allow this site to use your location and try again.' : '';
            Notification::make()->danger()->title(($direction === 'in' ? 'Check-in' : 'Check-out').' not accepted')->body($punch->processing_error.$hint)->persistent()->send();

            return false;
        }
        Notification::make()->success()->title($direction === 'in' ? 'Checked in' : 'Checked out')->body(now()->format('H:i'))->send();

        return true;
    }
}
