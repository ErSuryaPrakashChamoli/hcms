<?php

namespace App\Filament\Resources\LearningCertificates;

use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Services\Certificates;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\LearningCertificates\Pages\ListLearningCertificates;
use App\Filament\Support\LearningActions;
use App\Support\Storage\StagedUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Certificates issued on completion; expiry drives re-training (§37). */
class LearningCertificateResource extends Resource
{
    protected static ?string $model = LearningCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'course']);
        $user = auth()->user();
        if ($user->can('learning.view') || $user->can('learning.manage') || $user->can('learning.certificates')) {
            return $query;
        }
        $me = LearningActions::me();
        $reports = ($user->can('learning.team') || $user->can('learning.assign')) ? app(PerformanceRelationships::class)->reportIds($me) : collect();

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhereIn('employee_id', $reports));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('issued_on')->date()->sortable(),
                TextColumn::make('expires_on')->date()->placeholder('Never')->sortable(),
                TextColumn::make('score')->suffix('%')->placeholder('—'),
                TextColumn::make('courseVersion.version')->label('Version')->prefix('v')->placeholder('—')->toggleable(),
                TextColumn::make('issuer')->placeholder('—')->toggleable(),
                TextColumn::make('verification_status')->label('Verified')->badge()->color(fn (?string $state) => $state === 'verified' ? 'success' : 'warning'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'valid' => 'success', 'expiring' => 'warning', 'revoked' => 'gray', default => 'danger'
                }),
            ])
            ->defaultSort('issued_on', 'desc')
            ->filters([SelectFilter::make('status')->options(LearningCertificate::STATUSES), SelectFilter::make('verification_status')->options(['verified' => 'Verified', 'unverified' => 'Unverified'])])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (LearningCertificate $record) => $record->document_path !== null && auth()->user()->can('view', $record))
                    ->url(fn (LearningCertificate $record) => app(Certificates::class)->downloadUrl($record), shouldOpenInNewTab: true),
                Action::make('attach')->label('Attach document')->icon(Heroicon::OutlinedPaperClip)->color('gray')
                    ->visible(fn (LearningCertificate $record) => $record->document_path === null && $record->status !== 'revoked' && auth()->user()->can('learning.certificates'))
                    ->schema([FileUpload::make('file')->disk(StagedUpload::disk())->directory('tmp/certificates')->visibility('private')->acceptedFileTypes(['application/pdf'])->maxSize(10240)->required()])
                    ->action(fn (LearningCertificate $record, array $data) => LearningActions::run(function () use ($record, $data) {
                        $disk = StagedUpload::storage();
                        try {
                            return app(Certificates::class)->attachDocument($record, (string) $disk->get($data['file']), basename($data['file']), auth()->user());
                        } finally {
                            $disk->delete($data['file']);
                        }
                    }, 'Document attached')),
                Action::make('verify')->label('Verify')->icon(Heroicon::OutlinedShieldCheck)->color('success')
                    ->visible(fn (LearningCertificate $record) => $record->verification_status !== 'verified' && $record->status !== 'revoked' && auth()->user()->can('learning.certificates'))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(fn (LearningCertificate $record, array $data) => LearningActions::run(fn () => app(Certificates::class)->verify($record, auth()->user(), $data['note'] ?? null), 'Verified')),
                Action::make('revoke')->label('Revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (LearningCertificate $record) => $record->status !== 'revoked' && auth()->user()->can('learning.certificates'))
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (LearningCertificate $record, array $data) => LearningActions::run(fn () => app(Certificates::class)->revoke($record, $data['reason'], auth()->user()), 'Revoked')),
            ]);
    }

    /** Record an external credential: unverified until someone other than the holder verifies it. */
    public static function recordExternal(): Action
    {
        return Action::make('recordExternal')->label('Record external certificate')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('learning.certificates') || (LearningActions::me() !== null && auth()->user()->can('learning.learn')))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()
                    ->options(fn () => auth()->user()->can('learning.certificates') ? LearningActions::peopleOptions() + [LearningActions::me()?->id => 'Me'] : [LearningActions::me()?->id => 'Me'])
                    ->default(fn () => LearningActions::me()?->id),
                Select::make('course_id')->label('Learning item')->required()->searchable()->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id')->all()),
                TextInput::make('number')->label('Credential number')->required()->maxLength(64),
                TextInput::make('issuer')->required()->maxLength(255),
                DatePicker::make('issued_on')->native(false)->required(),
                DatePicker::make('expires_on')->native(false)->afterOrEqual('issued_on'),
                TextInput::make('credential_url')->url()->maxLength(255),
                FileUpload::make('file')->disk(StagedUpload::disk())->directory('tmp/certificates')->visibility('private')->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])->maxSize(10240),
            ])
            ->action(fn (array $data) => LearningActions::run(function () use ($data) {
                $employee = Employee::query()->findOrFail($data['employee_id']);
                if ($employee->id !== LearningActions::me()?->id && ! auth()->user()->can('learning.certificates')) {
                    throw new \RuntimeException('You can record only your own external certificates.');
                }
                $disk = StagedUpload::storage();
                $file = $data['file'] ?? null;
                try {
                    return app(Certificates::class)->recordExternal($employee, $data, $file ? (string) $disk->get($file) : null, $file ? basename($file) : null, auth()->user());
                } finally {
                    if ($file) {
                        $disk->delete($file);
                    }
                }
            }, 'Recorded — awaiting verification'));
    }

    public static function getPages(): array
    {
        return ['index' => ListLearningCertificates::route('/')];
    }
}
