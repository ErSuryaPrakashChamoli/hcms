<?php

namespace App\Filament\Resources\ExportLayouts;

use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExportLayouts\Pages\ListExportLayouts;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use App\Support\Storage\StagedUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** Phase 6.2: versioned statutory export layouts with maker-checker verification. Read-only for tenants. */
class ExportLayoutResource extends Resource
{
    protected static ?string $model = StatutoryExportLayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Export layouts';

    protected static ?int $navigationSort = 23;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $admin = fn () => (bool) auth()->user()?->isPlatformAdmin();
        $user = function (): User {
            /** @var User */
            return auth()->user();
        };

        return $table
            ->columns([
                TextColumn::make('code')->badge()->sortable(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('name')->wrap(),
                TextColumn::make('authority')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'verified' => 'success', 'review' => 'warning', 'draft' => 'danger', default => 'gray'
                }),
                TextColumn::make('source_title')->label('Specification')->limit(40)->placeholder('—'),
                TextColumn::make('verified_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(array_combine(StatutoryExportLayout::STATUSES, StatutoryExportLayout::STATUSES))])
            ->recordActions([
                Action::make('specification')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('structure')->state(fn (StatutoryExportLayout $record) => collect($record->specification)->except('fields')->map(fn ($v, $k) => "{$k}: ".json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->values()->all())->listWithLineBreaks(),
                        TextEntry::make('fields')->state(fn (StatutoryExportLayout $record) => collect($record->specification['fields'] ?? [])->map(fn ($f, $i) => ($i + 1).'. '.$f['name'].(($f['required'] ?? false) ? ' (required)' : '').(isset($f['pattern']) ? ' '.$f['pattern'] : ''))->all())->listWithLineBreaks(),
                        TextEntry::make('notes')->placeholder('—'),
                        TextEntry::make('checksum'),
                    ]),
                Action::make('submit')->label('Submit specification')->icon('heroicon-m-document-magnifying-glass')
                    ->visible(fn (StatutoryExportLayout $record) => in_array($record->status, ['draft', 'review'], true) && $admin())
                    ->schema([
                        TextInput::make('source_url')->label('Authority specification URL')->url()->required(),
                        TextInput::make('source_title')->required(),
                        DatePicker::make('retrieved_at')->required()->default(now())->maxDate(now()),
                        FileUpload::make('file')->label('Stored copy of the specification')->disk(StagedUpload::disk())->directory('compliance-evidence/uploads')->required(),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(fn (StatutoryExportLayout $record, array $data) => StatutoryRegistrationResource::attempt(function () use ($record, $data, $user) {
                        app(ExportLayouts::class)->submit($record, $user(), $data['source_url'], $data['source_title'], $data['retrieved_at'], (string) StagedUpload::storage()->get($data['file']), basename($data['file']), $data['notes'] ?? null);
                        StagedUpload::storage()->delete($data['file']);
                    }, 'Submitted for review')),
                Action::make('verify')->icon('heroicon-m-check-badge')->color('success')->requiresConfirmation()
                    ->modalDescription('Confirm, against the stored specification, that every field, its order and format, the mandatory fields, allowed values, encoding and file structure match. You cannot verify a layout you submitted.')
                    ->visible(fn (StatutoryExportLayout $record) => $record->status === 'review' && $admin())
                    ->schema([Textarea::make('notes')->label('Verification notes')->required()->rows(3)])
                    ->action(fn (StatutoryExportLayout $record, array $data) => StatutoryRegistrationResource::attempt(fn () => app(ExportLayouts::class)->verify($record, $user(), $data['notes']), 'Layout verified')),
                Action::make('reject')->icon('heroicon-m-x-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (StatutoryExportLayout $record) => in_array($record->status, ['draft', 'review'], true) && $admin())
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(fn (StatutoryExportLayout $record, array $data) => StatutoryRegistrationResource::attempt(fn () => app(ExportLayouts::class)->reject($record, $user(), $data['reason']), 'Layout rejected')),
                Action::make('newVersion')->label('Publish new version')->icon('heroicon-m-document-duplicate')->requiresConfirmation()
                    ->visible($admin)
                    ->schema(fn (StatutoryExportLayout $record) => [
                        Textarea::make('specification')->label('Specification (JSON)')->required()->rows(12)->default(json_encode($record->specification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        Textarea::make('reason')->required()->rows(2),
                    ])
                    ->action(fn (StatutoryExportLayout $record, array $data) => StatutoryRegistrationResource::attempt(function () use ($record, $data, $user) {
                        $spec = json_decode((string) $data['specification'], true);
                        if (! is_array($spec)) {
                            throw new RuntimeException('The specification must be valid JSON.');
                        }
                        app(ExportLayouts::class)->publishVersion($record, $spec, $data['reason'], $user());
                    }, 'New layout version published as DRAFT')),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => ListExportLayouts::route('/')];
    }
}
