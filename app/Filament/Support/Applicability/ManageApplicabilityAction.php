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
use Illuminate\Support\HtmlString;

/**
 * "Applies to…": changes which companies one configuration record applies to (Part 8.2 / 8.3). Two steps: the selector
 * (All entities, Selected entities, Current entity only, companies created later, from when), then a review of the plan —
 * the record, the operation, where it applies now and after, who joins and leaves, dependency and usage problems, and
 * whether a shared configuration is affected — with an explicit confirmation when several companies are involved.
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
            ->modalDescription('One record can serve several companies. Changing where it applies never changes past transactions; people already using it in a company keep it until they are moved.')
            ->modalSubmitActionLabel('Apply')
            ->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(fn (Model $record) => app(EntityApplicability::class)->state($record))
            ->steps(fn (Model $record) => [
                Step::make('Companies')->description('Where it applies')->schema([
                    Text::make(fn () => 'Today: '.app(EntityApplicability::class)->summary($record))->weight('medium'),
                    ...ApplicabilityUi::selectorFields($type),
                    DatePicker::make('effective_from')->label('From')->native(false)->required()->minDate(today())->default(today())
                        ->helperText('Today or later. Past days keep what applied to them.'),
                ]),
                Step::make('Review')->description('What changes')->schema([
                    Text::make(fn (Get $get) => self::review($record, $get)),
                    Checkbox::make('confirmed')->label('I understand this changes a configuration used by several companies')
                        ->visible(fn (Get $get) => self::plan($record, $get)?->needsConfirmation() ?? false)
                        ->accepted(fn (Get $get) => self::plan($record, $get)?->needsConfirmation() ?? false),
                    AuditReasonField::make(),
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
                    ->body($plan->changesCompanies() ? 'From '.$plan->effectiveFrom.'. Recorded in the audit trail.' : 'The companies did not change; the choice was recorded.')->send();
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
        ]), $user instanceof User ? $user : null);
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
