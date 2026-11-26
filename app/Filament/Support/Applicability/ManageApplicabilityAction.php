<?php

namespace App\Filament\Support\Applicability;

use App\Domain\Configuration\Applicability\ApplicabilityPlan;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Identity\Models\User;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * "Applies to…": changes which companies one configuration record applies to (Part 8.2 / 8.3). Two steps: the selector
 * (All entities, Selected entities, Current entity only, companies created later, from when), then a review of the plan —
 * the record, the operation, where it applies now and after, who joins and leaves, dependency and usage problems, and
 * whether a shared configuration is affected — with an explicit confirmation when several companies are involved.
 * What the record uses (a parent department, a cost centre, …) that a joining company cannot use yet is brought along to
 * that company with it ("Also apply what it uses", on by default and listed in the review), instead of refusing.
 */
final class ManageApplicabilityAction
{
    public static function make(string $typeKey, string $name = 'manageApplicability'): Action
    {
        $type = app(ConfigurationTypes::class)->for($typeKey);

        return Action::make($name)
            ->label('Applies to…')
            ->icon(Heroicon::OutlinedBuildingOffice2)
            ->color('gray')
            ->visible(fn () => ($user = auth()->user()) instanceof User && $user->hasPermission($type->permission))
            ->modalHeading(fn (Model $record) => 'Where '.$type->describe($record).' applies')
            ->modalDescription('One record can serve several companies. Changing where it applies never changes finalized payroll or closed attendance; people already using it in a company keep it until they are moved.')
            ->modalSubmitActionLabel('Apply')
            ->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(fn (Model $record) => app(EntityApplicability::class)->state($record))
            ->steps(fn (Model $record) => [
                Step::make('Companies')->description('Where it applies')->schema([
                    Text::make(fn () => 'Today: '.app(EntityApplicability::class)->summary($record))->weight('medium'),
                    ...ApplicabilityUi::selectorFields($type),
                    // Today by default; back-dating only as far as the payroll months still open for the companies it
                    // touches (EntityApplicability::earliestStart(), checked again in the plan).
                    DatePicker::make('effective_from')->label('From')->native(false)->required()->default(today()->toDateString())->live()
                        ->minDate(fn (Get $get) => self::earliest($record, $get)['date'])
                        ->helperText(fn (Get $get) => self::dateHelp(self::earliest($record, $get))),
                ]),
                Step::make('Review')->description('What changes')->schema([
                    Text::make(fn (Get $get) => self::review($record, $get)),
                    Checkbox::make('with_dependencies')->label('Also apply what it uses (its parent, cost centre, …) to the companies joining')
                        ->helperText('Leave ticked to bring them along in the same change; untick to keep them as they are (the change then waits until they apply there).')
                        ->default(true)->live()
                        ->visible(fn (Get $get) => self::plan($record, $get)?->canBringAlong() ?? false),
                    Checkbox::make('confirmed')->label('I understand this changes a configuration used by several companies')
                        ->visible(fn (Get $get) => self::plan($record, $get)?->needsConfirmation() ?? false)
                        ->accepted(fn (Get $get) => self::plan($record, $get)?->needsConfirmation() ?? false),
                    // A back-dated change is acknowledged and explained (compliance: it reaches days already lived).
                    Checkbox::make('backdated_confirmed')
                        ->label(fn (Get $get) => 'I understand this is back-dated to '.self::day($get('effective_from')).': the days from then are worked out with the new companies')
                        ->visible(fn (Get $get) => self::plan($record, $get)?->backdated() ?? false)
                        ->accepted(fn (Get $get) => self::plan($record, $get)?->backdated() ?? false),
                    AuditReasonField::make()
                        ->required(fn (Get $get) => self::plan($record, $get)?->backdated() ?? false)
                        ->helperText(fn (Get $get) => (self::plan($record, $get)?->backdated() ?? false)
                            ? 'Required for a back-dated change. Recorded in the audit trail alongside the before/after values.'
                            : 'Recorded in the audit trail alongside the before/after values.'),
                ]),
            ])
            ->action(function (Model $record, array $data, Action $action) {
                $reason = AuditReasonField::extract($data);
                try {
                    $plan = app(EntityApplicability::class)->apply($record, ApplicabilitySelection::fromState($data), auth()->user(), $reason);
                } catch (ApplicabilityRefusedException $refused) {
                    Notification::make()->danger()->title('Not changed')->body($refused->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Applies to '.$plan->names($plan->after))
                    ->body(($plan->changesCompanies() ? 'From '.self::day($plan->effectiveFrom).($plan->backdated() ? ' (back-dated)' : '').'. Recorded in the audit trail.' : 'The companies did not change; the choice was recorded.')
                        .($plan->broughtAlong() === [] ? '' : ' '.implode(' ', $plan->broughtAlong())))->send();
            });
    }

    private static function plan(Model $record, Get $get): ?ApplicabilityPlan
    {
        $user = auth()->user();
        if (! filled($get('mode'))) {
            return null;
        }

        return app(EntityApplicability::class)->plan($record, ApplicabilitySelection::fromState([
            'mode' => $get('mode'), 'companies' => (array) ($get('companies') ?? []), 'company' => $get('company'),
            'include_future' => (bool) $get('include_future'), 'effective_from' => $get('effective_from'),
            // Ticked unless unticked (an older form state without the box brings them along too).
            'with_dependencies' => $get('with_dependencies') ?? true,
        ]), $user instanceof User ? $user : null);
    }

    /**
     * The earliest day the calendar offers for the choice made so far (today when nothing is chosen yet).
     *
     * @return array{date: string, reason: string}
     */
    private static function earliest(Model $record, Get $get): array
    {
        if (! filled($get('mode'))) {
            return ['date' => today()->toDateString(), 'reason' => ''];
        }

        return app(EntityApplicability::class)->earliestStartFor($record, ApplicabilitySelection::fromState([
            'mode' => $get('mode'), 'companies' => (array) ($get('companies') ?? []), 'company' => $get('company'), 'include_future' => (bool) $get('include_future'),
        ]));
    }

    /** @param  array{date: string, reason: string}  $earliest */
    private static function dateHelp(array $earliest): string
    {
        if ($earliest['reason'] === '') {
            return 'Today by default. Choose where it applies first: earlier days open as far as its payroll months allow.';
        }
        if ($earliest['date'] >= today()->toDateString()) {
            return 'Today or later: it cannot be back-dated now ('.$earliest['reason'].').';
        }

        return 'Today by default. Back-dating goes to '.self::day($earliest['date']).' at the earliest: '.$earliest['reason']
            .'. Finalized payroll and closed attendance months never change.';
    }

    private static function day(mixed $date): string
    {
        return filled($date) ? Carbon::parse((string) $date)->format('j M Y') : '…';
    }

    private static function review(Model $record, Get $get): HtmlString
    {
        $plan = self::plan($record, $get);
        $type = app(EntityApplicability::class)->type($record);

        return new HtmlString(view('filament.applicability.review', [
            'plan' => $plan,
            'label' => $type->describe($record),
            'typeLabel' => strtolower($type->label),
        ])->render());
    }
}
