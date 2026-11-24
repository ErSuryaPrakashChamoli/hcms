<?php

namespace App\Filament\Support\Applicability;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Overrides\OverrideField;
use App\Domain\Identity\Models\User;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * "Company values…": one company's own values of a shared record (entity-specific overrides, Part 6). The company keeps
 * using the shared record and inherits every other value; its own values start on a date, are versioned, and ending one
 * returns the company to the shared value. Nothing changes for any other company.
 */
final class CompanyValuesAction
{
    public static function make(string $typeKey, string $name = 'companyValues'): Action
    {
        $type = app(ConfigurationTypes::class)->for($typeKey);

        return Action::make($name)
            ->label('Company values…')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn () => ($user = auth()->user()) instanceof User && $user->hasPermission(ConfigurationOverrides::PERMISSION) && $user->hasPermission($type->permission))
            ->modalHeading(fn (Model $record) => 'Company values of '.$type->describe($record))
            ->modalDescription('A company can keep this shared '.strtolower($type->label).' and set some values of its own. The shared record and other companies are not changed.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn () => ['operation' => 'set', 'effective_from' => today()->toDateString()])
            ->steps(fn (Model $record) => [
                Step::make('Company')->description('Whose values')->schema([
                    Select::make('company_id')->label('Company')->required()->native(false)->live()->options(fn () => self::companies($record)),
                    Text::make(fn (Get $get) => self::table($record, $get)),
                ]),
                Step::make('Value')->description('What changes')->schema([
                    Radio::make('operation')->label('Change')->required()->live()->options([
                        'set' => 'Give the company its own value', 'end' => 'Go back to the shared value',
                    ]),
                    Select::make('field')->label('Field')->required()->searchable()->native(false)->live()
                        ->options(fn (Get $get) => self::fieldOptions($record, $get)),
                    TextInput::make('value_number')->label('Company value')->numeric()
                        ->visible(fn (Get $get) => $get('operation') === 'set' && self::field($record, $get)?->kind === 'number'),
                    Toggle::make('value_boolean')->label('Company value')
                        ->visible(fn (Get $get) => $get('operation') === 'set' && self::field($record, $get)?->kind === 'boolean'),
                    Select::make('value_select')->label('Company value')->native(false)
                        ->options(fn (Get $get) => self::field($record, $get)?->options ?? [])
                        ->multiple(fn (Get $get) => self::field($record, $get)?->kind === 'multiselect')
                        ->visible(fn (Get $get) => $get('operation') === 'set' && in_array(self::field($record, $get)?->kind, ['select', 'multiselect'], true) && (self::field($record, $get)?->options ?? []) !== []),
                    TextInput::make('value_text')->label('Company value')
                        ->visible(fn (Get $get) => $get('operation') === 'set' && (in_array(self::field($record, $get)?->kind, ['text', 'time'], true)
                            || (in_array(self::field($record, $get)?->kind, ['select', 'multiselect'], true) && (self::field($record, $get)?->options ?? []) === []))),
                    DatePicker::make('effective_from')->label('From')->required()->native(false)->minDate(today())
                        ->helperText('Today or later. Days already worked or paid keep the values they used.'),
                ]),
                Step::make('Review')->description('Check and save')->schema([
                    Text::make(fn (Get $get) => self::review($record, $get)),
                    AuditReasonField::make(),
                ]),
            ])
            ->action(function (Model $record, array $data, Action $action) {
                $reason = AuditReasonField::extract($data);
                $overrides = app(ConfigurationOverrides::class);
                try {
                    if ($data['operation'] === 'end') {
                        $overrides->end($record, (int) $data['company_id'], (string) $data['field'], $data['effective_from'], auth()->user(), $reason);
                    } else {
                        $field = $overrides->fields($record)[$data['field']] ?? null;
                        $overrides->set($record, (int) $data['company_id'], (string) $data['field'], self::value($field, $data), $data['effective_from'], auth()->user(), $reason);
                    }
                } catch (ApplicabilityRefusedException $refused) {
                    Notification::make()->danger()->title('Not saved')->body($refused->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title($data['operation'] === 'end' ? 'Back to the shared value' : 'Company value saved')
                    ->body('From '.Carbon::parse($data['effective_from'])->format('j M Y').'. Only this company is affected; recorded in the audit trail.')->send();
            });
    }

    /** @return array<int, string> the companies the record applies to, inside the person's access */
    private static function companies(Model $record): array
    {
        $service = app(EntityApplicability::class);
        $reach = $service->reach($record);
        $user = auth()->user();
        $mine = $user instanceof User ? $service->companiesFor($user) : null;

        return collect(ApplicabilityUi::companyOptions($mine))
            ->filter(fn ($name, $id) => $reach === null || in_array((int) $id, $reach, true))->all();
    }

    /** @return array<string, string> */
    private static function fieldOptions(Model $record, Get $get): array
    {
        $overrides = app(ConfigurationOverrides::class);
        $fields = $overrides->fields($record);
        if ($get('operation') === 'end' && filled($get('company_id'))) {
            $mine = $overrides->valuesFor(app(EntityApplicability::class)->type($record)->key, (int) $record->getKey(), (int) $get('company_id'));
            $fields = array_intersect_key($fields, $mine);
        }

        return collect($fields)->mapWithKeys(fn (OverrideField $f, string $path) => [$path => $f->label])->all();
    }

    private static function field(Model $record, Get $get): ?OverrideField
    {
        return filled($get('field')) ? (app(ConfigurationOverrides::class)->fields($record)[$get('field')] ?? null) : null;
    }

    /** @param  array<string, mixed>  $data */
    private static function value(?OverrideField $field, array $data): mixed
    {
        return match ($field?->kind) {
            'number' => $data['value_number'] ?? null,
            'boolean' => (bool) ($data['value_boolean'] ?? false),
            'select', 'multiselect' => ($field->options !== [] ? ($data['value_select'] ?? null) : ($data['value_text'] ?? null)),
            default => $data['value_text'] ?? null,
        };
    }

    private static function table(Model $record, Get $get): HtmlString
    {
        $company = filled($get('company_id')) ? (int) $get('company_id') : null;
        $rows = $company === null ? [] : app(ConfigurationOverrides::class)->explain($record, $company);

        return new HtmlString(view('filament.applicability.values', [
            'company' => $company === null ? null : (app(EntityApplicability::class)->companyNames()[$company] ?? null),
            'rows' => $rows,
            'overridden' => collect($rows)->where('overridden', true)->count(),
        ])->render());
    }

    private static function review(Model $record, Get $get): HtmlString
    {
        $field = self::field($record, $get);
        $company = filled($get('company_id')) ? (int) $get('company_id') : null;
        if ($field === null || $company === null) {
            return new HtmlString('<p class="pos-body-sm text-gray-500">Choose the company and the field first.</p>');
        }
        $row = collect(app(ConfigurationOverrides::class)->explain($record, $company, $get('effective_from')))->firstWhere('path', $field->path);
        $new = $get('operation') === 'end' ? 'the shared value ('.($row['shared_display'] ?? '—').')' : $field->display(self::value($field, [
            'value_number' => $get('value_number'), 'value_boolean' => $get('value_boolean'), 'value_select' => $get('value_select'), 'value_text' => $get('value_text'),
        ]));
        $names = app(EntityApplicability::class)->companyNames();

        return new HtmlString(view('filament.applicability.value-review', [
            'rows' => [
                ['Field', $field->label], ['Company', $names[$company] ?? '—'], ['Shared value', $row['shared_display'] ?? '—'],
                ['Company uses now', $row['effective_display'] ?? '—'], ['Company will use', $new],
                ['From', filled($get('effective_from')) ? Carbon::parse($get('effective_from'))->format('j M Y') : '—'],
                ['Other companies', 'Not affected'], ['Shared record', 'Not changed'],
            ],
        ])->render());
    }
}
