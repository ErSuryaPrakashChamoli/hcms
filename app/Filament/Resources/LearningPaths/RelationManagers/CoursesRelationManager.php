<?php

namespace App\Filament\Resources\LearningPaths\RelationManagers;

use App\Domain\Learning\Models\Course;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Courses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('learning.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('course_id')->label('Course')->required()->searchable()->options(fn () => Course::query()->whereIn('status', Course::ENROLLABLE)->orderBy('title')->pluck('title', 'id')->all())->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('sort_order')->numeric()->default(fn () => ($this->getOwnerRecord()->items()->max('sort_order') ?? 0) + 10),
            Toggle::make('is_required')->default(true)->inline(false),
            Select::make('prerequisite_course_ids')->label('Prerequisites in this path')->multiple()->columnSpanFull()
                ->options(fn () => $this->getOwnerRecord()->items()->with('course')->get()->mapWithKeys(fn ($i) => [$i->course_id => $i->course?->title])->all()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('course'))
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('course.type')->label('Type')->badge()->color('gray'),
                IconColumn::make('is_required')->label('Required')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add course')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
