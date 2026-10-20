<?php

namespace App\Filament\Support;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Services\Catalogue;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;

/** Phase 8 course lifecycle actions: submit, approve (second person), send back, publish a version, retire, archive, versions. */
final class CourseLifecycleActions
{
    /** @return array<int, Action> */
    public static function all(): array
    {
        $manage = fn () => auth()->user()->can('learning.manage');

        return [
            Action::make('submit')->label('Submit for approval')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                ->visible(fn (Course $record) => $manage() && in_array('pending_approval', Course::TRANSITIONS[$record->status] ?? [], true))
                ->requiresConfirmation()
                ->action(fn (Course $record) => LearningActions::run(fn () => app(Catalogue::class)->submitForApproval($record, auth()->user()), 'Submitted for approval')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Course $record) => $record->status === 'pending_approval' && auth()->user()->can('learning.publish') && (int) $record->submitted_by !== (int) auth()->id())
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (Course $record, array $data) => LearningActions::run(fn () => app(Catalogue::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Approved for publication')),
            Action::make('sendBack')->label('Send back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn (Course $record) => $record->status === 'pending_approval' && auth()->user()->can('learning.publish'))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Course $record, array $data) => LearningActions::run(fn () => app(Catalogue::class)->reject($record, auth()->user(), $data['reason']), 'Sent back to draft')),
            Action::make('publish')->label('Publish version')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                ->visible(fn (Course $record) => $manage() && ($record->status === 'approved' || (! config('peopleos.learning.require_catalogue_approval', true) && in_array($record->status, ['draft', 'published', 'active'], true))))
                ->modalDescription('Publishing snapshots the curriculum, assessment, provider, instructor, validity and skill outcomes as a new immutable version. Existing learners keep the version they started.')
                ->schema([DatePicker::make('effective_from')->native(false)->helperText('A future date schedules the course.')])
                ->action(fn (Course $record, array $data) => LearningActions::run(fn () => app(Catalogue::class)->publish($record, auth()->user(), $data['effective_from'] ?? null), fn (CourseVersion $v) => "Version {$v->version} published")),
            Action::make('retire')->label('Retire')->icon(Heroicon::OutlinedArchiveBoxXMark)->color('danger')
                ->visible(fn (Course $record) => $manage() && in_array('retired', Course::TRANSITIONS[$record->status] ?? [], true))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Course $record, array $data) => LearningActions::run(fn () => app(Catalogue::class)->retire($record, $data['reason'], auth()->user()), 'Retired')),
            Action::make('archive')->label('Archive')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn (Course $record) => $manage() && $record->status === 'retired')
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Course $record, array $data) => LearningActions::run(fn () => app(Catalogue::class)->archive($record, $data['reason'], auth()->user()), 'Archived')),
            Action::make('versions')->label('Versions')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                ->visible(fn (Course $record) => $record->current_version_id !== null)
                ->schema(fn (Course $record) => $record->versions()->get()->map(fn (CourseVersion $v) => TextEntry::make("v{$v->id}")->label("v{$v->version} · ".$v->published_at?->toDateString())
                    ->state(sprintf('%s · %d module(s) · %s · valid %s · checksum %s', $v->title, count($v->curriculum ?? []), $v->assessment ? 'assessed' : 'no assessment', $v->validity_months ? $v->validity_months.' months' : 'no expiry', substr($v->checksum, 0, 12))))->all()),
        ];
    }
}
