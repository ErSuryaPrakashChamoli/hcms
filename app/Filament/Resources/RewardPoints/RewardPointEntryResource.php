<?php

namespace App\Filament\Resources\RewardPoints;

use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\RewardPoints;
use App\Filament\Resources\RewardPoints\Pages\ListRewardPointEntries;
use App\Filament\Support\RewardPointActions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity HP-9d (Engage → Reward points): the reward points ledger. Employees see their own points (to give
 * and received) and give points to colleagues; HR (recognition.points) sees the budget and every employee's points in
 * their scope, deposits, adjusts and redeems. Rows are never edited; a redemption not yet paid can be cancelled.
 */
class RewardPointEntryResource extends Resource
{
    protected static ?string $model = RewardPointEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Reward points';

    protected static ?string $modelLabel = 'reward points entry';

    protected static ?string $pluralModelLabel = 'reward points';

    protected static ?string $slug = 'reward-points';

    protected static ?int $navigationSort = 63;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('recognition.points') ? 'Engagement' : 'Me';
    }

    /** HR may set the budget up before switching points on; employees see the screen once points are on. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('recognition.points') || (app(RewardPoints::class)->enabled() && $user->can('viewAny', RewardPointEntry::class)));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'counterpart.person', 'creator']);

        return auth()->user()?->can('recognition.points') ? $query
            : $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d M Y')->sortable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->placeholder('Points budget')->description(fn (RewardPointEntry $record) => $record->employee?->employee_code)
                    ->visible(fn () => auth()->user()?->can('recognition.points') ?? false),
                TextColumn::make('wallet')->badge()->color('gray')->formatStateUsing(fn (string $state) => RewardPointEntry::WALLETS[$state] ?? $state),
                TextColumn::make('kind')->label('Entry')->formatStateUsing(fn (string $state) => RewardPointEntry::KINDS[$state] ?? $state),
                TextColumn::make('points')->numeric()->color(fn (int $state) => $state < 0 ? 'danger' : 'success')->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').$state),
                TextColumn::make('counterpart.person.display_name')->label('From / to')->placeholder('—'),
                TextColumn::make('note')->label('Message')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('amount')->label('Amount')->placeholder('—')->formatStateUsing(fn (RewardPointEntry $record) => $record->amount === null ? null : number_format((float) $record->amount, 2).' '.$record->currency)
                    ->description(fn (RewardPointEntry $record) => $record->kind === 'redeem' ? ($record->payroll_run_id ? 'Paid in payroll' : 'With the next payroll') : null),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('wallet')->options(RewardPointEntry::WALLETS),
                SelectFilter::make('kind')->label('Entry')->options(RewardPointEntry::KINDS),
                Filter::make('redemptions_unpaid')->label('Redemptions not yet paid')->query(fn (Builder $query) => $query->where('kind', 'redeem')->whereNull('payroll_run_id')),
            ])
            ->emptyStateHeading('No reward points yet')
            ->emptyStateDescription('Points appear here once they are deposited, credited, given or received.')
            ->recordActions([RewardPointActions::cancelRedemption()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRewardPointEntries::route('/')];
    }
}
