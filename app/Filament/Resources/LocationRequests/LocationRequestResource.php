<?php

namespace App\Filament\Resources\LocationRequests;

use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\LocationRequests\Pages\ListLocationRequests;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
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

/** HROne parity HP-1: employees ask to check in from a place; approval adds it as their geo work location. */
class LocationRequestResource extends Resource
{
    protected static ?string $model = LocationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Location requests';

    protected static ?int $navigationSort = 71;

    /** HROne parity TX-1: date ranges of the register (filter name => [label, column]). */
    public const DATE_FILTERS = [
        'request_dates' => ['Raised', 'created_at'],
        'decision_dates' => ['Decided', 'reviewed_at'],
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('attendance.view') ? 'Attendance' : 'Me';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'requester']);

        return auth()->user()?->can('attendance.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $decide = fn (bool $approve) => function (LocationRequest $record, array $data) use ($approve) {
            try {
                $approve ? app(GeoLocations::class)->approve($record, $data['note'] ?? null) : app(GeoLocations::class)->reject($record, $data['note']);
                Notification::make()->success()->title($approve ? 'Location approved' : 'Location rejected')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (LocationRequest $r) => $r->employee?->employee_code),
                TextColumn::make('name')->label('Place'),
                TextColumn::make('coordinates')->state(fn (LocationRequest $r) => sprintf('%.5f, %.5f', $r->latitude, $r->longitude))->description(fn (LocationRequest $r) => $r->radius_m.' m'),
                TextColumn::make('valid_until')->label('Needed')->state(fn (LocationRequest $r) => $r->valid_from || $r->valid_until ? ($r->valid_from?->format('d M Y') ?? '…').' → '.($r->valid_until?->format('d M Y') ?? '…') : 'Ongoing'),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning'
                }),
                TextColumn::make('created_at')->label('Raised')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LocationRequest::STATUSES),
                // HROne parity TX-1: the WO-2 employee filters and raised / decided ranges (HR side only).
                ...TimeOfficeRegisters::filters('attendance.view', self::DATE_FILTERS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (LocationRequest $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($decide(true)),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (LocationRequest $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($decide(false)),
                Action::make('withdraw')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (LocationRequest $r) => $r->status === 'pending' && Employee::query()->whereKey($r->employee_id)->value('user_id') === auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (LocationRequest $record) => app(GeoLocations::class)->cancel($record, 'Withdrawn by the employee')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLocationRequests::route('/')];
    }
}
