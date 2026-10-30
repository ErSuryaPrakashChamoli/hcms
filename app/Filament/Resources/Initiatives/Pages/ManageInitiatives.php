<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\InitiativeFeedback;
use App\Domain\Performance\Services\Initiatives;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PerformanceRegisters;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** HROne setup parity HP-8j: initiatives — mine, my team's, asked for my feedback, and (HR) all in scope. */
class ManageInitiatives extends PeopleManageRecords
{
    protected static string $resource = InitiativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Create an initiative')
                ->using(fn (array $data) => app(Initiatives::class)->create(Employee::query()->findOrFail($data['employee_id']), $data, auth()->user())),
            // HROne parity GO-1: the performance panel's actions menu for performance HR (hidden for everyone else).
            PerformanceRegisters::actionsMenu(),
        ];
    }

    public function getTabs(): array
    {
        $user = auth()->user();
        $relationships = app(PerformanceRelationships::class);
        $me = $relationships->forUser($user);
        $tabs = [];
        if (InitiativeResource::isHr($user)) {
            $tabs['all'] = Tab::make('All');
        }
        if ($me !== null) {
            $tabs['mine'] = Tab::make('Mine')->modifyQueryUsing(fn (Builder $query) => $query->where('employee_id', $me->id));
            $reports = $user->hasPermission('performance.initiatives') ? $relationships->reportIds($me)->all() : [];
            if ($reports !== []) {
                $tabs['team'] = Tab::make('My team')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('employee_id', $reports));
            }
            $tabs['feedback'] = Tab::make('Asked for my feedback')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', InitiativeFeedback::query()->where('reviewer_id', $me->id)->select('initiative_id')))
                ->badge(fn () => InitiativeFeedback::query()->where('reviewer_id', $me->id)->where('status', 'requested')->count() ?: null);
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return InitiativeResource::isHr() ? 'all' : 'mine';
    }
}
