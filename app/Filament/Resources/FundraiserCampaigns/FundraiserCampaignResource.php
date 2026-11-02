<?php

namespace App\Filament\Resources\FundraiserCampaigns;

use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Identity\Scopes\AccessScope;
use App\Filament\Resources\FundraiserCampaigns\Pages\ListFundraiserCampaigns;
use App\Filament\Resources\FundraiserCampaigns\Pages\ViewFundraiserCampaign;
use App\Filament\Resources\FundraiserCampaigns\RelationManagers\PledgesRelationManager;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\FundraiserActions;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity GO-18 (Engage → "Initiate fundraise", `fundraisercampaign_767`; matrix 27 §27.8.9): fundraisers. HR
 * (engagement.manage) starts them with the "Start a fundraiser" wizard (?action=startFundraiser), opens, closes or
 * cancels them, follows collected against target, records outside payments and downloads the pledges. Employees
 * (engagement.participate) see only the fundraisers open to them (and those they pledged to) and donate; the screen
 * opens for them only when there is one. Every rule is Fundraisers'.
 */
class FundraiserCampaignResource extends Resource
{
    protected static ?string $model = FundraiserCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Fundraisers';

    protected static ?string $modelLabel = 'fundraiser';

    protected static ?string $pluralModelLabel = 'fundraisers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'fundraisers';

    protected static ?int $navigationSort = 66;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $fundraisers = app(Fundraisers::class);

        return $user !== null && ($fundraisers->manages($user) || $fundraisers->hasAnyFor($user));
    }

    /** Fundraisers are started with the wizard on the list (FundraiserActions::start), never a bare create form. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $settled = fn (array $statuses) => fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)->whereIn('status', $statuses);
        $query = parent::getEloquentQuery()
            ->withSum(['pledges as collected_sum' => $settled(['deducted', 'paid'])], 'amount')
            ->withSum(['pledges as pledged_sum' => $settled(['pledged'])], 'amount');

        $user = auth()->user();

        return $user === null ? $query->whereRaw('1 = 0') : app(Fundraisers::class)->listQuery($query, $user);
    }

    public static function table(Table $table): Table
    {
        $money = fn (FundraiserCampaign $record, mixed $value) => number_format((float) $value, 2).' '.$record->currency;

        return $table
            ->columns([
                TextColumn::make('name')->label('Fundraiser')->searchable()->wrap()->description(fn (FundraiserCampaign $record) => str($record->purpose)->limit(90)->toString()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => FundraiserCampaign::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'success', 'closed' => 'gray', 'cancelled' => 'danger', default => 'warning',
                    }),
                TextColumn::make('starts_on')->label('Start date')->date('d M Y')->sortable(),
                TextColumn::make('ends_on')->label('End date')->date('d M Y')->sortable(),
                TextColumn::make('target_amount')->label('Target amount')->state(fn (FundraiserCampaign $record) => $money($record, $record->target_amount)),
                TextColumn::make('collected_sum')->label('Collected')->state(fn (FundraiserCampaign $record) => $money($record, $record->collected_sum ?? 0))
                    ->description(fn (FundraiserCampaign $record) => (float) ($record->pledged_sum ?? 0) > 0 ? $money($record, $record->pledged_sum).' still to collect' : null),
                TextColumn::make('payroll_deduction')->label('Payroll')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (FundraiserCampaign $record) => $record->payroll_deduction ? 'Through payroll'.($record->deduction_month ? ' ('.$record->deductionMonthLabel().')' : '') : 'Outside payroll'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(FundraiserCampaign::STATUSES)->visible(fn () => app(Fundraisers::class)->manages(auth()->user()))])
            ->emptyStateHeading('No fundraisers yet')
            ->emptyStateDescription('Start a fundraiser to collect donations for a cause; employees pledge an amount they choose.')
            ->recordActions([
                FundraiserActions::donate(),
                ViewAction::make(),
                ActionGroup::make([FundraiserActions::edit(), FundraiserActions::open(), FundraiserActions::close(), FundraiserActions::cancel()]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $fundraisers = fn () => app(Fundraisers::class);
        $money = fn (FundraiserCampaign $record, float $value) => number_format($value, 2).' '.$record->currency;

        return $schema->components([
            Section::make('Collected against target')->columns(4)->schema([
                TextEntry::make('collected')->label('Collected')->state(fn (FundraiserCampaign $record) => $money($record, $fundraisers()->totals($record)['collected'])),
                TextEntry::make('target')->label('Target amount')->state(fn (FundraiserCampaign $record) => $money($record, (float) $record->target_amount)),
                TextEntry::make('pledged')->label('Pledged, still to collect')->state(fn (FundraiserCampaign $record) => $money($record, $fundraisers()->totals($record)['pledged'])),
                TextEntry::make('progress')->label('Progress')->state(function (FundraiserCampaign $record) use ($fundraisers) {
                    $t = $fundraisers()->totals($record);

                    return $t['percent'].'% of the target · '.$t['donors'].' '.($t['donors'] === 1 ? 'donor' : 'donors');
                }),
            ]),
            Section::make('Fundraiser')->columns(3)->schema([
                TextEntry::make('name')->label('Campaign name'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => FundraiserCampaign::STATUSES[$state] ?? $state),
                TextEntry::make('beneficiary')->placeholder('—'),
                TextEntry::make('purpose')->columnSpanFull(),
                TextEntry::make('starts_on')->label('Start date')->date('d M Y'),
                TextEntry::make('ends_on')->label('End date')->date('d M Y'),
                TextEntry::make('payroll')->label('How pledges are paid')->state(fn (FundraiserCampaign $record) => $record->payroll_deduction
                    ? 'Outside payroll, or deducted once from salary'.($record->deduction_month ? ' in '.$record->deductionMonthLabel() : ' in the month the donor chooses').' (with consent)'
                    : 'Outside payroll'),
                TextEntry::make('audience')->label('Who can donate')->columnSpan(2)->visible(fn () => $fundraisers()->manages(auth()->user()))
                    ->state(fn (FundraiserCampaign $record) => $record->status === 'draft' && $record->audience_id !== null
                        ? 'Audience: '.Audience::query()->whereKey($record->audience_id)->value('name')
                        : AudienceCriteriaSchema::describe($record->audience_criteria)),
                TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (FundraiserCampaign $record) => $record->status === 'cancelled')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PledgesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundraiserCampaigns::route('/'),
            'view' => ViewFundraiserCampaign::route('/{record}'),
        ];
    }
}
