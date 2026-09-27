<?php

namespace App\Filament\Resources\AuditEvents\Tables;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('action')->badge()->formatStateUsing(fn (AuditAction $state) => $state->label()),
                TextColumn::make('module')->badge()->color('gray'),
                TextColumn::make('entity_type')->label('Record type')->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                TextColumn::make('entity_label')->label('Record')->searchable()->placeholder('—'),
                TextColumn::make('actor_name')->label('By')->searchable()->placeholder('System'),
                TextColumn::make('reason')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->options(collect(AuditAction::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())->multiple(),
                SelectFilter::make('module')->options(fn () => AuditEvent::query()->distinct()->orderBy('module')->pluck('module', 'module')->all())->multiple(),
                Filter::make('occurred_between')
                    ->schema([
                        DatePicker::make('from')->native(false),
                        DatePicker::make('until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
