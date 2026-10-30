<?php

namespace App\Filament\Resources\AssetRequests\Pages;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListAssetRequests extends PeopleListRecords
{
    protected static string $resource = AssetRequestResource::class;

    protected function getHeaderActions(): array
    {
        $own = fn (): ?Employee => auth()->id() ? Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', auth()->id())->first() : null;
        $raise = function (?Employee $employee, array $data) {
            try {
                if ($employee === null) {
                    throw new RuntimeException('Choose the employee.');
                }
                $request = app(AssetRequests::class)->request($employee, $data);
                RequestChrome::confirmed('asset', $request, 'Asset request '.$request->number.' raised', $request->status === 'requested' ? 'It waits for approval.' : 'It is with the asset team.');
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not raised')->body($e->getMessage())->send();
            }
        };

        return [
            Action::make('requestAsset')->label('Request an asset')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('asset.request') && $own() !== null)
                // HROne parity RQ-2: whose request, who approves it and the policy, around the module's own form.
                ->schema(fn () => [RequestChrome::header(fn () => $own()), ...self::requestFields(fn (Get $get) => $own(), 'employee'), RequestChrome::route('asset', fn () => $own())])
                ->action(fn (array $data) => $raise($own(), $data)),
            Action::make('requestForEmployee')->label('Request for an employee')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => AssetRequestResource::team() || auth()->user()->can('asset.request'))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => Employee::query()->with('person')->where('user_id', '!=', auth()->id())
                            ->whereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(20)->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->display_name.' ('.$e->employee_code.')']))
                        ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->display_name),
                    ...self::requestFields(fn (Get $get) => ($id = $get('employee_id')) ? Employee::query()->find($id) : null, AssetRequestResource::team() ? 'hr' : 'manager'),
                ])
                ->action(function (array $data) use ($raise) {
                    $employee = Employee::query()->find($data['employee_id'] ?? null);
                    unset($data['employee_id']);
                    $raise($employee, $data);
                }),
        ];
    }

    /**
     * The request form: what for (new, replacement, return), the asset type with the sub type and product where the raiser
     * may choose them (Asset → Settings), or the asset held, the reason and the date needed by.
     *
     * @param  Closure(Get): ?Employee  $employee
     * @return list<Component>
     */
    public static function requestFields(Closure $employee, string $audience): array
    {
        $settings = app(SettingsRepository::class);
        $subtypes = in_array($audience, (array) $settings->get('asset.request.subtype_visible_to', ['employee', 'manager', 'hr']), true);
        $models = in_array($audience, (array) $settings->get('asset.request.model_visible_to', ['hr']), true);
        $new = fn (Get $get) => $get('kind') !== 'return' && $get('kind') !== 'replacement';

        return [
            Select::make('kind')->label('Request for')->options(AssetRequest::KINDS)->default('new')->required()->live()->native(false),
            Select::make('asset_id')->label('Asset')->native(false)->visible(fn (Get $get) => ! $new($get))->required(fn (Get $get) => ! $new($get))
                ->options(fn (Get $get) => ($person = $employee($get)) === null ? [] : Asset::query()->where('custodian_id', $person->id)->where('status', 'assigned')->orderBy('asset_tag')->get()
                    ->mapWithKeys(fn (Asset $a) => [$a->id => $a->asset_tag.' · '.$a->name])->all()),
            Select::make('asset_category_id')->label('Asset type')->native(false)->live()->visible($new)->required($new)
                ->options(fn () => AssetCategory::query()->where('status', ActiveStatus::Active)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('asset_subtype_id')->label('Asset sub type')->native(false)->live()
                ->visible(fn (Get $get) => $subtypes && $get('kind') !== 'return')
                ->options(fn (Get $get) => AssetSubtype::query()->where('status', ActiveStatus::Active)
                    ->where('asset_category_id', $get('kind') === 'replacement' ? Asset::query()->whereKey($get('asset_id'))->value('asset_category_id') : $get('asset_category_id'))
                    ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('asset_model_id')->label('Product')->native(false)
                ->visible(fn (Get $get) => $models && $get('kind') !== 'return')
                ->options(fn (Get $get) => AssetModel::query()->where('status', ActiveStatus::Active)
                    ->where('asset_category_id', $get('kind') === 'replacement' ? Asset::query()->whereKey($get('asset_id'))->value('asset_category_id') : $get('asset_category_id'))
                    ->when(filled($get('asset_subtype_id')), fn ($q) => $q->where(fn ($q) => $q->whereNull('asset_subtype_id')->orWhere('asset_subtype_id', $get('asset_subtype_id'))))
                    ->orderBy('name')->pluck('name', 'id')->all()),
            Textarea::make('reason')->maxLength(500)->required(fn (Get $get) => ! $new($get)),
            DatePicker::make('needed_by')->label('Needed by')->native(false)->minDate(today()),
        ];
    }
}
