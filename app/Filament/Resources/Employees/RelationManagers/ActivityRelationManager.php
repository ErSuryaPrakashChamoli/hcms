<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Filament\Pages\ChangeIntelligencePage;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-2 ("Activity log" on an employee): everything that happened about this employee across modules — the
 * employee record and every record linked to them (positions, leave, letters, exits, e-mails sent …) — from the audit
 * trail, through ChangeIntelligence: the viewer's access scope applies and classified values are masked for people
 * without employee.sensitive.view. Needs audit.view (the same as Change Intelligence).
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'auditEvents';

    protected static ?string $title = 'Activity';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasPermission('audit.view') ?? false;
    }

    public function table(Table $table): Table
    {
        $intelligence = app(ChangeIntelligence::class);
        $viewer = auth()->user();

        return $table
            ->query(fn () => $intelligence->query($viewer, ['employee_id' => $this->getOwnerRecord()->getKey()]))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('action')->label('What')->badge()->color('gray')->formatStateUsing(fn (AuditAction $state) => $state->label())
                    ->description(fn (AuditEvent $record) => $record->entity_label),
                TextColumn::make('module')->formatStateUsing(fn (?string $state) => ucfirst(str_replace('_', ' ', (string) $state)))->toggleable(),
                TextColumn::make('actor_name')->label('By')->placeholder('System'),
                TextColumn::make('changes')->label('Changes')
                    ->state(fn (AuditEvent $record) => collect($intelligence->describe($record, $viewer)['changes'])->map(fn (array $c) => $c['field'].': '.($c['before'] ?? '∅').' → '.($c['after'] ?? '∅'))->all())
                    ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->placeholder('—'),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('module')->options(fn () => $intelligence->query($viewer, ['employee_id' => $this->getOwnerRecord()->getKey()])->reorder()->toBase()
                    ->distinct()->orderBy('module')->limit(60)->pluck('module', 'module')->map(fn ($m) => ucfirst(str_replace('_', ' ', (string) $m)))->all()),
            ])
            ->headerActions([
                Action::make('allChanges')->label('Open in Change Intelligence')->icon('heroicon-m-magnifying-glass-circle')->color('gray')
                    ->url(fn () => ChangeIntelligencePage::getUrl(['employee' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
