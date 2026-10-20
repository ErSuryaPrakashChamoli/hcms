<?php

namespace App\Filament\Support;

use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\Feedback;
use App\Domain\Engagement\Services\SurveyResponses;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 13: the employee's survey and feedback actions in My HR.
 *
 * The survey form is built from each question's employee view only (no administrator, scoring or
 * analysis metadata). Nothing is saved until the employee submits, so no draft can link a person to
 * their answers. The confirmation never echoes the answers.
 */
final class EngagementActions
{
    public static function takeSurvey(): Action
    {
        return Action::make('takeSurvey')->label('Take survey')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
            ->modalHeading(fn (array $arguments) => SurveyVersion::query()->with('survey')->find($arguments['version'] ?? 0)?->survey?->name ?? 'Survey')
            ->modalDescription(fn (array $arguments) => self::intro(SurveyVersion::query()->find($arguments['version'] ?? 0)))
            ->mountUsing(function (array $arguments) {
                $version = SurveyVersion::query()->find($arguments['version'] ?? 0);
                if ($version !== null) {
                    app(SurveyResponses::class)->markOpened($version, auth()->user());
                }
            })
            ->schema(function (array $arguments) {
                $version = SurveyVersion::query()->find($arguments['version'] ?? 0);
                if ($version === null || $version->status !== 'open') {
                    return [];
                }
                $fields = SurveyQuestion::query()->where('survey_version_id', $version->id)->orderBy('position')->orderBy('id')->get()
                    ->map(fn (SurveyQuestion $q) => self::field($q->employeeView()))->all();
                $fields[] = Hidden::make('idempotency_key')->default((string) Str::uuid());

                return $fields;
            })
            ->modalSubmitActionLabel('Submit my response')
            ->action(function (array $data, array $arguments) {
                try {
                    $version = SurveyVersion::query()->findOrFail($arguments['version'] ?? 0);
                    $key = $data['idempotency_key'] ?? null;
                    unset($data['idempotency_key']);
                    $answers = collect($data['answers'] ?? [])->all();
                    $result = app(SurveyResponses::class)->submit($version, auth()->user(), $answers, $key);
                    Notification::make()->success()->title($result['status'] === 'submitted' ? 'Thank you — your response was recorded.' : 'You have already responded to this survey.')->send();
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());
                }
            });
    }

    public static function giveFeedback(): Action
    {
        return Action::make('giveFeedback')->label('Give feedback')->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->modalDescription('Identified: HR knows it is from you and can follow up. Confidential: your name is kept apart and revealed only for a serious, audited reason. Anonymous: nobody can ever see who sent it — and nobody can reply.')
            ->schema([
                Radio::make('mode')->label('How do you want to send it?')->options(config('peopleos.engagement.feedback_modes'))->default('identified')->required(),
                Select::make('category')->options(config('peopleos.engagement.feedback_categories'))->required(),
                Textarea::make('body')->label('Your feedback')->required()->minLength(5)->maxLength(5000)->rows(5)
                    ->helperText('For a complaint that needs investigation, raise a grievance; for a request, use Services.'),
            ])
            ->action(function (array $data) {
                try {
                    app(Feedback::class)->submit(auth()->user(), $data['mode'], $data['category'], $data['body']);
                    Notification::make()->success()->title('Thank you — your feedback was sent.')->send();
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());
                }
            });
    }

    private static function intro(?SurveyVersion $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $mode = match ($version->anonymity_mode) {
            'anonymous' => 'Anonymous: your answers are stored without any link to you, and results are shown only for groups of at least '.config('peopleos.engagement.analytics_min_group').' people. Anonymous responses cannot be changed after you submit.',
            'confidential' => 'Confidential: your name is kept apart from your answers and revealed only for a serious, audited reason.',
            default => 'Identified: HR can see your answers.',
        };

        return trim(($version->intro ? $version->intro.' ' : '').$mode.($version->closes_at ? ' Closes on '.$version->closes_at->toDateString().'.' : ''));
    }

    /** @param  array{key: string, type: string, prompt: string, help: ?string, required: bool, options: array<string, string>, scale: ?array}  $q */
    private static function field(array $q): mixed
    {
        $name = 'answers.'.$q['key'];
        $field = match ($q['type']) {
            'multiple_choice' => CheckboxList::make($name)->options($q['options']),
            'single_choice', 'yes_no', 'likert', 'rating' => Radio::make($name)->options($q['options'])->inline(in_array($q['type'], ['yes_no', 'rating', 'likert'], true)),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false)->format('Y-m-d'),
            default => Textarea::make($name)->rows(3)->maxLength(2000),
        };

        return $field->label($q['prompt'])->helperText($q['help'])->required($q['required']);
    }
}
