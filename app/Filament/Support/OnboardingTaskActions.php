<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Onboarding\Support\ProfileSections;
use App\Filament\Resources\FeedbackRequests\FeedbackRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use RuntimeException;

final class OnboardingTaskActions
{
    /** @return array<int, Action> */
    public static function forTable(): array
    {
        $can = fn (OnboardingTask $record) => $record->status === 'pending' && auth()->user()->can('act', $record);
        // HROne setup parity P13: a feedback item is done by answering its feedback (the owner, here or on Feedback).
        $feedback = fn (OnboardingTask $record) => $record->type === 'feedback' ? app(FeedbackRequests::class)->pendingFor($record, (string) $record->feedbackTemplate()->value('process')) : null;

        return [
            Action::make('complete')->label('Done')->icon('heroicon-m-check')->color('success')
                ->visible(fn (OnboardingTask $record) => $can($record) && $feedback($record) === null)
                ->schema(fn (OnboardingTask $record) => array_filter([
                    $record->type === 'document' ? Select::make('document_id')->label('Attach uploaded document')
                        ->options(fn () => $record->plan->employee->documents()->when($record->document_type_id, fn ($q) => $q->where('document_type_id', $record->document_type_id))->pluck('title', 'id')->all())
                        ->required()->helperText('Upload it on the Documents tab first.') : null,
                    $record->type === 'profile_section' && filled($record->profile_section) ? TextEntry::make('section')->label('Profile section')
                        ->state(ProfileSections::label($record->profile_section).(ProfileSections::filled($record->plan->employee, $record->profile_section) ? ' — filled in' : ' — not filled in yet: complete it on the profile first')) : null,
                    filled($record->declaration_text) ? TextEntry::make('declaration')->label('Declaration')->state($record->declaration_text) : null,
                    filled($record->declaration_text) ? Checkbox::make('declared')->label('I confirm the declaration above')->accepted() : null,
                    Textarea::make('note')->maxLength(255),
                ]))
                ->action(function (OnboardingTask $record, array $data) {
                    try {
                        $evidence = isset($data['document_id']) ? EmployeeDocument::query()->find($data['document_id']) : null;
                        app(Onboarding::class)->complete($record, $data['note'] ?? null, $evidence, null, (bool) ($data['declared'] ?? false), request()->ip());
                        Notification::make()->success()->title('Task completed')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
            Action::make('giveFeedback')->label('Give feedback')->icon('heroicon-m-pencil-square')->color('success')
                ->visible(fn (OnboardingTask $record) => $record->status === 'pending' && ($request = $feedback($record)) !== null && $request->isRespondent(auth()->user()))
                ->modalHeading(fn (OnboardingTask $record) => $record->title)
                ->schema(fn (OnboardingTask $record) => FeedbackRequestResource::answerFields((array) $feedback($record)?->questions))
                ->action(function (OnboardingTask $record, array $data) use ($feedback) {
                    try {
                        app(FeedbackRequests::class)->submit($feedback($record) ?? throw new RuntimeException('This feedback is no longer pending.'), (array) ($data['answers'] ?? []), auth()->user());
                        Notification::make()->success()->title('Feedback given')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
            Action::make('skip')->label('Skip')->icon('heroicon-m-forward')->color('gray')
                ->visible(fn (OnboardingTask $record) => $record->status === 'pending' && auth()->user()->can('onboarding.manage'))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(function (OnboardingTask $record, array $data) {
                    app(Onboarding::class)->skip($record, $data['note']);
                    Notification::make()->success()->title('Task skipped')->send();
                }),
        ];
    }
}
