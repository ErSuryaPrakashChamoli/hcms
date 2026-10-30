<?php

namespace App\Filament\Resources\LeaveDonations\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveDonations;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\LeaveDonations\LeaveDonationResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListLeaveDonations extends PeopleListRecords
{
    protected static string $resource = LeaveDonationResource::class;

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();

        return [
            Action::make('donate')
                ->label('Donate leave')
                ->icon(Heroicon::OutlinedGift)
                ->visible(fn () => auth()->user()->can('leave.apply') && $mine() !== null)
                ->schema([
                    Select::make('recipient_employee_id')->label('To')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    Select::make('leave_type_id')->label('Leave')->required()->native(false)
                        ->options(fn () => LeaveType::query()->whereIn('code', (array) app(SettingsRepository::class)->get('leave.donation.allowed_types', ['EL']))->pluck('name', 'id')),
                    TextInput::make('days')->numeric()->minValue(0.5)->step(0.5)->required(),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) use ($mine) {
                    try {
                        app(LeaveDonations::class)->request($mine(), Employee::query()->findOrFail($data['recipient_employee_id']), LeaveType::query()->findOrFail($data['leave_type_id']), (float) $data['days'], $data['reason']);
                        Notification::make()->success()->title('Donation sent for approval')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
