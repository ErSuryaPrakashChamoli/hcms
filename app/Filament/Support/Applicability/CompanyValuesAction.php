<?php

namespace App\Filament\Support\Applicability;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Overrides\OverrideField;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Identity\Models\User;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
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
    /** The field choice that changes the fields that must agree (a designation's level and grade) in one step. */
    public const TOGETHER = '__together';

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
                    Group::make(fn () => collect(self::togetherFields($record))->map(fn (OverrideField $f, string $path) => Select::make("together.{$path}")
                        ->label("Company {$f->label}")->native(false)->searchable()->options($f->options)->placeholder('Blank (none)'))->values()->all())
                        ->visible(fn (Get $get) => $get('operation') === 'set' && $get('field') === self::TOGETHER),
                    TextInput::make('value_text')->label('Company value')
                        ->visible(fn (Get $get) => $get('operation') === 'set' && (in_array(self::field($record, $get)?->kind, ['text', 'time'], true)
                            || (in_array(self::field($record, $get)?->kind, ['select', 'multiselect'], true) && (self::field($record, $get)?->options ?? []) === []))),
                    // Today by default; back-dating only into the payroll months still open for the company, and never
                    // before the field's last change (ConfigurationOverrides::earliestStart(), checked again when saving).
                    DatePicker::make('effective_from')->label('From')->required()->native(false)->live()
                        ->minDate(fn (Get $get) => self::earliest($record, $get)['date'])
                        ->helperText(fn (Get $get) => self::dateHelp(self::earliest($record, $get))),
                ]),
                Step::make('Review')->description('Check and save')->schema([
                    Text::make(fn (Get $get) => self::review($record, $get)),
                    // A back-dated change is acknowledged and explained (compliance: it reaches days already lived).
                    Checkbox::make('backdated_confirmed')
                        ->label(fn (Get $get) => 'I understand this is back-dated to '.self::day($get('effective_from')).': the days from then are worked out with the new value')
                        ->visible(fn (Get $get) => self::backdated($get))
                        ->accepted(fn (Get $get) => self::backdated($get)),
                    AuditReasonField::make()
                        ->required(fn (Get $get) => self::backdated($get))
                        ->helperText(fn (Get $get) => self::backdated($get)
                            ? 'Required for a back-dated change. Recorded in the audit trail alongside the before/after values.'
                            : 'Recorded in the audit trail alongside the before/after values.'),
                ]),
            ])
            ->action(function (Model $record, array $data, Action $action) {
                $reason = AuditReasonField::extract($data);
                $overrides = app(ConfigurationOverrides::class);
                try {
                    if ($data['field'] === self::TOGETHER) {
                        // Remediation I.6: the fields that must agree change in one step, checked once at the end.
                        $overrides->together(function () use ($overrides, $record, $data, $reason) {
                            foreach (array_keys(self::togetherFields($record)) as $path) {
                                $data['operation'] === 'end'
                                    ? $overrides->end($record, (int) $data['company_id'], $path, $data['effective_from'], auth()->user(), $reason)
                                    : $overrides->set($record, (int) $data['company_id'], $path, $data['together'][$path] ?? null, $data['effective_from'], auth()->user(), $reason);
                            }
                        });
                    } elseif ($data['operation'] === 'end' && $record instanceof Policy && $record->type === EmploymentRequirements::TYPE) {
                        // Remediation I.7: ending a company's own value of a requirements policy is checked first, so the
                        // company never takes on a Block nobody checked for it (EmploymentRequirementsPreflight).
                        $outcome = app(EmploymentRequirementsPreflight::class)->endCompanyValues($record, (int) $data['company_id'], [(string) $data['field']], auth()->user(), (string) $reason, $data['effective_from']);
                        $notice = Notification::make()->title(match ($outcome['outcome']) {
                            'pending' => 'Checking first', 'refused' => 'Not ended', default => 'Back to the shared value',
                        })->body($outcome['message']);
                        match ($outcome['outcome']) {
                            'refused' => $notice->danger()->persistent()->send(),
                            'pending' => $notice->info()->persistent()->send(),
                            default => $notice->success()->send(),
                        };
                        if ($outcome['outcome'] === 'refused') {
                            $action->halt();
                        }

                        return;
                    } elseif ($data['operation'] === 'end') {
                        $overrides->end($record, (int) $data['company_id'], (string) $data['field'], $data['effective_from'], auth()->user(), $reason);
                    } else {
                        $field = $overrides->fields($record)[$data['field']] ?? null;
                        $overrides->set($record, (int) $data['company_id'], (string) $data['field'], self::value($field, $data), $data['effective_from'], auth()->user(), $reason);
                    }
                } catch (ApplicabilityRefusedException|ConfigurationException $refused) {
                    Notification::make()->danger()->title('Not saved')->body($refused->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title($data['operation'] === 'end' ? 'Back to the shared value' : 'Company value saved')
                    ->body('From '.self::day($data['effective_from']).(self::isPast($data['effective_from']) ? ' (back-dated)' : '').'. Only this company is affected; recorded in the audit trail.')->send();
            });
    }

    /**
     * The earliest day the calendar offers for the company and field chosen so far (today until both are chosen).
     *
     * @return array{date: string, reason: string}
     */
    private static function earliest(Model $record, Get $get): array
    {
        $paths = $get('field') === self::TOGETHER ? array_keys(self::togetherFields($record)) : array_filter([(string) $get('field')]);
        if (! filled($get('company_id')) || $paths === []) {
            return ['date' => today()->toDateString(), 'reason' => ''];
        }

        return app(ConfigurationOverrides::class)->earliestStart($record, (int) $get('company_id'), array_values($paths));
    }

    /** @param  array{date: string, reason: string}  $earliest */
    private static function dateHelp(array $earliest): string
    {
        if ($earliest['reason'] === '') {
            return 'Today by default. Choose the company and the field first: earlier days open as far as its payroll months allow.';
        }
        if ($earliest['date'] >= today()->toDateString()) {
            return 'Today or later: it cannot be back-dated now ('.$earliest['reason'].').';
        }

        return 'Today by default. Back-dating goes to '.self::day($earliest['date']).' at the earliest: '.$earliest['reason']
            .'. Finalized payroll and closed attendance months never change.';
    }

    private static function backdated(Get $get): bool
    {
        return self::isPast($get('effective_from'));
    }

    private static function isPast(mixed $date): bool
    {
        return filled($date) && Carbon::parse((string) $date)->toDateString() < today()->toDateString();
    }

    private static function day(mixed $date): string
    {
        return filled($date) ? Carbon::parse((string) $date)->format('j M Y') : '…';
    }

    /**
     * For the review card: when the change is back-dated, the day, how long ago and the earliest it could start.
     *
     * @return array{from: string, days: int, earliest: string, allowed: bool, what: string}|null
     */
    private static function backdating(Model $record, Get $get): ?array
    {
        if (! self::backdated($get)) {
            return null;
        }
        $earliest = self::earliest($record, $get);

        return ['from' => self::day($get('effective_from')), 'days' => (int) Carbon::parse((string) $get('effective_from'))->diffInDays(today()),
            'earliest' => self::day($earliest['date']), 'allowed' => Carbon::parse((string) $get('effective_from'))->toDateString() >= $earliest['date'],
            'what' => $get('operation') === 'end' ? 'goes back to the shared value' : 'uses the new value as if it had applied then'];
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
        $together = self::togetherFields($record);

        return collect($fields)->mapWithKeys(fn (OverrideField $f, string $path) => [$path => $f->label])->all()
            + (count($together) > 1 ? [self::TOGETHER => collect($together)->map(fn (OverrideField $f) => $f->label)->implode(' and ').' together'] : []);
    }

    /** @return array<string, OverrideField> the fields that must agree with each other (ValidatesCompanyTimeline), if any */
    private static function togetherFields(Model $record): array
    {
        $overrides = app(ConfigurationOverrides::class);

        return array_intersect_key($overrides->fields($record), array_flip($overrides->timelineFields($record)));
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
        $company = filled($get('company_id')) ? (int) $get('company_id') : null;
        if ($get('field') === self::TOGETHER && $company !== null) {
            $names = app(EntityApplicability::class)->companyNames();
            $explained = collect(app(ConfigurationOverrides::class)->explain($record, $company, $get('effective_from')))->keyBy('path');
            $rows = [];
            foreach (self::togetherFields($record) as $path => $f) {
                $rows[] = [$f->label, ($explained[$path]['effective_display'] ?? '—').' → '.($get('operation') === 'end' ? 'the shared value ('.($explained[$path]['shared_display'] ?? '—').')' : $f->display($get("together.{$path}")))];
            }
            $rows[] = ['From', filled($get('effective_from')) ? Carbon::parse($get('effective_from'))->format('j M Y') : '—'];
            $rows[] = ['Checked', 'Together: they must agree on every date from then on'];

            return new HtmlString(view('filament.applicability.value-review', [
                'rows' => $rows,
                'headline' => ($names[$company] ?? 'The company').' gets its own values for '.count(self::togetherFields($record)).' fields that change together.',
                'backdated' => self::backdating($record, $get),
            ])->render());
        }
        $field = self::field($record, $get);
        if ($field === null || $company === null) {
            return new HtmlString('<p class="pos-body-sm text-gray-500">Choose the company and the field first.</p>');
        }
        $row = collect(app(ConfigurationOverrides::class)->explain($record, $company, $get('effective_from')))->firstWhere('path', $field->path);
        $new = $get('operation') === 'end' ? 'the shared value ('.($row['shared_display'] ?? '—').')' : $field->display(self::value($field, [
            'value_number' => $get('value_number'), 'value_boolean' => $get('value_boolean'), 'value_select' => $get('value_select'), 'value_text' => $get('value_text'),
        ]));
        $names = app(EntityApplicability::class)->companyNames();

        $from = filled($get('effective_from')) ? Carbon::parse($get('effective_from'))->format('j M Y') : '—';

        // The company's value now → what it will use (a "before → after" row), the shared value beside it for reference.
        return new HtmlString(view('filament.applicability.value-review', [
            // The company and the field are in the list of every detail above; the card shows what they become.
            'rows' => [
                [$field->label, ($row['effective_display'] ?? '—').' → '.$new],
                ['Shared value', $row['shared_display'] ?? '—'], ['From', $from],
            ],
            'headline' => ($get('operation') === 'end' ? ($names[$company] ?? 'The company').' goes back to the shared '.mb_strtolower($field->label) : ($names[$company] ?? 'The company').' gets its own '.mb_strtolower($field->label)).' from '.$from.(self::backdated($get) ? ' (back-dated)' : '').'.',
            'backdated' => self::backdating($record, $get),
        ])->render());
    }
}
