<?php

namespace App\Filament\Resources\ConfigurationCloneOperations;

use App\Domain\Configuration\Cloning\CloneMode;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Cloning\CloneScope;
use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ConfigurationCloneOperations\Pages\ListConfigurationCloneOperations;
use App\Filament\Resources\ConfigurationCloneOperations\Pages\ViewConfigurationCloneOperation;
use App\Filament\Support\Cloning\CloneLinks;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
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
 * Clone history (configuration cloning, FR-22): every clone attempt — completed, failed or refused — with its source,
 * destinations, mode, who made it and what it created; a row opens the full manifest. Read-only, exportable.
 */
class ConfigurationCloneOperationResource extends Resource
{
    protected static ?string $model = ConfigurationCloneOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Clone history';

    protected static ?string $modelLabel = 'clone';

    protected static ?string $pluralModelLabel = 'clone history';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    /** A person limited to some companies sees the clones they made and those that wrote into their companies. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('initiator');
        $user = auth()->user();
        $mine = $user instanceof User ? app(CloneScope::class)->companiesFor($user) : [];
        if ($mine === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($mine, $user) {
            $q->where('initiated_by', $user?->id ?? 0);
            foreach ($mine as $company) {
                $q->orWhereJsonContains('destination_company_ids', $company);
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('initiator.name')->label('By')->placeholder('—'),
                TextColumn::make('adapter')->label('What')->formatStateUsing(fn (string $state) => app(CloneRegistry::class)->label($state)),
                TextColumn::make('source_label')->label('Source')->searchable()->wrap(),
                TextColumn::make('destinations')->label('Companies')->state(fn (ConfigurationCloneOperation $record) => implode(', ', $record->destinationNames()))->wrap(),
                TextColumn::make('mode')->formatStateUsing(fn (string $state) => CloneMode::tryFrom($state)?->label() ?? $state)->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ConfigurationCloneOperation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ConfigurationCloneOperation::COMPLETED => 'success', ConfigurationCloneOperation::FAILED => 'danger',
                        ConfigurationCloneOperation::REFUSED => 'warning', default => 'gray',
                    }),
                TextColumn::make('created')->label('Created')->state(fn (ConfigurationCloneOperation $record) => count((array) ($record->result['created'] ?? [])) ?: null)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('adapter')->label('What')->options(fn () => app(CloneRegistry::class)->labels()),
                SelectFilter::make('status')->options(ConfigurationCloneOperation::STATUSES),
                SelectFilter::make('initiated_by')->label('By')->relationship('initiator', 'name')->searchable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Clone')->columns(4)->schema([
                TextEntry::make('adapter')->label('What')->formatStateUsing(fn (string $state) => app(CloneRegistry::class)->label($state)),
                TextEntry::make('source_label')->label('Source')
                    ->url(fn (ConfigurationCloneOperation $record) => CloneLinks::record($record->adapter, (int) $record->source_id)),
                TextEntry::make('mode')->formatStateUsing(fn (string $state) => CloneMode::tryFrom($state)?->label() ?? $state),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ConfigurationCloneOperation::STATUSES[$state] ?? $state),
                TextEntry::make('initiator.name')->label('By')->placeholder('—'),
                TextEntry::make('started_at')->label('Started')->dateTime(),
                TextEntry::make('completed_at')->label('Ended')->dateTime()->placeholder('—'),
                TextEntry::make('destinations')->label('Companies')->state(fn (ConfigurationCloneOperation $record) => implode(', ', $record->destinationNames())),
                TextEntry::make('error')->label('Why it stopped')->placeholder('—')->columnSpanFull(),
                TextEntry::make('source_checksum')->label('Source checksum')->copyable()->columnSpanFull(),
                TextEntry::make('audit_operation_id')->label('Audit operation')->placeholder('—')->copyable()->columnSpanFull(),
            ]),
            Section::make('Created')->schema([
                RepeatableEntry::make('copies')->hiddenLabel()->placeholder('Nothing was created.')
                    ->state(fn (ConfigurationCloneOperation $record) => $record->lineage()->where('role', ConfigurationLineage::ROOT)->with('destinationCompany')->get()
                        ->map(fn (ConfigurationLineage $l) => ['company' => $l->destinationCompany?->name ?? '—', 'record' => self::link($record->adapter, (int) $l->target_id)])->all())
                    ->columns(2)
                    ->schema([
                        TextEntry::make('company'),
                        TextEntry::make('record')->label('Copy')->html(),
                    ]),
            ]),
            Section::make('Decisions')->description('Every dependency, each company\'s applicability before → after, and the conflicts found.')->collapsed()->schema([
                TextEntry::make('plan_references')->label('Dependencies')->state(fn (ConfigurationCloneOperation $record) => collect((array) ($record->plan['references'] ?? []))
                    ->map(fn (array $r) => "{$r['label']}: ".($r['target_label'] ?? $r['value']).' — '.$r['strategy'])->all() ?: ['None'])->listWithLineBreaks(),
                TextEntry::make('plan_applicability')->label('Applicability after')->state(fn (ConfigurationCloneOperation $record) => collect((array) ($record->plan['applicability'] ?? []))
                    ->flatMap(fn (array $a, $company) => array_map(fn (string $rule) => "#{$company}: {$rule}", CloneLinks::rules((array) ($a['after'] ?? []))))->all() ?: ['—'])->listWithLineBreaks(),
                TextEntry::make('plan_notes')->label('Notes')->state(fn (ConfigurationCloneOperation $record) => collect((array) ($record->plan['applicability'] ?? []))->flatMap(fn (array $a) => (array) ($a['notes'] ?? []))->all() ?: ['—'])->listWithLineBreaks(),
                TextEntry::make('plan_conflicts')->label('Conflicts')->state(fn (ConfigurationCloneOperation $record) => collect((array) ($record->plan['conflicts'] ?? $record->result['conflicts'] ?? []))
                    ->map(fn (array $c) => ucfirst($c['severity']).': '.$c['message'])->all() ?: ['None'])->listWithLineBreaks(),
            ]),
        ]);
    }

    /** The copy as a link to its page, named by its code (or its id when it is gone). */
    private static function link(string $adapter, int $id): string
    {
        $copy = app(CloneRegistry::class)->has($adapter) ? app(CloneRegistry::class)->for($adapter)->find($id) : null;
        $label = e((string) ($copy?->getAttribute('code') ?? $copy?->getAttribute('name') ?? "#{$id} (deleted)"));
        $url = $copy === null ? null : CloneLinks::record($adapter, $id);

        return $url === null ? $label : '<a class="underline" href="'.e($url).'">'.$label.'</a>';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfigurationCloneOperations::route('/'),
            'view' => ViewConfigurationCloneOperation::route('/{record}'),
        ];
    }
}
