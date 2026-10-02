<?php

namespace App\Filament\Resources\Announcements;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Knowledge\Models\Article;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Communication centre (§51), Phase 13:
 * - prepare a draft;
 * - submit it;
 * - have it approved by a second person (communication.approve);
 * - publish it (now or on its date) to a snapshotted, scope-limited audience, delivered through the
 *   Notifier.
 *
 * A submitted announcement never changes: corrections are new versions. Readers use My HR →
 * Communications or the Announcements feed.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('communication.manage') || auth()->user()?->can('communication.approve')) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->columns(3)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                Select::make('type')->options(config('peopleos.communication.types'))->default('announcement')->required()
                    ->helperText('Policy publications and instructions are mandatory: preferences cannot switch them off.'),
                MarkdownEditor::make('body')->required()->columnSpanFull()->helperText('Never include salary, bank, PAN, tax, case or performance details. Link a Knowledge Base article for policy content.'),
                Select::make('article_id')->label('Link a knowledge article')->placeholder('—')->searchable()->options(fn () => Article::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all()),
                Select::make('priority')->options(config('peopleos.communication.priorities'))->default('normal')->required(),
                Select::make('campaign_id')->label('Campaign')->placeholder('—')->options(fn () => EngagementCampaign::query()->whereNotIn('status', ['completed', 'cancelled'])->orderBy('name')->pluck('name', 'id')->all()),
                DateTimePicker::make('publish_at')->native(false)->placeholder('Immediately on publish'),
                DateTimePicker::make('expires_at')->native(false)->placeholder('Never'),
                FileUpload::make('attachment_upload')->label('Attachment (private)')->disk(config('peopleos.documents.disk', 'local'))->directory('communication')->visibility('private')
                    ->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('attachment_upload_name')->dehydrated(),
            ]),
            Section::make('Audience & options')->columns(2)->schema([
                Toggle::make('is_pinned')->label('Pin to the top'),
                Toggle::make('requires_acknowledgement')->label('Employees must acknowledge'),
                Select::make('audience_id')->label('Saved audience')->placeholder('— criteria below (empty = everyone employed in your scope) —')
                    ->options(fn () => Audience::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->columnSpanFull(),
            ]),
            AudienceCriteriaSchema::section(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (callable $callback, string $done) => ServiceDeskActions::run($callback, $done);
        $comms = fn () => app(Communications::class);

        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->description(fn (Announcement $record) => $record->version > 1 ? 'Version '.$record->version : null),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.communication.types.{$state}", $state)),
                TextColumn::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'high' => 'warning', default => 'gray'
                })->toggleable(),
                TextColumn::make('audience_criteria')->label('Audience')->state(fn (Announcement $record) => $record->recipients_count !== null ? $record->recipients_count.' recipients' : ($record->audience_id ? 'Audience: '.Audience::query()->whereKey($record->audience_id)->value('name') : AudienceCriteriaSchema::describe($record->audience_criteria)))->wrap(),
                IconColumn::make('requires_acknowledgement')->label('Ack')->boolean(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Announcement::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'scheduled', 'approved' => 'info', 'in_review' => 'warning', 'cancelled' => 'danger', default => 'gray'
                }),
                TextColumn::make('delivery')->label('Sent / failed / read / ack')->state(function (Announcement $record) use ($comms) {
                    if (! in_array($record->status, ['published', 'archived'], true)) {
                        return '—';
                    }
                    $s = $comms()->stats($record);

                    return ($s['sent'] ?? '—').' / '.($s['failed'] ?? '—').' / '.$s['read'].' / '.$s['acknowledged'].' of '.$s['audience'];
                }),
                TextColumn::make('publish_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('Never')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(Announcement::STATUSES), SelectFilter::make('type')->options(config('peopleos.communication.types'))])
            ->recordActions([
                EditAction::make()->visible(fn (Announcement $record) => $record->status === 'draft'),
                Action::make('attachment')->label('Attachment')->icon('heroicon-m-paper-clip')->color('gray')
                    ->visible(fn (Announcement $record) => $record->attachment_path !== null)
                    ->url(fn (Announcement $record) => $comms()->attachmentUrl($record), shouldOpenInNewTab: true),
                ActionGroup::make([
                    Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                        ->modalDescription('The message and its audience are frozen from now on; a correction is a new version.')
                        ->visible(fn (Announcement $record) => $record->status === 'draft' && auth()->user()->can('communication.manage'))
                        ->action(fn (Announcement $record) => $run(fn () => $comms()->submit($record, auth()->user()), 'Submitted for approval')),
                    Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                        ->visible(fn (Announcement $record) => $record->status === 'in_review' && ! $record->workflow_instance_id && auth()->user()->can('communication.approve') && (int) $record->prepared_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->label('Note (optional)')])
                        ->action(fn (Announcement $record, array $data) => $run(fn () => $comms()->approve($record, $data['note'] ?? null, auth()->user()), 'Approved')),
                    Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (Announcement $record) => in_array($record->status, ['in_review', 'approved'], true) && ! $record->workflow_instance_id && auth()->user()->can('communication.approve'))
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (Announcement $record, array $data) => $run(fn () => $comms()->returnToDraft($record, $data['note'], auth()->user()), 'Returned to draft')),
                    Action::make('publish')->label('Publish')->icon('heroicon-m-megaphone')->color('success')->requiresConfirmation()
                        ->modalDescription('Publishes now (or schedules for its date): the audience is snapshotted and delivery starts.')
                        ->visible(fn (Announcement $record) => $record->status === 'approved')
                        ->action(fn (Announcement $record) => $run(fn () => $comms()->publish($record, auth()->user()), 'Published')),
                    Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')->requiresConfirmation()
                        ->modalDescription('Copies this announcement into a new draft. When the new version is published, this one is archived.')
                        ->visible(fn (Announcement $record) => in_array($record->status, ['in_review', 'approved', 'scheduled', 'published'], true) && auth()->user()->can('communication.manage'))
                        ->action(fn (Announcement $record) => $run(fn () => $comms()->newVersion($record, auth()->user()), 'Draft version created')),
                    Action::make('cancel')->label('Cancel')->icon('heroicon-m-x-mark')->color('danger')
                        ->visible(fn (Announcement $record) => in_array($record->status, ['in_review', 'approved', 'scheduled'], true))
                        ->schema([Textarea::make('reason')->required()])
                        ->action(fn (Announcement $record, array $data) => $run(fn () => $comms()->cancel($record, $data['reason'], auth()->user()), 'Cancelled')),
                    Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('gray')->requiresConfirmation()
                        ->visible(fn (Announcement $record) => $record->status === 'published')
                        ->action(fn (Announcement $record) => $run(fn () => $comms()->archive($record, auth()->user()), 'Archived')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
