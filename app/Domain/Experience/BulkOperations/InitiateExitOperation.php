<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/** HROne parity WO-1 (§23.2.2 #5 "Initiate separation" in bulk): one exit case per employee through Exits::initiate. */
final class InitiateExitOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.initiate_exit';
    }

    public function label(): string
    {
        return 'Initiate exit';
    }

    public function description(): string
    {
        return 'Opens one exit case per employee with the same type, dates and reason (the exit rules apply to each: one open exit at a time, current employees only, the policy\'s backdating limit).';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'exit.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-arrow-right-start-on-rectangle';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [
            Select::make('type')->options(config('peopleos.exit.types'))->default('resignation')->required()->live(),
            DatePicker::make('resignation_date')->label('Resignation / decision date')->native(false)->default(now())->required(),
            TextInput::make('notice_days')->numeric()->minValue(0)->placeholder('Each employee\'s notice from the exit policy')
                ->visible(fn (Get $get) => ! in_array($get('type'), config('peopleos.exit.immediate_types', []), true)),
            DatePicker::make('last_working_day')->native(false)->placeholder('Resignation date + notice'),
            Select::make('workforce_reason_id')->label('Separation reason')->placeholder('—')->native(false)
                ->options(fn () => WorkforceReason::query()->ofKind('separation')->where('for_approver', true)->pluck('name', 'id')->all())
                ->visible(fn () => WorkforceReason::query()->ofKind('separation')->where('for_approver', true)->exists()),
            Toggle::make('is_rehire_eligible')->label('Eligible for rehire')->default(true)->live(),
            Select::make('rehire_block_reason_id')->label('Why not eligible for rehire')->native(false)
                ->options(fn () => WorkforceReason::query()->ofKind('blacklist')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => ! $get('is_rehire_eligible') && WorkforceReason::query()->ofKind('blacklist')->exists())
                ->required(fn (Get $get) => ! $get('is_rehire_eligible') && WorkforceReason::query()->ofKind('blacklist')->exists()),
            Textarea::make('reason')->rows(3)->maxLength(2000),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $type = (string) ($data['type'] ?? '');
        if (! array_key_exists($type, config('peopleos.exit.types'))) {
            throw new RuntimeException('Choose an exit type.');
        }
        $date = fn ($value) => filled($value) ? Carbon::parse($value)->toDateString() : null;

        return [
            'type' => $type,
            'resignation_date' => $date($data['resignation_date'] ?? null) ?? now()->toDateString(),
            'notice_days' => isset($data['notice_days']) && $data['notice_days'] !== '' ? max(0, (int) $data['notice_days']) : null,
            'last_working_day' => $date($data['last_working_day'] ?? null),
            'workforce_reason_id' => filled($data['workforce_reason_id'] ?? null) ? (int) $data['workforce_reason_id'] : null,
            'is_rehire_eligible' => (bool) ($data['is_rehire_eligible'] ?? true),
            'rehire_block_reason_id' => filled($data['rehire_block_reason_id'] ?? null) ? (int) $data['rehire_block_reason_id'] : null,
            'reason' => filled($data['reason'] ?? null) ? mb_substr((string) $data['reason'], 0, 2000) : null,
        ];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $case = app(Exits::class)->initiate($record, $input['type'], $input['reason'] ?? null, $input['resignation_date'], $input['last_working_day'] ?? null, $input['notice_days'] ?? null, $actor, [
            'is_rehire_eligible' => $input['is_rehire_eligible'] ?? true,
            'workforce_reason_id' => $input['workforce_reason_id'] ?? null,
            'rehire_block_reason_id' => $input['rehire_block_reason_id'] ?? null,
        ]);

        return $case->number.' initiated; last working day '.$case->last_working_day->toDateString();
    }
}
