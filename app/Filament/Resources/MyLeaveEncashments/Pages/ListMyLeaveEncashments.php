<?php

namespace App\Filament\Resources\MyLeaveEncashments\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Filament\Resources\MyLeaveEncashments\MyLeaveEncashmentResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
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
                    Select::make('leave_type_id')->label('Leave type')->options(fn () => LeaveType::query()->where('is_encashable', true)->where('status', 'active')->orderBy('name')->pluck('name', 'id'))->required()->native(false)
                        ->live(),
                    // HROne parity RQ-4 (§20.1.18 Encashment #3): the balance available and the leave type's encashment rule.
                    Text::make(fn (Get $get) => self::renderAllowance($mine(), $get('leave_type_id')))->visible(fn (Get $get) => filled($get('leave_type_id'))),
                    TextInput::make('days')->numeric()->minValue(0.5)->step(0.5)->required(),
                    Textarea::make('reason')->maxLength(255),
                    // HROne parity RQ-4 (§20.1.18 Encashment #5): a supporting file, required when the leave type's rule says so.
                    FileUpload::make('attachments')->label('Supporting file')->multiple()->maxFiles(LeaveAdjustments::MAX_FILES)
                        ->disk(config('peopleos.documents.disk', 'local'))->directory(rtrim(LeaveAdjustments::UPLOAD_AREA, '/'))->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                        ->storeFileNamesIn('attachment_names')
                        ->required(fn (Get $get) => self::needsFile($mine(), $get('leave_type_id')))
                        ->helperText(fn (Get $get) => self::needsFile($mine(), $get('leave_type_id')) ? 'Required for this leave type.' : 'Optional.'),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the leave policy.
                    RequestChrome::route('leave_encashment', fn () => $mine()),
                ])
                ->action(function (array $data) use ($mine) {
                    try {
                        $encashment = app(LeaveAdjustments::class)->requestEncashment($mine(), LeaveType::query()->findOrFail($data['leave_type_id']), (float) $data['days'], $data['reason'] ?? null,
                            attachments: array_values((array) ($data['attachments'] ?? [])), names: (array) ($data['attachment_names'] ?? []));
                        RequestChrome::confirmed('leave_encashment', $encashment, 'Encashment requested');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    /** HROne parity RQ-4: whether the leave type's encashment rule asks for a supporting file. */
    public static function needsFile(?Employee $employee, mixed $typeId): bool
    {
        $type = $employee !== null && filled($typeId) ? LeaveType::query()->find($typeId) : null;

        return $type !== null && app(LeaveAdjustments::class)->encashmentAllowance($employee, $type)['needs_file'];
    }

    /** HROne parity RQ-4: "Available leave balance" and the encashment rule's limits for the chosen leave type. */
    public static function renderAllowance(?Employee $employee, mixed $typeId): HtmlString
    {
        $type = $employee !== null && filled($typeId) ? LeaveType::query()->find($typeId) : null;
        if ($type === null) {
            return new HtmlString('');
        }
        $a = app(LeaveAdjustments::class)->encashmentAllowance($employee, $type);
        $days = fn (float $d) => rtrim(rtrim(number_format($d, 2), '0'), '.').' '.($d == 1.0 ? 'day' : 'days');
        $lines = ['Available leave balance: '.$days($a['available']).'.'];
        if (! $a['allowed']) {
            $lines[] = (string) $a['reason'];
        } else {
            if ($a['year_limit'] !== null) {
                $lines[] = 'At most '.$days($a['year_limit']).' a year; '.$days($a['already']).' asked for this year.';
            }
            if ($a['per_request'] !== null) {
                $lines[] = 'At most '.$days($a['per_request']).' in one request.';
            }
            if ($a['keep'] > 0) {
                $lines[] = 'Keep at least '.$days($a['keep']).' after encashing.';
            }
            $lines[] = 'You can encash up to '.$days($a['max_now']).' now.';
            if ($a['needs_file']) {
                $lines[] = 'A supporting file is required.';
            }
        }

        return new HtmlString('<div class="pos-body-sm space-y-1">'.collect($lines)->map(fn (string $l) => '<p>'.e($l).'</p>')->implode('').'</div>');
    }
}
