<?php

namespace App\Filament\Support;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetRepair;
use App\Domain\Assets\Services\Assets;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Location;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;

/** The asset lifecycle as actions on the asset page. */
final class AssetActions
{
    public static function peopleOptions(): array
    {
        return Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    /** @return array<int, Action> */
    public static function forAsset(): array
    {
        $assign = fn (Asset $record) => auth()->user()->can('assign', $record);
        $manage = fn () => auth()->user()->can('asset.manage');
        $conditions = config('peopleos.assets.conditions');

        return [
            Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('primary')
                ->visible(fn (Asset $record) => $record->status === 'in_stock' && $assign($record))
                ->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => self::peopleOptions()),
                    DatePicker::make('assigned_on')->native(false)->default(now())->required(),
                    Select::make('condition')->options($conditions)->default(fn (Asset $record) => $record->condition)->required(),
                    DatePicker::make('expected_return_on')->native(false)->placeholder('Indefinite'),
                    Textarea::make('note')->maxLength(255),
                ])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->assign($record, Employee::query()->findOrFail($data['employee_id']), $data['assigned_on'], $data['condition'], $data['note'] ?? null, $data['expected_return_on'] ?? null, auth()->user()), 'Asset assigned')),
            Action::make('transfer')->label('Transfer')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
                ->visible(fn (Asset $record) => $record->status === 'assigned' && $assign($record))
                ->schema([
                    Select::make('employee_id')->label('New custodian')->required()->searchable()->options(fn () => self::peopleOptions()),
                    Textarea::make('note')->maxLength(255),
                ])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->transfer($record, Employee::query()->findOrFail($data['employee_id']), $data['note'] ?? null, auth()->user()), 'Asset transferred')),
            Action::make('return')->label('Take back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (Asset $record) => $record->status === 'assigned' && $assign($record))
                ->schema([
                    Select::make('condition')->label('Condition on return')->options($conditions)->default('good')->required(),
                    Textarea::make('note')->maxLength(255),
                ])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->returnAsset($record, $data['condition'], $data['note'] ?? null, auth()->user()), 'Asset back in stock')),
            Action::make('repair')->label('Send for repair')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('warning')
                ->visible(fn (Asset $record) => in_array($record->status, ['in_stock', 'assigned'], true) && $manage())
                ->schema([Textarea::make('issue')->required()->maxLength(500), TextInput::make('vendor')->maxLength(255)])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->sendForRepair($record, $data['issue'], $data['vendor'] ?? null, auth()->user()), 'Sent for repair')),
            Action::make('repaired')->label('Back from repair')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Asset $record) => $record->status === 'in_repair' && $manage())
                ->schema([
                    TextInput::make('cost')->numeric()->minValue(0),
                    Select::make('condition')->options($conditions)->default('good')->required(),
                    Textarea::make('resolution')->maxLength(500),
                ])
                ->action(fn (Asset $record, array $data) => self::run(function () use ($record, $data) {
                    $repair = $record->repairs()->where('status', 'open')->first() ?? AssetRepair::create(['asset_id' => $record->id, 'issue' => 'Unrecorded', 'sent_on' => now(), 'status' => 'open']);

                    return app(Assets::class)->repaired($repair, isset($data['cost']) ? (float) $data['cost'] : null, $data['resolution'] ?? null, $data['condition'], auth()->user());
                }, 'Asset back in stock')),
            Action::make('relocate')->label('Change location')->icon(Heroicon::OutlinedMapPin)->color('gray')
                ->visible(fn (Asset $record) => $record->isActive() && $manage())
                ->schema([Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('None'), Textarea::make('note')->maxLength(255)])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->relocate($record, $data['location_id'] ?? null, $data['note'] ?? null, auth()->user()), 'Location updated')),
            Action::make('lost')->label('Report lost')->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
                ->visible(fn (Asset $record) => in_array($record->status, ['in_stock', 'assigned', 'in_transit'], true) && $manage())
                ->requiresConfirmation()
                ->schema([Textarea::make('note')->required()->maxLength(500)])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->markLost($record, $data['note'], auth()->user()), 'Reported lost')),
            Action::make('found')->label('Found')->icon(Heroicon::OutlinedMagnifyingGlass)->color('success')
                ->visible(fn (Asset $record) => $record->status === 'lost' && $manage())
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->found($record, $data['note'] ?? null, auth()->user()), 'Back in stock')),
            Action::make('retire')->label('Retire')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn (Asset $record) => in_array($record->status, ['in_stock', 'in_repair', 'lost'], true) && $manage())
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->retire($record, $data['reason'], auth()->user()), 'Asset retired')),
            Action::make('dispose')->label('Dispose')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (Asset $record) => in_array($record->status, ['in_stock', 'retired', 'in_repair', 'lost'], true) && $manage())
                ->requiresConfirmation()
                ->schema([
                    Select::make('method')->options(config('peopleos.assets.disposal_methods'))->required(),
                    DatePicker::make('disposed_on')->native(false)->default(now())->required(),
                    TextInput::make('value')->numeric()->minValue(0)->label('Realised value'),
                    TextInput::make('reference')->maxLength(255)->placeholder('Invoice / gate pass'),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(fn (Asset $record, array $data) => self::run(fn () => app(Assets::class)->dispose($record, $data['method'], $data['disposed_on'], isset($data['value']) ? (float) $data['value'] : null, $data['reference'] ?? null, $data['note'] ?? null, auth()->user()), 'Asset disposed')),
        ];
    }

    public static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
