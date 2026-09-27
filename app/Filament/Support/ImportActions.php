<?php

namespace App\Filament\Support;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use RuntimeException;
use Throwable;

/** Header actions for the import pipeline; every step calls the service and reports the outcome. */
final class ImportActions
{
    /** @return list<Action> */
    public static function forImport(): array
    {
        $fields = collect(EmployeeImports::FIELDS)->mapWithKeys(fn ($def, $key) => [$key => $def[0].($def[1] ? ' *' : '')])->all();

        return [
            Action::make('inspect')->label('Inspect file')->icon('heroicon-m-magnifying-glass')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['uploaded', 'inspected'], true))
                ->action(fn (EmployeeImport $record) => self::run(fn () => app(EmployeeImports::class)->inspect($record), fn (EmployeeImport $i) => "{$i->row_count} row(s) staged; ".count($i->mapping ?? []).' column(s) mapped automatically')),

            Action::make('map')->label('Map columns')->icon('heroicon-m-arrows-right-left')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['inspected', 'mapped', 'validated'], true))
                ->fillForm(fn (EmployeeImport $record) => ['mapping' => collect($record->headers ?? [])->mapWithKeys(fn ($h, $i) => [(string) $i => $record->mapping[$h] ?? null])->all(), 'on_duplicate' => $record->options['on_duplicate'] ?? 'update'])
                ->schema(fn (EmployeeImport $record) => [
                    Section::make('Columns')->description('Choose the PeopleOS field for each column. Fields marked * are required.')->columns(2)
                        ->schema(collect($record->headers ?? [])->map(fn ($header, $i) => Select::make("mapping.{$i}")->label($header)->options($fields)->searchable()->placeholder('Ignore column'))->values()->all()),
                    Select::make('on_duplicate')->label('When the person already exists')->options(['update' => 'Update person details', 'skip' => 'Skip the row'])->required(),
                ])
                ->action(function (EmployeeImport $record, array $data) {
                    $mapping = [];
                    foreach ($record->headers ?? [] as $i => $header) {
                        $mapping[$header] = $data['mapping'][(string) $i] ?? null;
                    }
                    self::run(fn () => app(EmployeeImports::class)->map($record, $mapping, ['on_duplicate' => $data['on_duplicate']]), 'Mapping saved');
                }),

            Action::make('validate')->label('Validate rows')->icon('heroicon-m-check-badge')
                ->visible(fn (EmployeeImport $record) => in_array($record->status, ['mapped', 'validated'], true))
                ->action(fn (EmployeeImport $record) => self::run(fn () => app(EmployeeImports::class)->validate($record), fn (EmployeeImport $i) => "{$i->valid_count} valid, {$i->error_count} with errors, {$i->review_count} to review")),

            Action::make('approve')->label('Approve import')->icon('heroicon-m-hand-thumb-up')->color('warning')
                ->visible(fn (EmployeeImport $record) => $record->status === 'validated')
                ->requiresConfirmation()
                ->modalDescription(fn (EmployeeImport $record) => "This will create {$record->create_count} and update {$record->update_count} employee(s); {$record->error_count} row(s) with errors and {$record->skip_count} duplicate(s) will be skipped.")
                ->schema([Textarea::make(AuditReasonField::NAME)->label('Reason')->required()->maxLength(500)])
                ->action(fn (EmployeeImport $record, array $data) => self::run(fn () => app(EmployeeImports::class)->approve($record, auth()->user(), $data[AuditReasonField::NAME]), 'Import approved')),

            Action::make('run')->label('Run import')->icon('heroicon-m-play')->color('success')
                ->visible(fn (EmployeeImport $record) => $record->status === 'approved')
                ->requiresConfirmation()
                ->action(fn (EmployeeImport $record) => self::run(fn () => app(EmployeeImports::class)->run($record, auth()->user()), fn (EmployeeImport $i) => "Created {$i->create_count}, updated {$i->update_count}, skipped {$i->skip_count}, failed {$i->failure_count}")),

            Action::make('discard')->label('Discard')->icon('heroicon-m-trash')->color('danger')
                ->visible(fn (EmployeeImport $record) => ! in_array($record->status, ['importing', 'imported', 'discarded'], true))
                ->requiresConfirmation()
                ->action(fn (EmployeeImport $record) => self::run(fn () => app(EmployeeImports::class)->discard($record), 'Import discarded')),
        ];
    }

    public static function run(callable $callback, callable|string $success): mixed
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();

            return $result;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }

        return null;
    }
}
