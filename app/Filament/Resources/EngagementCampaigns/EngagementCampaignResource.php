<?php

namespace App\Filament\Resources\EngagementCampaigns;

use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Services\Campaigns;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\EngagementCampaigns\Pages\CreateEngagementCampaign;
use App\Filament\Resources\EngagementCampaigns\Pages\EditEngagementCampaign;
use App\Filament\Resources\EngagementCampaigns\Pages\ListEngagementCampaigns;
use App\Filament\Resources\EngagementCampaigns\RelationManagers\ItemsRelationManager;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 13: engagement campaigns group surveys, announcements, Knowledge Base articles and HR
 * services by reference. Approved by a second person; launching publishes each approved item through
 * its own module and reports anything not ready as skipped.
 */
class EngagementCampaignResource extends Resource
{
    protected static ?string $model = EngagementCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(32)->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(255),
                DatePicker::make('starts_on')->native(false)->required(),
                DatePicker::make('ends_on')->native(false),
                Select::make('audience_id')->label('Audience (for reference)')->placeholder('—')->options(fn () => Audience::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Textarea::make('purpose')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (callable $callback, string $done) => ServiceDeskActions::run($callback, $done);

        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.engagement.campaign_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'scheduled', 'approved' => 'info', 'in_review' => 'warning', 'cancelled' => 'danger', default => 'gray'
                    }),
                TextColumn::make('starts_on')->date(),
                TextColumn::make('ends_on')->date()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.engagement.campaign_statuses'))])
            ->recordActions([
                EditAction::make()->label('Manage'),
                Action::make('stats')->label('Metrics')->icon('heroicon-m-chart-bar')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (EngagementCampaign $record) => collect(app(Campaigns::class)->stats($record))->map(fn ($n, $k) => TextEntry::make($k)->label(ucfirst(str_replace('_', ' ', $k)))->state($n))->values()->all()),
                ActionGroup::make([
                    Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                        ->visible(fn (EngagementCampaign $record) => $record->status === 'draft' && auth()->user()->can('engagement.manage'))
                        ->action(fn (EngagementCampaign $record) => $run(fn () => app(Campaigns::class)->submit($record, auth()->user()), 'Submitted')),
                    Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                        ->visible(fn (EngagementCampaign $record) => $record->status === 'in_review' && ! $record->workflow_instance_id && auth()->user()->can('engagement.approve') && (int) $record->prepared_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')])
                        ->action(fn (EngagementCampaign $record, array $data) => $run(fn () => app(Campaigns::class)->approve($record, $data['note'] ?? null, auth()->user()), 'Approved')),
                    Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (EngagementCampaign $record) => in_array($record->status, ['in_review', 'approved'], true) && auth()->user()->can('engagement.approve'))
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (EngagementCampaign $record, array $data) => $run(fn () => app(Campaigns::class)->returnToDraft($record, $data['note'], auth()->user()), 'Returned')),
                    Action::make('schedule')->label('Schedule')->icon('heroicon-m-calendar')->color('success')->requiresConfirmation()
                        ->modalDescription('On its start date, every approved item is published by its own module; items that are not approved are reported as skipped.')
                        ->visible(fn (EngagementCampaign $record) => $record->status === 'approved')
                        ->action(fn (EngagementCampaign $record) => $run(fn () => app(Campaigns::class)->schedule($record, auth()->user()), 'Scheduled')),
                    Action::make('cancel')->label('Cancel')->icon('heroicon-m-x-mark')->color('danger')
                        ->visible(fn (EngagementCampaign $record) => ! in_array($record->status, ['completed', 'cancelled'], true) && (auth()->user()->can('engagement.manage') || auth()->user()->can('engagement.approve')))
                        ->schema([Textarea::make('reason')->required()])
                        ->action(fn (EngagementCampaign $record, array $data) => $run(fn () => app(Campaigns::class)->cancel($record, $data['reason'], auth()->user()), 'Cancelled')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEngagementCampaigns::route('/'), 'create' => CreateEngagementCampaign::route('/create'), 'edit' => EditEngagementCampaign::route('/{record}/edit')];
    }
}
