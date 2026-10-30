<?php

namespace App\Filament\Resources\LocationRequests\Pages;

use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListLocationRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LocationRequestResource::class;

    public static function registerKey(): string
    {
        return 'location_requests';
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['value' => 'pending']]],
            'approved' => ['label' => 'Approved locations', 'filters' => ['status' => ['value' => 'approved']]],
        ];
    }

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();

        return [
            // HROne parity TX-1: views and the geo location upload for the HR side; an employee's own list is unchanged.
            ...(TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['geo_locations'])] : []),
            Action::make('requestLocation')
                ->label('Request a location')
                ->icon(Heroicon::OutlinedMapPin)
                ->visible(fn () => auth()->user()->can('attendance.regularise') && $mine() !== null)
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => $mine()),
                    TextInput::make('name')->label('Place')->required()->maxLength(255),
                    TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required(),
                    TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required(),
                    TextInput::make('radius_m')->label('Radius')->numeric()->minValue(20)->maxValue(20000)->default(200)->suffix('m')->required(),
                    // HROne setup parity: a location needed only for a while (required when the check-in policy approves locations for a period).
                    DatePicker::make('valid_from')->label('Needed from')->native(false)->default(today()),
                    DatePicker::make('valid_until')->label('Needed until')->native(false)->afterOrEqual('valid_from'),
                    Textarea::make('address')->rows(2)->maxLength(500),
                    Textarea::make('reason')->required()->maxLength(500),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the attendance policy.
                    RequestChrome::route('location', fn () => $mine()),
                ])
                ->action(function (array $data) use ($mine) {
                    try {
                        $location = app(GeoLocations::class)->request($mine(), $data, $data['reason']);
                        RequestChrome::confirmed('location', $location, 'Location sent for approval');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
