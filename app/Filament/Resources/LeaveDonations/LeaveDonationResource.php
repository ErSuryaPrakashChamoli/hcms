<?php

namespace App\Filament\Resources\LeaveDonations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveDonation;
use App\Domain\Leave\Services\LeaveDonations;
use App\Filament\Resources\LeaveDonations\Pages\ListLeaveDonations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/** HROne parity HP-1: leave given to a colleague (donor and recipient each see it; approvers decide). */
class LeaveDonationResource extends Resource
{
    protected static ?string $model = LeaveDonation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Leave donations';

    protected static ?int $navigationSort = 35;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('leave.view') ? 'Leave' : 'Me';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'recipient.person', 'leaveType', 'reviewer']);
        if (auth()->user()?->can('leave.view')) {
            return $query;
        }
        $mine = Employee::query()->select('id')->where('user_id', auth()->id());

        return $query->where(fn (Builder $q) => $q->whereIn('employee_id', $mine)->orWhereIn('recipient_employee_id', $mine));
    }

    public static function table(Table $table): Table
    {
        $decide = fn (bool $approve) => function (LeaveDonation $record, array $data) use ($approve) {
            try {
                $approve ? app(LeaveDonations::class)->approve($record, $data['note'] ?? null) : app(LeaveDonations::class)->reject($record, $data['note']);
                Notification::make()->success()->title($approve ? 'Donation approved' : 'Donation rejected')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('From'),
                TextColumn::make('recipient.person.display_name')->label('To'),
                TextColumn::make('leaveType.name')->label('Leave')->badge(),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning'
                }),
                TextColumn::make('reviewer.name')->label('Decided by')->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveDonation::STATUSES)])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (LeaveDonation $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($decide(true)),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (LeaveDonation $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($decide(false)),
                Action::make('withdraw')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (LeaveDonation $r) => $r->status === 'pending' && Employee::query()->whereKey($r->employee_id)->value('user_id') === auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (LeaveDonation $record) => app(LeaveDonations::class)->cancel($record, 'Withdrawn by the donor')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveDonations::route('/')];
    }
}
