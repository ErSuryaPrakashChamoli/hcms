<?php

namespace App\Filament\Resources\ComplianceRules;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ComplianceRules\Pages\ListComplianceRules;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * The versioned statutory rule library (§32, Phase 5 Parts E–G). Read-only inside tenants. Platform
 * administrators submit official evidence, verify (never their own submission) or reject. Rule
 * payloads are never edited here: a correction is a new version in the pack.
 */
class ComplianceRuleResource extends Resource
{
    protected static ?string $model = ComplianceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Compliance rules';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->color('gray')->sortable(),
                TextColumn::make('state')->placeholder('All India')->formatStateUsing(fn (?string $state) => config("peopleos.compliance.states.{$state}", $state)),
                TextColumn::make('name')->searchable(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('authority')->placeholder('—')->toggleable(),
                TextColumn::make('source')->limit(50)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_url')->label('Official source')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('checksum')->limit(12)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verification_status')->label('Verification')->badge()->formatStateUsing(fn (string $state) => config("peopleos.compliance.verification_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'verified' => 'success', 'review' => 'warning', 'draft' => 'danger', default => 'gray'
                    }),
                TextColumn::make('verified_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('code')->options(['EPF' => 'EPF', 'ESI' => 'ESI', 'PT' => 'Professional tax', 'LWF' => 'LWF', 'TDS' => 'Income tax', 'GRATUITY' => 'Gratuity']),
                SelectFilter::make('verification_status')->label('Verification')->options(config('peopleos.compliance.verification_statuses')),
            ])
            ->recordActions([
                Action::make('parameters')->label('Parameters')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('parameters')->hiddenLabel()->state(fn (ComplianceRule $record) => collect(Arr::dot($record->parameters))->map(fn ($v, $k) => "{$k} = ".(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->values()->all())->listWithLineBreaks(),
                    ]),
                Action::make('history')->label('Verification history')->icon('heroicon-m-clock')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('history')->hiddenLabel()->state(fn (ComplianceRule $record) => $record->verifications->map(fn ($v) => $v->created_at?->format('Y-m-d H:i').' — '.strtoupper($v->action).' '.($v->from_status ?? '∅').' → '.$v->to_status.' by '.($v->actor_label ?? 'system').($v->source_url ? ' — '.$v->source_url : '').($v->notes ? ' — '.$v->notes : ''))->all())->listWithLineBreaks(),
                    ]),
                Action::make('submit')->label('Submit evidence')->icon('heroicon-m-document-magnifying-glass')
                    ->visible(fn (ComplianceRule $record) => $record->verification_status === ComplianceRule::DRAFT && self::platformAdmin())
                    ->schema(fn (ComplianceRule $record) => [
                        Select::make('authority')->options(config('peopleos.compliance.authorities'))->default($record->authority),
                        TextInput::make('source_url')->label('Official source URL')->url()->required()->helperText('Government or gazette site only.'),
                        TextInput::make('source_title')->required(),
                        DatePicker::make('source_published_date')->label('Published on (if stated)'),
                        DatePicker::make('effective_date')->required(),
                        Textarea::make('requirement_text')->label('Requirement text (quoted)')->required()->rows(4),
                        KeyValue::make('mapping')->label('Payload parameter → requirement')->default(array_fill_keys(array_keys($record->payload()), ''))->required(),
                        TextInput::make('evidence_reference')->label('Evidence reference (file / retrieval note)'),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(fn (ComplianceRule $record, array $data) => StatutoryRegistrationResource::attempt(fn () => app(RuleVerifications::class)->submit($record, $data, self::user()), 'Submitted for review')),
                Action::make('verify')->icon('heroicon-m-check-badge')->color('success')->requiresConfirmation()
                    ->modalDescription('Confirm that the official evidence supports every payload parameter. Verified versions can be used for production payroll and can never be edited.')
                    ->visible(fn (ComplianceRule $record) => $record->verification_status === ComplianceRule::REVIEW && self::platformAdmin())
                    ->schema([Textarea::make('notes')->label('Verification notes')->required()->rows(3)])
                    ->action(fn (ComplianceRule $record, array $data) => StatutoryRegistrationResource::attempt(fn () => app(RuleVerifications::class)->verify($record, self::user(), $data['notes']), 'Rule verified')),
                Action::make('reject')->icon('heroicon-m-x-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (ComplianceRule $record) => in_array($record->verification_status, [ComplianceRule::DRAFT, ComplianceRule::REVIEW], true) && self::platformAdmin())
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(fn (ComplianceRule $record, array $data) => StatutoryRegistrationResource::attempt(fn () => app(RuleVerifications::class)->reject($record, self::user(), $data['reason']), 'Rule rejected')),
            ])
            ->emptyStateHeading('No statutory rules loaded')
            ->emptyStateDescription('Run peopleos:compliance:sync on the platform to load the compliance packs.');
    }

    private static function platformAdmin(): bool
    {
        return (bool) auth()->user()?->isPlatformAdmin();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceRules::route('/')];
    }
}
