<?php

namespace App\Filament\Resources\ApiKeys\RelationManagers;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Services\ApiRequestLogs;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HROne parity CA-11 ("Logs → API Calls"): every call made with the key — date, method, path, status, duration, IP and user
 * agent (never bodies, headers or query strings) — with filters and a queued CSV export.
 */
class RequestLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'requestLogs';

    protected static ?string $title = 'API calls';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('method')->badge()->color('gray'),
                TextColumn::make('path')->label('Request URL')->fontFamily('mono')->searchable()->wrap(),
                TextColumn::make('status')->label('HTTP status')->badge()->sortable()
                    ->color(fn (int $state) => match (true) {
                        $state >= 500 => 'danger', $state >= 400 => 'warning', default => 'success'
                    }),
                TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->numeric()->sortable(),
                TextColumn::make('ip')->label('IP address')->placeholder('—'),
                TextColumn::make('route_name')->label('Action')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')->label('User agent')->limit(60)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('dates')->columns(2)->schema([
                    DatePicker::make('from')->native(false),
                    DatePicker::make('until')->native(false),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
                    ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', Carbon::parse($until)->endOfDay()))),
                SelectFilter::make('method')->options(ApiRequestLogs::METHODS),
                SelectFilter::make('status_class')->label('Outcome')->options(ApiRequestLogs::STATUS_CLASSES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereBetween('status', [(int) $data['value'][0] * 100, (int) $data['value'][0] * 100 + 99]) : $query),
                Filter::make('status_code')->schema([TextInput::make('code')->label('HTTP status code')->integer()->minValue(100)->maxValue(599)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['code'] ?? null) ? $query->where('status', (int) $data['code']) : $query),
            ])
            ->headerActions([
                Action::make('export')->label('Export CSV')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->visible(fn () => app(IntegrationLogExports::class)->mayExport('api_request_logs', auth()->user()))
                    ->modalDescription('The file is prepared in the background; you are told when it is ready under Integrations → Log exports.')
                    ->schema([
                        DatePicker::make('from')->native(false)->default(now()->subDays(30)->toDateString()),
                        DatePicker::make('until')->native(false),
                        Select::make('method')->options(ApiRequestLogs::METHODS)->placeholder('Any'),
                        Select::make('status_class')->label('Outcome')->options(ApiRequestLogs::STATUS_CLASSES)->placeholder('Any'),
                        TextInput::make('path')->label('Request URL contains')->maxLength(120),
                    ])
                    ->action(function (array $data) {
                        /** @var ApiKey $key */
                        $key = $this->getOwnerRecord();
                        ServiceDeskActions::run(fn () => app(IntegrationLogExports::class)->request('api_request_logs', [...$data, 'api_key_id' => $key->getKey()], auth()->user()),
                            'Export queued — you will be told when it is ready');
                    }),
            ]);
    }
}
