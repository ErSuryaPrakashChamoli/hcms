<?php

namespace App\Filament\Resources\SalaryStructures\RelationManagers;

use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Components';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('salary_component_id')->label('Component')
                ->options(fn () => SalaryComponent::query()->where('status', 'active')->where('is_statutory', false)->orderBy('sort_order')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->code})"])->all())
                ->required()->searchable()
                ->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('sort_order')->numeric()->default(fn () => ($this->getOwnerRecord()->items()->max('sort_order') ?? 0) + 10),
            Textarea::make('formula_override')->rows(2)->helperText('Optional: replaces the component formula within this structure')
                ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                    if (blank($value)) {
                        return;
                    }
                    try {
                        app(FormulaEngine::class)->validate((string) $value);
                    } catch (RuntimeException $e) {
                        $fail($e->getMessage());
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('component'))
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('component.name')->label('Component'),
                TextColumn::make('component.code')->label('Code'),
                TextColumn::make('component.type')->label('Type')->badge()->color('gray'),
                TextColumn::make('component.calculation_method')->label('Method')->badge()->color('gray'),
                TextColumn::make('effective_formula')->label('Formula')->state(fn ($record) => $record->formula_override ?: ($record->component->calculation_method === 'formula' ? $record->component->formula : 'fixed from assignment'))->wrap(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add component')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
