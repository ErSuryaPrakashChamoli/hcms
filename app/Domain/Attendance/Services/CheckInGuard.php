<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\EmployeeDevice;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Check-in controls (HROne parity HP-1): the `checkin` policy decides which sources may punch, whether
 * web and mobile punches must carry a location and fall inside a geo location, and whether only an
 * approved device of the employee may be used. With no check-in policy nothing is checked and punches
 * behave as before. The verdict annotates the punch; a rejected punch is stored as evidence but never
 * counted.
 *
 * HROne setup parity adds, each only when the policy turns it on: web check-in from listed IP addresses or ranges,
 * a daily punch limit and a time window per channel (web; mobile and API), photos (or an app-verified face) and an
 * approved WiFi network for mobile check-ins, polygon geo locations and geo locations valid only for a period.
 */
final class CheckInGuard
{
    private const LOCATED_SOURCES = ['web', 'mobile', 'api'];

    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * @param  array<string, mixed>  $meta  latitude, longitude, device_identifier, device_platform, ip_address, wifi_ssid, wifi_bssid, photos (stored paths), face_verified
     * @return array{geo_status: ?string, attendance_geo_fence_id: ?int, geo_distance_m: ?int, device_identifier: ?string, rejected: ?string}
     */
    public function check(Employee $employee, Carbon $at, string $sourceType, array $meta): array
    {
        $device = isset($meta['device_identifier']) && $meta['device_identifier'] !== '' ? substr((string) $meta['device_identifier'], 0, 128) : null;
        $verdict = ['geo_status' => null, 'attendance_geo_fence_id' => null, 'geo_distance_m' => null, 'device_identifier' => $device, 'rejected' => null];
        $policy = $this->policies->resolve('checkin', $employee, $at);
        $latitude = isset($meta['latitude']) && $meta['latitude'] !== '' ? (float) $meta['latitude'] : null;
        $longitude = isset($meta['longitude']) && $meta['longitude'] !== '' ? (float) $meta['longitude'] : null;

        // Geo status is recorded whenever a location and a geo location exist, even without a policy.
        if (in_array($sourceType, self::LOCATED_SOURCES, true)) {
            if ($latitude === null || $longitude === null) {
                $verdict['geo_status'] = 'no_location';
            } elseif ($nearest = $this->nearest($employee, $latitude, $longitude, $at)) {
                [$fence, $distance] = $nearest;
                $verdict['attendance_geo_fence_id'] = $fence->id;
                $verdict['geo_distance_m'] = $fence->shape === 'polygon' ? $fence->distanceOutside($latitude, $longitude) : $distance;
                $verdict['geo_status'] = $fence->distanceOutside($latitude, $longitude) === 0 ? 'inside' : 'outside';
            }
        }

        if ($policy === null) {
            return $verdict;
        }

        $source = $sourceType === 'biometric_device' ? 'biometric' : $sourceType;
        $allowed = array_values(array_filter((array) ($policy->setting('allowed_sources') ?? [])));
        if ($allowed !== [] && in_array($source, ['web', 'mobile', 'api', 'biometric'], true) && ! in_array($source, $allowed, true)) {
            return ['rejected' => "Check-in from {$source} is not allowed under your check-in policy."] + $verdict;
        }

        if ($refusal = $this->channelRules($policy, $employee, $at, $sourceType, $meta)) {
            return ['rejected' => $refusal] + $verdict;
        }

        if (in_array($sourceType, ['web', 'mobile'], true)) {
            if ($policy->setting('require_location', false) && $verdict['geo_status'] === 'no_location') {
                return ['rejected' => 'Your location is needed to check in.'] + $verdict;
            }
            if ($policy->setting('geo_fence_required', false)) {
                if ($verdict['geo_status'] === null) {
                    $verdict['geo_status'] = 'outside'; // a location, but no geo location applies to the employee
                }
                if ($verdict['geo_status'] !== 'inside' && $policy->setting('outside_fence_action', 'flag') === 'reject') {
                    return ['rejected' => 'You are outside your approved check-in locations.'] + $verdict;
                }
            }
        }

        if ($policy->setting('single_device', false) && in_array($sourceType, ['web', 'mobile', 'api'], true)) {
            $refusal = $this->checkDevice($employee, $device, (string) ($meta['device_platform'] ?? ''), (bool) $policy->setting('auto_approve_first_device', true));
            if ($refusal !== null) {
                return ['rejected' => $refusal] + $verdict;
            }
        }

        return $verdict;
    }

    /**
     * HROne setup parity: the channel's time window and daily punch limit, web IP validation, and the photos, face
     * verification and WiFi network a mobile check-in must carry. Null when the punch may go on.
     *
     * @param  array<string, mixed>  $meta
     */
    private function channelRules(PolicyVersion $policy, Employee $employee, Carbon $at, string $sourceType, array $meta): ?string
    {
        $channel = match ($sourceType) {
            'web' => 'web', 'mobile', 'api' => 'mobile', default => null,
        };
        if ($channel === null) {
            return null;
        }
        $on = fn (string $key) => in_array($policy->setting($key), [true, 1, '1'], true);

        if ($on("{$channel}_window_enabled") && ($from = $policy->setting("{$channel}_window_start")) && ($to = $policy->setting("{$channel}_window_end"))) {
            $time = $at->format('H:i');
            [$from, $to] = [substr((string) $from, 0, 5), substr((string) $to, 0, 5)];
            $inside = $from <= $to ? ($time >= $from && $time <= $to) : ($time >= $from || $time <= $to); // a window may cross midnight
            if (! $inside) {
                return "Attendance can be marked only between {$from} and {$to}.";
            }
        }

        if ($on("{$channel}_max_punches_enabled") && ($max = (int) $policy->setting("{$channel}_max_punches")) > 0) {
            $sources = $channel === 'web' ? ['web'] : ['mobile', 'api'];
            $today = AttendancePunch::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
                ->whereIn('source_type', $sources)->where('processing_status', '!=', 'rejected')
                ->whereBetween('punched_at', [$at->copy()->startOfDay(), $at->copy()->endOfDay()])->count();
            if ($today >= $max) {
                return "You can mark attendance at most {$max} time(s) a day from ".($channel === 'web' ? 'the web' : 'the app').'.';
            }
        }

        if ($channel === 'web' && $on('web_ip_validation')) {
            $ip = (string) ($meta['ip_address'] ?? '');
            $allowed = array_values(array_filter(array_map('trim', (array) $policy->setting('web_allowed_ips', []))));
            if ($ip === '' || $allowed === [] || ! IpUtils::checkIp($ip, $allowed)) {
                return 'Web check-in is allowed only from your office network.';
            }
        }

        if ($channel === 'mobile') {
            $photos = in_array($policy->setting('photo_capture'), ['photo', 'facial'], true) ? max(1, (int) $policy->setting('photo_count', 1)) : 0;
            if ($photos > 0 && count(array_filter((array) ($meta['photos'] ?? []))) < $photos) {
                return $photos === 1 ? 'Take a photo to check in.' : "Take {$photos} photos to check in.";
            }
            if ($policy->setting('photo_capture') === 'facial' && ! filter_var($meta['face_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
                return 'Your face could not be verified; try again.';
            }
            if ($on('wifi_attendance')) {
                $networks = array_map('mb_strtolower', array_values(array_filter(array_map('trim', (array) $policy->setting('wifi_networks', [])))));
                $seen = array_map('mb_strtolower', array_filter([(string) ($meta['wifi_ssid'] ?? ''), (string) ($meta['wifi_bssid'] ?? '')]));
                if (array_intersect($seen, $networks) === []) {
                    return 'Connect to an approved office WiFi network to check in.';
                }
            }
        }

        return null;
    }

    /** Whether a web check-in should carry the browser's location (the employee's policy uses it). */
    public function needsLocation(Employee $employee): bool
    {
        $policy = $this->policies->resolve('checkin', $employee, now());
        $on = fn (string $key) => in_array($policy?->setting($key), [true, 1, '1'], true);

        return $policy !== null && ($on('require_location') || $on('geo_fence_required'));
    }

    /** @return array{0: GeoFence, 1: int}|null the closest fence in force that applies to the employee */
    public function nearest(Employee $employee, float $latitude, float $longitude, ?Carbon $at = null): ?array
    {
        $locationId = $employee->currentPosition?->location_id;
        $fences = GeoFence::query()->withoutGlobalScope(AccessScope::class)->validOn(($at ?? now())->toDateString())
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhere(fn ($q) => $q->whereNull('employee_id')->where(fn ($q) => $q->whereNull('location_id')->when($locationId, fn ($q) => $q->orWhere('location_id', $locationId)))))
            ->get();

        return $fences->map(fn (GeoFence $f) => [$f, $f->distanceTo($latitude, $longitude)])
            ->sortBy(fn (array $pair) => $pair[0]->distanceOutside($latitude, $longitude))->first();
    }

    private function checkDevice(Employee $employee, ?string $device, string $platform, bool $autoApproveFirst): ?string
    {
        if ($device === null) {
            return 'This device could not be identified; check in from your registered device.';
        }

        $mine = EmployeeDevice::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('device_identifier', $device)->first();
        if ($mine?->status === 'approved') {
            $mine->withoutAuditing()->update(['last_seen_at' => now()]);

            return null;
        }
        if ($mine !== null) {
            return $mine->status === 'blocked' ? 'This device is blocked for check-in.' : 'This device is waiting for approval.';
        }

        if (EmployeeDevice::query()->withoutGlobalScope(AccessScope::class)->where('device_identifier', $device)->where('employee_id', '!=', $employee->id)->where('status', 'approved')->exists()) {
            return 'This device is registered to another employee.';
        }

        $first = $autoApproveFirst && ! EmployeeDevice::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('status', 'approved')->exists();
        $registered = EmployeeDevice::create([
            'employee_id' => $employee->id, 'device_identifier' => $device, 'platform' => $platform !== '' ? substr($platform, 0, 32) : null,
            'status' => $first ? 'approved' : 'pending', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        AttendanceEvent::dispatch('attendance.device_registered', $employee, $registered, ['status' => $registered->status, 'platform' => $registered->platform]);

        return $first ? null : 'This new device is waiting for approval before it can be used to check in.';
    }
}
