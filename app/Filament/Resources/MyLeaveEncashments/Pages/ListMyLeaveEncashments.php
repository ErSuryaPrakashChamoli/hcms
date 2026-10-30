<?php

namespace App\Filament\Resources\MyLeaveEncashments\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Filament\Resources\MyLeaveEncashments\MyLeaveEncashmentResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListMyLeaveEncashments extends PeopleListRecords
{
    protected static string $resource = MyLeaveEncashmentResource::class;

    /** Ask to encash leave; the policy's encashment rules and the balance are checked. */
    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();

        return [
            Action::make('requestEncashment')
                ->label('Request encashment')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => $mine()),
                    Select::make('leave_type_id')->label('Leave type')->options(fn () => LeaveType::query()->where('is_encashable', true)->where('status', 'active')->orderBy('name')->pluck('name', 'id'))->required()->native(false),
                    TextInput::make('days')->numeric()->minValue(0.5)->step(0.5)->required(),
                    Textarea::make('reason')->maxLength(255),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the leave policy.
                    RequestChrome::route('leave_encashment', fn () => $mine()),
                ])
                ->action(function (array $data) use ($mine) {
                    try {
                        $encashment = app(LeaveAdjustments::class)->requestEncashment($mine(), LeaveType::query()->findOrFail($data['leave_type_id']), (float) $data['days'], $data['reason'] ?? null);
                        RequestChrome::confirmed('leave_encashment', $encashment, 'Encashment requested');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
