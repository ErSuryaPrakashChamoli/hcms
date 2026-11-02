<?php

namespace App\Filament\Support;

use App\Domain\Engagement\Contracts\PledgePayroll;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Filament\Resources\FundraiserCampaigns\FundraiserCampaignResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * HROne parity GO-18: the fundraiser actions — "Start a fundraiser" (a step wizard: cause · target and dates · who can
 * donate · payroll · review, opened directly with ?action=startFundraiser), change a draft, open, close, cancel, the
 * pledges CSV and the document for HR (engagement.manage); "Donate now" for employees (engagement.participate) on the
 * fundraiser, its list and the Home card; record an outside payment and cancel a pledge on the pledges list. Every rule
 * is Fundraisers'; the forms only collect the values.
 */
final class FundraiserActions
{
    public const START = 'startFundraiser';

    private static function service(): Fundraisers
    {
        return app(Fundraisers::class);
    }

    // --- HR -----------------------------------------------------------------------------------------------------------

    public static function start(): Action
    {
        return Action::make(self::START)->label('Start a fundraiser')->icon(Heroicon::OutlinedHeart)
            ->visible(fn () => self::service()->manages(auth()->user()))
            ->modalHeading('Start a fundraiser')
            ->modalDescription('Describe the cause, set the target and the dates, and choose who may donate. Employees see an open fundraiser on Home and pledge an amount they choose.')
            ->modalWidth('4xl')
            ->fillForm(fn () => ['starts_on' => self::service()->today()->toDateString(), 'currency' => self::service()->baseCurrency(), 'open_now' => true, 'payroll_deduction' => false])
            ->steps([...self::steps(), self::reviewStep(true)])
            ->action(function (array $data) {
                ServiceDeskActions::run(function () use ($data) {
                    $campaign = self::service()->create(self::input($data), auth()->user());
                    if ((bool) ($data['open_now'] ?? false)) {
                        self::service()->open($campaign, auth()->user());

                        return 'Fundraiser opened';
                    }

                    return 'Fundraiser saved as a draft';
                }, fn (string $done) => $done);
            });
    }

    public static function edit(): Action
    {
        return Action::make('editFundraiser')->label('Change')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && $record->status === 'draft' && self::service()->manages(auth()->user()))
            ->modalHeading('Change the draft fundraiser')
            ->modalWidth('4xl')
            ->fillForm(fn (FundraiserCampaign $record) => [
                'name' => $record->name, 'purpose' => $record->purpose, 'beneficiary' => $record->beneficiary, 'document_path' => $record->document_path, 'document_name' => $record->document_name,
                'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(), 'target_amount' => (float) $record->target_amount, 'currency' => $record->currency,
                'audience_id' => $record->audience_id, 'audience_criteria' => $record->audience_criteria ?? [], 'payroll_deduction' => $record->payroll_deduction, 'deduction_month' => $record->deduction_month,
            ])
            ->steps([...self::steps(), self::reviewStep(false)])
            ->action(fn (FundraiserCampaign $record, array $data) => ServiceDeskActions::run(fn () => self::service()->update($record, self::input($data), auth()->user()), 'Draft saved'));
    }

    public static function open(): Action
    {
        return Action::make('openFundraiser')->label('Open')->icon(Heroicon::OutlinedPlayCircle)->color('success')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && $record->status === 'draft' && self::service()->manages(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('Open the fundraiser')
            ->modalDescription('Its audience is fixed now, within your scope; the people in it see the fundraiser on Home and may pledge between its dates. An open fundraiser is no longer changed.')
            ->action(fn (FundraiserCampaign $record) => ServiceDeskActions::run(fn () => self::service()->open($record, auth()->user()), 'Fundraiser opened'));
    }

    public static function close(): Action
    {
        return Action::make('closeFundraiser')->label('Close')->icon(Heroicon::OutlinedStopCircle)->color('gray')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && $record->status === 'open' && self::service()->manages(auth()->user()))
            ->modalHeading('Close the fundraiser')
            ->modalDescription('No more pledges are taken. Pledges already made are still collected: deducted in their payroll month, or recorded when paid outside payroll.')
            ->schema([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(Fundraisers::NOTE_MAX)])
            ->action(fn (FundraiserCampaign $record, array $data) => ServiceDeskActions::run(fn () => self::service()->close($record, auth()->user(), $data['note'] ?? null), 'Fundraiser closed'));
    }

    public static function cancel(): Action
    {
        return Action::make('cancelFundraiser')->label('Cancel fundraiser')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && in_array($record->status, ['draft', 'open'], true) && self::service()->manages(auth()->user()))
            ->modalHeading('Cancel the fundraiser')
            ->modalDescription('Every pledge still to be collected is cancelled with it (nothing is deducted). Pledges already deducted or paid stay as they are.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->rows(3)->maxLength(Fundraisers::NOTE_MAX)])
            ->action(fn (FundraiserCampaign $record, array $data) => ServiceDeskActions::run(
                fn () => self::service()->cancel($record, auth()->user(), (string) $data['reason']),
                fn (array $result) => 'Fundraiser cancelled · '.$result['pledges'].' pledge(s) cancelled'.($result['carried'] > 0 ? ' · '.$result['carried'].' were in a payroll being prepared: it must be recalculated before it is finalized' : ''),
            ));
    }

    public static function downloadPledges(): Action
    {
        return Action::make('downloadPledges')->label('Download pledges (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && $record->status !== 'draft' && self::service()->manages(auth()->user()))
            ->action(function (FundraiserCampaign $record) {
                $csv = self::service()->pledgesCsv($record, auth()->user());

                return response()->streamDownload(fn () => print ($csv), 'fundraiser-'.$record->id.'-pledges-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
            });
    }

    public static function document(): Action
    {
        return Action::make('fundraiserDocument')->label('Document')->icon(Heroicon::OutlinedPaperClip)->color('gray')
            ->visible(fn (?Model $record) => $record instanceof FundraiserCampaign && $record->document_path !== null)
            ->url(fn (FundraiserCampaign $record) => self::service()->documentUrl($record), shouldOpenInNewTab: true);
    }

    // --- Employees ------------------------------------------------------------------------------------------------------

    /**
     * "Donate now": on a fundraiser (its record) or, from Home, with ['campaign' => id].
     */
    public static function donate(?Closure $after = null): Action
    {
        $campaign = fn (?Model $record, array $arguments) => $record instanceof FundraiserCampaign ? $record : FundraiserCampaign::query()->find((int) ($arguments['campaign'] ?? 0));
        $months = fn (?FundraiserCampaign $c) => $c === null ? [] : self::service()->payrollMonths($c, app(CurrentEmployee::class)->of(auth()->user()));

        return Action::make('donate')->label('Donate now')->icon(Heroicon::OutlinedHeart)->color('primary')
            ->visible(fn (?Model $record) => ! $record instanceof FundraiserCampaign
                || (self::service()->participates(auth()->user()) && self::service()->refusal($record, app(CurrentEmployee::class)->of(auth()->user())) === null))
            ->modalHeading(fn (?Model $record, array $arguments) => 'Donate to '.($campaign($record, $arguments)?->name ?? 'the fundraiser'))
            ->modalDescription(fn (?Model $record, array $arguments) => self::summary($campaign($record, $arguments)))
            ->modalSubmitActionLabel('Pledge')
            ->fillForm(fn () => ['mode' => 'paid_outside'])
            ->schema(fn (?Model $record, array $arguments) => self::pledgeFields($campaign($record, $arguments), $months($campaign($record, $arguments))))
            ->action(function (?Model $record, array $arguments, array $data) use ($campaign, $after) {
                $c = $campaign($record, $arguments);
                try {
                    if ($c === null) {
                        throw new RuntimeException('The fundraiser was not found.');
                    }
                    $pledge = self::service()->pledge($c, auth()->user(), (string) ($data['amount'] ?? ''), (string) ($data['mode'] ?? 'paid_outside'),
                        $data['payroll_month'] ?? null, (bool) ($data['consent'] ?? false), $data['note'] ?? null);
                    Notification::make()->success()->title('Thank you for your pledge')
                        ->body(number_format((float) $pledge->amount, 2).' '.$pledge->currency.($pledge->mode === 'one_time_payroll'
                            ? ' is deducted once from your salary in the payroll of '.FundraiserCampaign::monthLabel($pledge->payroll_month).'.'
                            : ' — pay it as the fundraiser describes; HR records the payment.'))->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not pledged')->body($e->getMessage())->send();
                }
                if ($after !== null) {
                    $after();
                }
            });
    }

    // --- Pledges --------------------------------------------------------------------------------------------------------

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')->label('Record payment')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(fn (FundraiserPledge $record) => $record->status === 'pledged' && self::service()->manages(auth()->user()))
            ->modalHeading('Record the payment made outside payroll')
            ->fillForm(fn () => ['paid_on' => today()->toDateString()])
            ->schema([
                DatePicker::make('paid_on')->label('Paid on')->required()->native(false)->maxDate(today()),
                TextInput::make('reference')->label('Reference (receipt, transfer)')->maxLength(100),
            ])
            ->action(fn (FundraiserPledge $record, array $data) => ServiceDeskActions::run(
                fn () => self::service()->recordPayment($record, auth()->user(), (string) $data['paid_on'], $data['reference'] ?? null), 'Payment recorded'));
    }

    public static function cancelPledge(): Action
    {
        return Action::make('cancelPledge')->label('Cancel pledge')->icon(Heroicon::OutlinedXMark)->color('danger')
            ->visible(fn (FundraiserPledge $record) => $record->status === 'pledged'
                && (self::service()->manages(auth()->user()) || (int) (app(CurrentEmployee::class)->of(auth()->user())?->id ?? 0) === (int) $record->employee_id))
            ->modalHeading('Cancel the pledge')
            ->modalDescription(fn (FundraiserPledge $record) => ($carried = self::service()->carriedBy($record)) !== null
                ? "The payroll being prepared ({$carried}) already shows this deduction: once cancelled, that payroll is recalculated before it is finalized, and nothing is deducted."
                : 'Nothing is collected for a cancelled pledge.')
            ->schema([Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(Fundraisers::NOTE_MAX)])
            ->action(fn (FundraiserPledge $record, array $data) => ServiceDeskActions::run(fn () => self::service()->cancelPledge($record, auth()->user(), $data['reason'] ?? null), 'Pledge cancelled'));
    }

    // --- Forms -----------------------------------------------------------------------------------------------------------

    /** @return list<Step> */
    private static function steps(): array
    {
        $disk = config('peopleos.documents.disk', 'local');

        return [
            Step::make('Cause')->description('Campaign name, purpose and who it helps')->schema([
                TextInput::make('name')->label('Campaign name')->required()->maxLength(150),
                Textarea::make('purpose')->label('Purpose')->required()->rows(4)->maxLength(Fundraisers::PURPOSE_MAX),
                TextInput::make('beneficiary')->label('Beneficiary (optional)')->maxLength(200)->helperText('The person, family or organisation the money goes to.'),
                FileUpload::make('document_path')->label('Upload document')->disk($disk)->directory(fn () => Fundraisers::directory())->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('document_name')
                    ->helperText('Optional: an appeal letter, a poster or a receipt from the beneficiary. Employees who see the fundraiser may open it.'),
            ]),
            Step::make('Target and dates')->description('How much, and when pledges are taken')->columns(2)->schema([
                DatePicker::make('starts_on')->label('Start date')->required()->native(false),
                DatePicker::make('ends_on')->label('End date')->required()->native(false)->afterOrEqual('starts_on'),
                TextInput::make('target_amount')->label('Target amount')->required()->numeric()->minValue(1)->maxValue(Fundraisers::AMOUNT_MAX),
                TextInput::make('currency')->label('Currency')->required()->maxLength(3)->helperText('Three letters, e.g. INR.'),
            ]),
            Step::make('Who can donate')->description('A saved audience, criteria, or everyone employed in your scope')->schema([
                Select::make('audience_id')->label('Saved audience')->placeholder('— criteria below (empty = everyone employed in your scope) —')
                    ->options(fn () => Audience::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                AudienceCriteriaSchema::section('audience_criteria', 'Or choose by criteria'),
            ]),
            Step::make('Payroll')->description('Collect pledges with salaries (only where the organisation allows it)')
                ->visible(fn () => self::service()->payrollAllowed())
                ->schema([
                    Toggle::make('payroll_deduction')->label('Do you want to collect through payroll?')->live()
                        ->helperText('Donors may then choose to have their pledge deducted once from their salary; each such pledge needs their consent. Pledges paid outside payroll stay possible.'),
                    Select::make('deduction_month')->label('Salary deduction month')->placeholder('Each donor chooses the month')
                        ->options(fn () => app(PledgePayroll::class)->upcoming())->visible(fn (Get $get) => (bool) $get('payroll_deduction')),
                ]),
        ];
    }

    private static function reviewStep(bool $creating): Step
    {
        return Step::make('Review')->description('Check, then open it or keep it as a draft')->schema(array_values(array_filter([
            Text::make(fn (Get $get) => self::review($get)),
            $creating ? Toggle::make('open_now')->label('Open it now')->helperText('Off: saved as a draft; open it from the fundraiser when it is ready.') : null,
        ])));
    }

    private static function review(Get $get): HtmlString
    {
        $criteria = AudienceCriteriaSchema::clean((array) ($get('audience_criteria') ?? []));
        $audience = filled($get('audience_id')) ? 'Audience: '.Audience::query()->whereKey((int) $get('audience_id'))->value('name') : AudienceCriteriaSchema::describe($criteria);
        $rows = [
            'Campaign' => (string) $get('name'),
            'Dates' => trim(((string) $get('starts_on')).' → '.((string) $get('ends_on'))),
            'Target' => trim(((string) $get('target_amount')).' '.((string) $get('currency'))),
            'Who can donate' => $audience,
            'Payroll' => (bool) $get('payroll_deduction') ? 'Collected through payroll'.(filled($get('deduction_month')) ? ' in '.FundraiserCampaign::monthLabel((string) $get('deduction_month')) : ' in the month each donor chooses').', with consent per pledge' : 'Paid outside payroll',
        ];
        $html = '<dl class="grid gap-2 text-sm">';
        foreach ($rows as $label => $value) {
            $html .= '<div><dt class="font-medium">'.e($label).'</dt><dd>'.e($value === '' ? '—' : $value).'</dd></div>';
        }

        return new HtmlString($html.'</dl>');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(array $data): array
    {
        $path = $data['document_path'] ?? null;

        return [
            'name' => $data['name'] ?? null, 'purpose' => $data['purpose'] ?? null, 'beneficiary' => $data['beneficiary'] ?? null,
            'document_path' => is_array($path) ? (array_values($path)[0] ?? null) : $path, 'document_name' => $data['document_name'] ?? null,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null, 'target_amount' => isset($data['target_amount']) ? (string) $data['target_amount'] : null,
            'currency' => $data['currency'] ?? null, 'audience_id' => $data['audience_id'] ?? null, 'audience_criteria' => AudienceCriteriaSchema::clean((array) ($data['audience_criteria'] ?? [])),
            'payroll_deduction' => (bool) ($data['payroll_deduction'] ?? false), 'deduction_month' => $data['deduction_month'] ?? null,
        ];
    }

    /**
     * @param  array<string, string>  $months
     * @return list<mixed>
     */
    private static function pledgeFields(?FundraiserCampaign $campaign, array $months): array
    {
        $payroll = $months !== [];
        $fixed = $campaign?->deduction_month;

        return array_values(array_filter([
            TextInput::make('amount')->label('Amount'.($campaign ? ' ('.$campaign->currency.')' : ''))->required()->numeric()->minValue(1)->maxValue(Fundraisers::AMOUNT_MAX)->live(onBlur: true),
            $payroll ? Radio::make('mode')->label('How will you pay?')->required()->live()
                ->options(['paid_outside' => 'I will pay outside payroll', 'one_time_payroll' => 'Deduct it once from my salary']) : null,
            $payroll && $fixed === null ? Select::make('payroll_month')->label('Payroll month of the deduction')->options($months)->required()
                ->visible(fn (Get $get) => $get('mode') === 'one_time_payroll')->live() : null,
            $payroll ? Checkbox::make('consent')->accepted()
                ->label(fn (Get $get) => Fundraisers::consentText($campaign, (float) ($get('amount') ?: 0), (string) ($fixed ?? $get('payroll_month') ?? '…')))
                ->visible(fn (Get $get) => $get('mode') === 'one_time_payroll') : null,
            Textarea::make('note')->label('Message (optional)')->rows(2)->maxLength(Fundraisers::NOTE_MAX),
        ]));
    }

    private static function summary(?FundraiserCampaign $campaign): ?string
    {
        if ($campaign === null) {
            return null;
        }
        $t = self::service()->totals($campaign);

        return 'Collected '.number_format($t['collected'], 2).' of '.number_format($t['target'], 2).' '.$campaign->currency
            .($t['pledged'] > 0 ? ' (and '.number_format($t['pledged'], 2).' pledged)' : '').' · ends '.$campaign->ends_on->format('d M Y').'. Pledge the amount you choose; it is never changed afterwards.';
    }

    /** The fundraiser's own page (for Home's "See more"). */
    public static function url(FundraiserCampaign $campaign): string
    {
        return FundraiserCampaignResource::getUrl('view', ['record' => $campaign]);
    }
}
