<?php

namespace App\Filament\Support;

use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\Returns\ReturnGenerators;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Shared Filament resource for one statutory return type (Part T). Subclasses set $returnType.
 * Entries are shown masked; unmasked identifiers need compliance.sensitive.view and every view of a
 * return's entries is recorded as STATUTORY_OUTPUT_ACCESSED.
 */
abstract class StatutoryReturnResourceBase extends Resource
{
    protected static ?string $model = StatutoryReturn::class;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static string $returnType = '';

    public static function returnType(): string
    {
        return static::$returnType;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('return_type', static::$returnType)->with(['establishment', 'legalEntity', 'generator', 'approver']);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'validated', 'approved' => 'info',
            'exported' => 'warning',
            'submitted', 'acknowledged', 'reconciled' => 'success',
            'reconciliation_required' => 'danger',
            'cancelled', 'revised' => 'gray',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_key')->label('Period')->sortable(),
                TextColumn::make('establishment.name')->label('Establishment')->placeholder('Legal entity level'),
                TextColumn::make('legalEntity.legal_name')->label('Legal entity')->toggleable(),
                TextColumn::make('state_code')->label('State')->placeholder('—')->toggleable(),
                TextColumn::make('return_kind')->label('Kind')->formatStateUsing(fn (string $state, StatutoryReturn $record) => $state.($record->sequence > 1 ? ' #'.$record->sequence : '')),
                TextColumn::make('status')->badge()->color(fn (string $state) => static::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.compliance.return_statuses.{$state}", $state)),
                TextColumn::make('totals.entries')->label('Lines')->placeholder('—'),
                TextColumn::make('blocking_count')->label('Blocking')->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('external_reference')->label('Portal ref.')->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('status')->options(config('peopleos.compliance.return_statuses'))])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Return')->columns(4)->schema([
                TextEntry::make('form_code')->label('Form')->formatStateUsing(fn (string $state, StatutoryReturn $record) => $state.($record->legacy_form_code ? " (formerly {$record->legacy_form_code})" : '')),
                TextEntry::make('period_key')->label('Period'),
                TextEntry::make('establishment.name')->label('Establishment')->placeholder('Legal entity level'),
                TextEntry::make('legalEntity.legal_name')->label('Legal entity'),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.compliance.return_statuses.{$state}", $state)),
                TextEntry::make('return_kind')->label('Kind'),
                TextEntry::make('exportLayout.status')->label('Export layout')->formatStateUsing(fn (?string $state, StatutoryReturn $record) => ($record->exportLayout?->label() ?? '—').' — '.($state ?? 'none'))->badge()->color(fn (?string $state) => $state === 'verified' ? 'success' : 'warning')->placeholder('None'),
                TextEntry::make('local_validation')->label('File structure')->state(fn (StatutoryReturn $record) => $record->local_validation === null ? 'Not exported' : (($record->local_validation['valid'] ?? false) ? 'Valid against '.$record->local_validation['layout'] : ($record->local_validation['issue_count'] ?? 0).' issue(s)')),
                TextEntry::make('portal_validation_result')->label('Portal validation')->formatStateUsing(fn (?string $state, StatutoryReturn $record) => $state ? "{$state} ({$record->portal_validation_reference})" : null)->placeholder('Not recorded'),
                TextEntry::make('reconciliation_status')->label('Reconciliation')->placeholder('—')->badge(),
                TextEntry::make('generator.name')->label('Generated by')->placeholder('—'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('external_reference')->label('Portal reference')->placeholder('Not filed'),
                TextEntry::make('acknowledgement_reference')->label('Acknowledgement')->placeholder('—'),
                TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Totals')->collapsible()->schema([
                TextEntry::make('totals')->hiddenLabel()->state(fn (StatutoryReturn $record) => collect($record->totals ?? [])->map(fn ($v, $k) => "{$k}: ".(is_numeric($v) ? number_format((float) $v, 2) : json_encode($v)))->values()->all())->listWithLineBreaks(),
            ]),
            Section::make('Validation')->collapsible()->schema([
                TextEntry::make('validation')->hiddenLabel()->placeholder('Not validated yet')
                    ->state(fn (StatutoryReturn $record) => collect($record->validation ?? [])->map(fn ($i) => strtoupper($i['severity']).' · '.$i['code'].' — '.$i['message'])->values()->all())->listWithLineBreaks(),
            ]),
            Section::make('Reconciliation')->collapsible()->collapsed()->schema([
                TextEntry::make('reconciliations')->hiddenLabel()->placeholder('None')
                    ->state(fn (StatutoryReturn $record) => $record->reconciliations()->get()->flatMap(fn ($r) => collect($r->checks)->map(fn ($c) => "{$r->stage} · {$c['check']}: expected {$c['expected']}, actual {$c['actual']}".($c['blocking'] ? ' — MISMATCH' : '')))->values()->all())->listWithLineBreaks(),
            ]),
            Section::make('Entries (masked)')->collapsible()->collapsed()->schema([
                TextEntry::make('entries')->hiddenLabel()->placeholder('None')
                    ->state(fn (StatutoryReturn $record) => app(ReturnGenerators::class)->for($record->return_type)->entries($record)->limit(500)->get()
                        ->map(fn ($e) => collect(app(ReturnGenerators::class)->for($record->return_type)->present($e, false))->except(['issues', 'calculated', 'rule'])->map(fn ($v, $k) => is_array($v) ? $k.'='.json_encode($v) : "{$k}={$v}")->implode(' · '))->all())->listWithLineBreaks(),
            ]),
            Section::make('Actions')->collapsible()->collapsed()->schema([
                TextEntry::make('actions')->hiddenLabel()->state(fn (StatutoryReturn $record) => $record->actions()->with('user')->get()->map(fn ($a) => $a->created_at?->format('Y-m-d H:i').' · '.$a->action.' '.($a->from_status ?? '∅').' → '.($a->to_status ?? '∅').' · '.($a->user?->name ?? 'system').' · '.$a->source.($a->reason ? ' · '.$a->reason : ''))->all())->listWithLineBreaks(),
            ]),
        ]);
    }
}
