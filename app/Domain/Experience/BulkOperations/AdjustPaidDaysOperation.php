<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Attendance\Services\PaidDayAdjustments;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-4 (matrix 24 §24.5.3 #19–21, "Override paid days" for the employees selected; owner decision TIM-2 /
 * D-TX2, off by default through attendance.paid_day_adjustments.enabled): the same paid-day adjustment for each selected
 * employee's attendance month, saved through PaidDayAdjustments::save — reason required, audited, refused on finalized,
 * locked or closed months and on one's own paid days; attendance.override in scope. The Paid days screen shows each
 * employee's result before the run starts.
 */
final class AdjustPaidDaysOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'paid_days.adjust';
    }

    public function label(): string
    {
        return 'Adjust paid days';
    }

    public function description(): string
    {
        return 'Saves the same paid-day adjustment for each selected employee\'s month, with this reason (an earlier adjustment of the month is replaced). Finalized, locked and closed months are refused, and so are your own paid days.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return PaidDayAdjustments::PERMISSION;
    }

    public function icon(): string
    {
        return 'heroicon-m-adjustments-horizontal';
    }

    public function color(): string
    {
        return 'warning';
    }

    public static function enabled(): bool
    {
        return app(PaidDayAdjustments::class)->enabled();
    }

    /**
     * The adjustment fields (shared by the one-employee and the bulk form): how, then the counts or the reductions.
     *
     * @return list<mixed>
     */
    public static function fields(): array
    {
        $set = [];
        $reduce = [];
        foreach (PaidDayAdjustment::CATEGORIES as $key => $label) {
            $set[] = TextInput::make("counts.{$key}")->label('No. of '.mb_strtolower($label))->numeric()->minValue(0)->maxValue(31)->step(0.5)
                ->helperText('Blank keeps the processed count.')->live(onBlur: true);
            $reduce[] = Select::make("reductions.{$key}.how")->label($label)->options(PaidDayAdjustment::HOW)->default('none')->native(false)->live();
            $reduce[] = TextInput::make("reductions.{$key}.percent")->label('How much % to reduce')->numeric()->minValue(0.01)->maxValue(100)->suffix('%')->live(onBlur: true)
                ->visible(fn (Get $get) => $get("reductions.{$key}.how") === 'partial')->required(fn (Get $get) => $get("reductions.{$key}.how") === 'partial');
        }

        return [
            Radio::make('mode')->label('How do you want to adjust the paid days?')->options([
                'set' => 'Set the counts (manually set paid days)',
                'reduce' => 'Reduce chosen days (no, partial or full reduction of each kind of day)',
            ])->default('reduce')->required()->live(),
            Grid::make(2)->schema($set)->visible(fn (Get $get) => $get('mode') === 'set'),
            Grid::make(2)->schema($reduce)->visible(fn (Get $get) => $get('mode') === 'reduce'),
            Text::make(self::help()),
        ];
    }

    /**
     * The service's input from the form: the counts in set mode, the reductions in reduce mode.
     *
     * @param  array<string, mixed>  $data
     * @return array{mode: string, categories: array<string, mixed>, value: mixed}
     */
    public static function input(array $data): array
    {
        $mode = (string) ($data['mode'] ?? '');

        return ['mode' => $mode, 'categories' => (array) ($data[$mode === 'set' ? 'counts' : 'reductions'] ?? $data['categories'] ?? []), 'value' => $data['value'] ?? null];
    }

    /** The formula, in reduction wording (HROne's "How is it calculated?"). */
    public static function help(): string
    {
        return 'How is it calculated? Paid days = present days + paid leave + holidays + weekly offs, after the reduction you choose. '
            .'Example: 20 present days, 2 days of paid leave, 1 holiday and 8 weekly offs make 31 paid days; a full reduction of weekly offs '
            .'and a 50% reduction of paid leave leave 20 + 1 + 1 + 0 = 22. A partial reduction is rounded as the employee\'s attendance policy '
            .'says (Paid days → round a partial reduction; blank = two decimals). Late-mark penalty days are counted separately.';
    }

    public function schema(): array
    {
        return [
            Select::make('company_id')->label('Company')->required()->native(false)->live()
                ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('month')->label('Month')->required()->native(false)
                ->options(fn (Get $get) => filled($get('company_id')) ? app(AttendanceMonthDesk::class)->months((int) $get('company_id')) : []),
            ...self::fields(),
            Textarea::make('reason')->label('Reason')->required()->maxLength(255),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $adjustments = app(PaidDayAdjustments::class);
        if (! $adjustments->enabled()) {
            throw new RuntimeException('Paid-day adjustments are switched off (Setup → Time office).');
        }
        $company = Company::query()->find((int) ($data['company_id'] ?? 0));
        if ($company === null) {
            throw new RuntimeException('Choose a company in your access.');
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) ($data['month'] ?? ''), $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new RuntimeException('Choose the attendance month.');
        }
        try {
            [$start, $end] = $adjustments->window((int) $company->id, (int) $m[1], (int) $m[2]);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }
        $input = $adjustments->normalise(self::input($data), (int) $start->diffInDays($end) + 1);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for adjusting the paid days.');
        }

        return ['company_id' => (int) $company->id, 'year' => (int) $m[1], 'month' => (int) $m[2]] + $input + ['reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return $record->user_id !== null && (int) $record->user_id === (int) $actor->id ? 'You cannot adjust your own paid days.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $adjustment = app(PaidDayAdjustments::class)->save($record, (int) $input['company_id'], (int) $input['year'], (int) $input['month'],
            ['mode' => $input['mode'], 'categories' => $input['categories'] ?? [], 'value' => $input['value'] ?? null], (string) $input['reason'], $actor);

        return $adjustment->label().': paid days '.PaidDayAdjustment::number((float) $adjustment->paid_days_before).' → '.PaidDayAdjustment::number((float) $adjustment->paid_days_after);
    }
}
