<?php

namespace App\Domain\Assets\Support;

use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Services\Assets;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Assets\AssetResource;
use Illuminate\Support\Collection;

/**
 * HROne setup parity AS-A: "My tasks" items for assets in the employee's custody whose sub type needs an acknowledgement
 * and that are not acknowledged yet. Due after the reminder interval (asset.acknowledgement.reminder_days).
 */
final class AssetAcknowledgementTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly Assets $assets) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null || ! $user->hasPermission('asset.own')) {
            return collect();
        }
        $days = (int) app(SettingsRepository::class)->get('asset.acknowledgement.reminder_days', 3);

        return $this->assets->pendingAcknowledgements($employee)->map(fn (AssetAssignment $a) => new ExperienceTask(
            'assets', 'acknowledge', 'Acknowledge receipt: '.$a->asset->name, $a->asset->asset_tag,
            $days > 0 ? $a->assigned_on->copy()->addDays($days) : null,
            AssetResource::getUrl('view', ['record' => $a->asset_id]),
        ));
    }
}
