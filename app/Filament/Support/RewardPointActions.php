<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\RewardPoints;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * HROne setup parity HP-9d: the reward points actions — give points to a colleague, redeem one's own points (when the
 * organisation allows it), and for HR (recognition.points) deposit into the budget, redeem for an employee, adjust a
 * balance and cancel a redemption not yet paid. Every rule is RewardPoints'; the forms only collect the values.
 */
final class RewardPointActions
{
    public static function give(?Closure $after = null): Action
    {
        return Action::make('givePoints')->label('Give points')->icon(Heroicon::OutlinedGift)
            ->visible(fn () => app(RewardPoints::class)->enabled() && (auth()->user()?->can('recognition.give') ?? false) && self::me() !== null)
            ->modalHeading('Give reward points')
            ->modalDescription(fn () => 'You have '.app(RewardPoints::class)->givingBalance(self::me()).' points to give. The colleague is told at once.')
            ->modalSubmitActionLabel('Give points')
            // HROne parity RQ-8: opened from the inbox's "More" with ['employee' => id], the colleague is picked already.
            ->fillForm(fn (array $arguments) => ['employee_id' => $arguments['employee'] ?? null])
            ->schema([
                Select::make('employee_id')->label('Colleague')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => RecognitionActions::colleagues($search))
                    ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->auditLabel()),
                TextInput::make('points')->label('Points')->required()->numeric()->integer()->minValue(1)->maxValue(100000),
                Textarea::make('message')->label('Message')->rows(3)->maxLength(RewardPoints::MESSAGE_MAX),
            ])
            ->action(function (array $data) use ($after) {
                self::attempt(function () use ($data) {
                    $employee = Employee::query()->with('person')->findOrFail($data['employee_id']);
                    app(RewardPoints::class)->give(auth()->user(), $employee, (int) $data['points'], $data['message'] ?? null);

                    return (int) $data['points'].' points given to '.$employee->person->first_name;
                }, 'Could not give the points', $after);
            });
    }

    public static function redeemOwn(?Closure $after = null): Action
    {
        return Action::make('redeemPoints')->label('Redeem my points')->icon(Heroicon::OutlinedBanknotes)->color('gray')
            ->visible(fn () => self::me() !== null && app(RewardPoints::class)->redemptionRefusal() === null && (bool) app(SettingsRepository::class)->get('engage.points.self_redeem', false))
            ->modalHeading('Redeem my points')
            ->modalDescription(fn () => self::redeemHint(self::me()))
            ->schema([TextInput::make('points')->label('Points')->required()->numeric()->integer()->minValue(1)])
            ->action(function (array $data) use ($after) {
                self::attempt(function () use ($data) {
                    $entry = app(RewardPoints::class)->redeem(self::me(), (int) $data['points'], auth()->user());

                    return 'Redeemed: '.number_format((float) $entry->amount, 2).' '.$entry->currency.' comes with your next salary';
                }, 'Could not redeem', $after);
            });
    }

    public static function deposit(?Closure $after = null): Action
    {
        return Action::make('depositPoints')->label('Deposit points')->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => auth()->user()?->can('recognition.points') ?? false)
            ->modalHeading('Deposit points into the budget')
            ->modalDescription(fn () => 'The budget has '.app(RewardPoints::class)->budgetAvailable().' points. Cycle credits, badge points and positive adjustments are paid from it.')
            ->schema([
                TextInput::make('points')->label('Points')->required()->numeric()->integer()->minValue(1)->maxValue(100000000),
                Textarea::make('note')->label('Note')->rows(2)->maxLength(RewardPoints::MESSAGE_MAX),
            ])
            ->action(function (array $data) use ($after) {
                self::attempt(function () use ($data) {
                    app(RewardPoints::class)->deposit((int) $data['points'], auth()->user(), $data['note'] ?? null);

                    return (int) $data['points'].' points deposited';
                }, 'Could not deposit', $after);
            });
    }

    public static function redeemFor(?Closure $after = null): Action
    {
        return Action::make('redeemFor')->label('Redeem for an employee')->icon(Heroicon::OutlinedBanknotes)->color('gray')
            ->visible(fn () => (auth()->user()?->can('recognition.points') ?? false) && app(RewardPoints::class)->redemptionRefusal() === null)
            ->modalHeading('Redeem points through payroll')
            ->modalDescription(fn () => 'One point is worth '.app(RewardPoints::class)->valuePerPoint().' '.app(RewardPoints::class)->currency().'. The amount is paid with the next payroll as a taxable earning.')
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => RecognitionActions::colleagues($search))
                    ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->auditLabel()),
                TextInput::make('points')->label('Points')->required()->numeric()->integer()->minValue(1),
                Textarea::make('note')->label('Note')->rows(2)->maxLength(RewardPoints::MESSAGE_MAX),
            ])
            ->action(function (array $data) use ($after) {
                self::attempt(function () use ($data) {
                    $employee = Employee::query()->with('person')->findOrFail($data['employee_id']);
                    $entry = app(RewardPoints::class)->redeem($employee, (int) $data['points'], auth()->user(), $data['note'] ?? null);

                    return 'Redeemed '.(-$entry->points).' points for '.$employee->person->first_name;
                }, 'Could not redeem', $after);
            });
    }

    public static function adjust(?Closure $after = null): Action
    {
        return Action::make('adjustPoints')->label('Adjust points')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
            ->visible(fn () => auth()->user()?->can('recognition.points') ?? false)
            ->modalHeading('Adjust an employee\'s points')
            ->modalDescription('Points added come from the budget; points taken away go back to it.')
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => RecognitionActions::colleagues($search))
                    ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->auditLabel()),
                TextInput::make('points')->label('Points (negative to take away)')->required()->numeric()->integer()->notIn([0]),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(RewardPoints::MESSAGE_MAX),
            ])
            ->action(function (array $data) use ($after) {
                self::attempt(function () use ($data) {
                    $employee = Employee::query()->with('person')->findOrFail($data['employee_id']);
                    app(RewardPoints::class)->adjust($employee, (int) $data['points'], auth()->user(), (string) $data['reason']);

                    return 'Points adjusted for '.$employee->person->first_name;
                }, 'Could not adjust', $after);
            });
    }

    public static function cancelRedemption(): Action
    {
        return Action::make('cancelRedemption')->label('Cancel redemption')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn (RewardPointEntry $record) => $record->kind === 'redeem' && $record->payroll_run_id === null && (auth()->user()?->can('recognition.points') ?? false))
            ->modalDescription('The points come back to the employee. A redemption a payroll run already carries cannot be cancelled.')
            ->schema([Textarea::make('reason')->required()->maxLength(255)])
            ->action(fn (RewardPointEntry $record, array $data) => self::attempt(function () use ($record, $data) {
                app(RewardPoints::class)->cancelRedemption($record, auth()->user(), (string) $data['reason']);

                return 'Redemption cancelled';
            }, 'Could not cancel'));
    }

    /** "You have 120 points (worth 120.00 INR)." */
    public static function redeemHint(?Employee $employee): string
    {
        $points = app(RewardPoints::class);
        $balance = $employee === null ? 0 : $points->earnedBalance($employee);
        $value = $points->valuePerPoint();

        return "You have {$balance} points".($value !== null ? ' (worth '.number_format($balance * $value, 2).' '.$points->currency().')' : '').'. The amount is paid with your next salary as a taxable earning.';
    }

    private static function me(): ?Employee
    {
        $user = auth()->user();

        return $user === null ? null : app(RoleLens::class)->employee($user);
    }

    private static function attempt(callable $work, string $failure, ?Closure $after = null): void
    {
        try {
            Notification::make()->success()->title($work())->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($failure)->body($e->getMessage())->persistent()->send();
        }
        if ($after !== null) {
            $after();
        }
    }
}
