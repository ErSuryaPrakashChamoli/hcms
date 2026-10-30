<?php

namespace App\Filament\Resources\MoodReasons\Pages;

use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Models\MoodReason;
use App\Domain\Engagement\Services\MoodCheckIns;
use App\Domain\Engagement\Services\MoodDefaults;
use App\Filament\Resources\MoodReasons\MoodReasonResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ManageMoodReasons extends PeopleManageRecords
{
    protected static string $resource = MoodReasonResource::class;

    /** HROne: "You can add up to 20 active tags". */
    public function getSubheading(): ?string
    {
        return $this->moduleContext()['sentence'].' You can add up to '.MoodReason::MAX_ACTIVE.' active tags ('.MoodReason::query()->where('status', 'active')->count().' active now).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add new reason')
                ->using(function (array $data, Action $action) {
                    try {
                        return MoodReasonResource::getModel()::query()->create($data);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not added')->body($e->getMessage())->send();
                        $action->halt();
                    }
                }),
            Action::make('standard')->label('Add standard reasons')->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->visible(fn () => auth()->user()->can('engagement.manage'))
                ->requiresConfirmation()->modalDescription('Adds HROne’s standard reasons that are missing (inactive once 20 are active). Reasons you already have are left as they are.')
                ->action(function () {
                    $added = app(MoodDefaults::class)->seed();
                    Notification::make()->success()->title($added.' standard reason'.($added === 1 ? '' : 's').' added')->send();
                }),
            // HROne "PREVIEW": the question as employees see it on Home.
            Action::make('preview')->label('Preview')->icon(Heroicon::OutlinedEye)->color('gray')
                ->modalHeading('How’s your mood today?')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => new HtmlString(self::preview())),
        ];
    }

    /** The question, the five moods and the active reasons, as plain HTML (no input). */
    public static function preview(): string
    {
        $moods = app(MoodCheckIns::class);
        $reasons = implode(', ', array_map('e', $moods->reasons()));

        return '<div class="space-y-2 text-sm"><p><strong>'.e(implode(' · ', MoodCheckIn::MOODS)).'</strong></p>'
            .'<p>Reasons: '.($reasons === '' ? 'none active' : $reasons).'</p><p>Comments (optional). '
            .e(['anonymous' => 'Your check-in is anonymous.', 'confidential' => 'Your check-in is confidential.', 'identified' => 'HR sees who checked in.'][$moods->privacy()])
            .'</p><p>Asked: '.e(ucfirst($moods->frequency())).($moods->enabled() ? '' : ' (the mood bot is off)').'</p></div>';
    }
}
