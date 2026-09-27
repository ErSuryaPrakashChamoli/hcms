<?php

namespace App\Filament\Resources\Policies\RelationManagers;

use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\ImpactPreview;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** IF conditions THEN this policy. */
class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'assignmentRules';

    protected static ?string $title = 'Assignment rules';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('priority')->numeric()->default(100)->helperText('Lower wins when several rules match.'),
                Radio::make('match')->options(['all' => 'All conditions', 'any' => 'Any condition'])->default('all')->inline(),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ]),
            RuleConditionsSchema::repeater(),
            AuditReasonField::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('conditions')->label('IF')->state(fn (PolicyAssignmentRule $record) => RuleConditionsSchema::describe($record->conditions ?? [], $record->match))->wrap(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->placeholder('—')->toggleable(),
                TextColumn::make('effective_to')->date()->placeholder('Open')->toggleable(),
            ])
            ->defaultSort('priority')
            ->headerActions([
                CreateAction::make()->modalWidth('4xl')->using(function (array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);
                    $rule = new PolicyAssignmentRule($data + ['policy_id' => $livewire->getOwnerRecord()->id, 'policy_type' => $livewire->getOwnerRecord()->type]);
                    $rule->withAuditReason($reason)->save();

                    return $rule;
                }),
            ])
            ->recordActions([
                Action::make('impact')
                    ->label('Impact')
                    ->icon('heroicon-m-users')
                    ->action(function (PolicyAssignmentRule $record) {
                        $impact = app(ImpactPreview::class)->for($record);
                        Notification::make()->info()->title($impact['summary'])->body(collect($impact['details']['departments'] ?? [])->map(fn ($n, $d) => "{$d}: {$n}")->implode(', '))->send();
                    }),
                EditAction::make()->modalWidth('4xl')->using(function (PolicyAssignmentRule $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }
}
