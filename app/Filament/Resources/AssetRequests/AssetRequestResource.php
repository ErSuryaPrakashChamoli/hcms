<?php

namespace App\Filament\Resources\AssetRequests;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Policies\AssetRequestPolicy;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\AssetRequests\Pages\ListAssetRequests;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity AS-B: asset requests — the asset team's queue under Assets (everything in scope, with what is due
 * and overdue), and under Me the employee's own requests, those they raised for their team and those their role fulfils
 * (My asset requests). Approve / reject (the line manager or the asset team), fulfil or decline (the asset team or the
 * asset type's owner role), cancel.
 */
class AssetRequestResource extends Resource
{
    protected static ?string $model = AssetRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?string $modelLabel = 'asset request';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function team(): bool
    {
        return auth()->user() !== null && AssetRequestPolicy::team(auth()->user());
    }

    public static function getNavigationGroup(): ?string
    {
        return static::team() ? 'Assets' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return static::team() ? 'Asset requests' : 'My asset requests';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'category', 'subtype', 'model', 'asset', 'fulfilledAsset']);
        if (static::team()) {
            return $query;
        }
        $user = auth()->user();
        $relationships = app(PerformanceRelationships::class);
        $mine = Employee::query()->select('id')->where('user_id', $user->id);
        $team = $relationships->reportIds($relationships->forUser($user))->all();
        $roles = $user->roles()->pluck('roles.id')->all();

        return $query->where(fn (Builder $q) => $q->whereIn('employee_id', $mine)->orWhere('requested_by', $user->id)
            ->when($team !== [], fn ($q) => $q->orWhereIn('employee_id', $team))
            ->when($roles !== [], fn ($q) => $q->orWhere(fn ($q) => $q->whereIn('owner_role_id', $roles)->whereIn('status', AssetRequest::OPEN))));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset request')->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('kind')->formatStateUsing(fn (string $state) => AssetRequest::KINDS[$state] ?? $state),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => AssetRequest::STATUSES[$state] ?? $state),
                TextEntry::make('asset_type')->label('Asset')->state(fn (AssetRequest $r) => $r->describe()),
                TextEntry::make('asset.asset_tag')->label('Asset to return or replace')->placeholder('—'),
                TextEntry::make('fulfilledAsset.asset_tag')->label('Asset handed over / taken back')->placeholder('—'),
                TextEntry::make('source')->label('Raised by')->formatStateUsing(fn (string $state) => AssetRequest::SOURCES[$state] ?? $state),
                TextEntry::make('needed_by')->label('Needed by')->date()->placeholder('—'),
                TextEntry::make('due_on')->label('Due on')->date()->placeholder('—'),
                TextEntry::make('ownerRole.name')->label('Fulfilled by (role)')->placeholder('—'),
                TextEntry::make('reason')->columnSpanFull()->placeholder('—'),
                TextEntry::make('decision_note')->label('Decision note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('fulfilment_note')->label('Fulfilment note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->columnSpanFull()->visible(fn (AssetRequest $r) => filled($r->cancel_reason)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $requests = fn () => app(AssetRequests::class);
        $run = function (callable $call, string $done) {
            try {
                $call();
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('kind')->formatStateUsing(fn (string $state) => AssetRequest::KINDS[$state] ?? $state),
                TextColumn::make('asset_type')->label('Asset')->state(fn (AssetRequest $r) => $r->describe().($r->asset ? ' ['.$r->asset->asset_tag.']' : '')),
                TextColumn::make('source')->label('Raised by')->formatStateUsing(fn (string $state) => AssetRequest::SOURCES[$state] ?? $state)->toggleable(),
                TextColumn::make('needed_by')->label('Needed by')->date()->placeholder('—')->toggleable(),
                TextColumn::make('due_on')->label('Due on')->date()->placeholder('—')->color(fn (AssetRequest $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => AssetRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'fulfilled' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(AssetRequest::STATUSES)->multiple(),
                SelectFilter::make('kind')->options(AssetRequest::KINDS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (AssetRequest $r) => $r->status === 'requested' && $requests()->canApprove(auth()->user(), $r))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(fn (AssetRequest $record, array $data) => $run(fn () => $requests()->approve($record, $data['note'] ?? null), 'Asset request approved')),
                Action::make('reject')->label(fn (AssetRequest $r) => $r->status === 'approved' ? 'Decline' : 'Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (AssetRequest $r) => ($r->status === 'requested' && $requests()->canApprove(auth()->user(), $r)) || ($r->status === 'approved' && $requests()->canFulfil(auth()->user(), $r)))
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (AssetRequest $record, array $data) => $run(fn () => $requests()->reject($record, $data['note']), 'Asset request rejected')),
                Action::make('fulfil')->icon('heroicon-m-check-badge')->color('primary')
                    ->visible(fn (AssetRequest $r) => $r->status === 'approved' && $requests()->canFulfil(auth()->user(), $r))
                    ->schema(fn (AssetRequest $record) => [
                        Select::make('asset_id')->label('Asset to hand over')->native(false)->searchable()->visible($record->kind !== 'return')->required($record->kind !== 'return')
                            ->options(fn () => Asset::query()->where('status', 'in_stock')->where('asset_category_id', $record->asset_category_id)
                                ->when($record->asset_subtype_id, fn ($q) => $q->where('asset_subtype_id', $record->asset_subtype_id))
                                ->when($record->asset_model_id, fn ($q) => $q->where('asset_model_id', $record->asset_model_id))
                                ->orderBy('asset_tag')->limit(200)->get()->mapWithKeys(fn (Asset $a) => [$a->id => $a->asset_tag.' · '.$a->name.($a->serial_number ? ' · '.$a->serial_number : '')])->all()),
                        Select::make('condition')->label('Condition of the asset taken back')->options(config('peopleos.assets.conditions'))->default('good')->visible($record->kind !== 'new'),
                        Textarea::make('note')->maxLength(255),
                    ])
                    ->action(fn (AssetRequest $record, array $data) => $run(fn () => $requests()->fulfil($record, filled($data['asset_id'] ?? null) ? Asset::query()->find($data['asset_id']) : null,
                        ['condition' => $data['condition'] ?? null, 'note' => $data['note'] ?? null]), 'Asset request fulfilled')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (AssetRequest $r) => $requests()->canCancel(auth()->user(), $r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (AssetRequest $record, array $data) => $run(fn () => $requests()->cancel($record, $data['reason']), 'Asset request cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAssetRequests::route('/')];
    }
}
