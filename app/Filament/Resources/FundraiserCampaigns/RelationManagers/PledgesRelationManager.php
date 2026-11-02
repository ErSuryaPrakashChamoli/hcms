<?php

namespace App\Filament\Resources\FundraiserCampaigns\RelationManagers;

use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Filament\Support\FundraiserActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-18: a fundraiser's pledges — every one in HR's access scope (engagement.manage), only their own for an
 * employee. HR records outside payments; the donor or HR cancels a pledge still to be collected.
 */
class PledgesRelationManager extends RelationManager
{
    protected static string $relationship = 'pledges';

    protected static ?string $title = 'Pledges';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof FundraiserCampaign && (auth()->user()?->can('view', $ownerRecord) ?? false);
    }

    public function table(Table $table): Table
    {
        $manages = fn () => app(Fundraisers::class)->manages(auth()->user());

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person')
                ->when(! $manages(), fn (Builder $q) => $q->where('employee_id', app(CurrentEmployee::class)->of(auth()->user())?->id ?? 0)))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->description(fn (FundraiserPledge $record) => $record->employee?->employee_code)->visible($manages),
                TextColumn::make('amount')->state(fn (FundraiserPledge $record) => number_format((float) $record->amount, 2).' '.$record->currency),
                TextColumn::make('mode')->label('How')->formatStateUsing(fn (string $state) => FundraiserPledge::MODES[$state] ?? $state)
                    ->description(fn (FundraiserPledge $record) => $record->payroll_month ? 'Payroll of '.FundraiserCampaign::monthLabel($record->payroll_month) : null),
                TextColumn::make('consent_given_at')->label('Consent given')->dateTime('d M Y H:i')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => FundraiserPledge::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'deducted', 'paid' => 'success', 'cancelled' => 'gray', default => 'warning',
                    })
                    ->description(fn (FundraiserPledge $record) => match ($record->status) {
                        'paid' => 'On '.$record->paid_on?->format('d M Y').($record->payment_reference ? ' · '.$record->payment_reference : ''),
                        'deducted' => 'On '.$record->deducted_at?->format('d M Y'),
                        'cancelled' => $record->cancel_reason,
                        default => null,
                    }),
                TextColumn::make('created_at')->label('Pledged on')->date('d M Y')->sortable(),
                TextColumn::make('note')->label('Message')->limit(60)->wrap()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(FundraiserPledge::STATUSES),
                SelectFilter::make('mode')->label('How')->options(FundraiserPledge::MODES),
            ])
            ->emptyStateHeading('No pledges yet')
            ->recordActions([FundraiserActions::recordPayment(), FundraiserActions::cancelPledge()]);
    }
}
