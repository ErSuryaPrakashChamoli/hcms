<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Services\PunchCorrections;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\GeoDashboard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;

/**
 * HROne parity TX-7: who may see a punch's check-in evidence — coordinates, photos, IP address and Wi-Fi network — on the
 * day drawer and through the photo link. The rule is the organisation's geo-access choice for located check-ins (Setup →
 * Access rights → Geo access, `geo_dashboard.checkins_visible_to`; owner decision D-CA9-5 / PRV-14: nobody until chosen,
 * since no screen showed punch coordinates before): HR sees the people in their access scope, managers the people who
 * report to them today. The attendance view right alone never shows it. The punch's source, device, geo status and matched
 * work location are not evidence of this kind and show to everyone who sees the day.
 */
final class CheckInEvidence
{
    public static function visibleTo(?User $viewer, Employee $employee): bool
    {
        if ($viewer === null) {
            return false;
        }

        return match (app(GeoDashboard::class)->panels($viewer)['checkins'] ?? null) {
            'hr' => app(AccessScopes::class)->allows($viewer, $employee),
            'manager' => app(PunchCorrections::class)->manages($viewer, $employee) && app(AccessScopes::class)->allows($viewer, $employee),
            default => false,
        };
    }
}
