<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Phase 14 Change Intelligence: who changed what, when, why and through which approval, integration,
 * request or bulk operation. It reads the existing audit trail through ChangeIntelligence (organisation
 * scope and value masking applied); it stores nothing.
 */
class ChangeIntelligencePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Change intelligence';

    protected static ?string $title = 'Change intelligence';

    protected static ?string $slug = 'change-intelligence';

    protected string $view = 'filament.pages.change-intelligence';

    #[Url]
    public ?int $employee = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('audit.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ChangeIntelligence::class)->query(auth()->user(), ['employee_id' => $this->employee]))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime(),
                TextColumn::make('action')->formatStateUsing(fn ($state) => $state instanceof AuditAction ? $state->label() : (string) $state)->badge()->color('gray'),
                TextColumn::make('module')->badge()->color('gray'),
                TextColumn::make('entity_label')->label('Record')->wrap()->limit(60),
                TextColumn::make('actor_name')->label('Who')->placeholder('System'),
                TextColumn::make('reason')->label('Why')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')->label('Correlation')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('operation_id')->label('Operation')->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('scope')->columns(4)->columnSpanFull()->schema([
                    Select::make('employee_id')->label('Employee')->searchable()->getSearchResultsUsing(fn (string $search) => Employee::query()->where('employee_code', 'like', "%{$search}%")->limit(20)->pluck('employee_code', 'id')->all()),
                    Select::make('department_id')->label('Department')->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('module')->options(fn () => AuditEvent::query()->distinct()->orderBy('module')->pluck('module', 'module')->all()),
                    Select::make('action')->multiple()->options(collect(AuditAction::cases())->mapWithKeys(fn (AuditAction $a) => [$a->value => $a->label()])->all()),
                    DatePicker::make('from'), DatePicker::make('to'),
                    TextInput::make('source')->placeholder('admin_control_centre, api:…, console'),
                    TextInput::make('correlation_id')->label('Correlation / request id'),
                    TextInput::make('operation_id'),
                    TextInput::make('entity_id')->label('Record id'),
                ])->query(fn (Builder $query, array $data) => $this->applyFilters($query, $data)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                Action::make('explain')->label('Explain')->icon('heroicon-m-light-bulb')->color('gray')->modalSubmitAction(false)
                    ->schema(function (AuditEvent $record) {
                        $d = app(ChangeIntelligence::class)->describe($record, auth()->user());

                        return [
                            TextEntry::make('what')->state($d['what']), TextEntry::make('who')->state($d['who']), TextEntry::make('when')->state($d['when']),
                            TextEntry::make('why')->state($d['why'] ?? '—'), TextEntry::make('approval')->state($d['approval'] ?? '—'),
                            TextEntry::make('integration')->state($d['integration'] ?? '—'), TextEntry::make('correlation')->state($d['correlation_id'] ?? '—'),
                            TextEntry::make('operation')->state($d['operation_id'] ?? '—'),
                            KeyValueEntry::make('changes')->state(collect($d['changes'])->mapWithKeys(fn ($c) => [$c['field'] => ($c['before'] ?? '∅').' → '.($c['after'] ?? '∅')])->all()),
                        ];
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    private function applyFilters(Builder $query, array $data): Builder
    {
        $filters = array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if ($filters === []) {
            return $query;
        }
        $filtered = app(ChangeIntelligence::class)->query(auth()->user(), $filters);

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $filtered->select('audit_events.id')->reorder()->toBase());
    }
}
