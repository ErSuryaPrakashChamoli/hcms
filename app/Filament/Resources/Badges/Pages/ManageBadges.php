<?php

namespace App\Filament\Resources\Badges\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Services\RecognitionDefaults;
use App\Domain\Recognition\Services\Recognitions;
use App\Filament\Resources\Badges\BadgeResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ManageBadges extends PeopleManageRecords
{
    protected static string $resource = BadgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add custom badge')
                ->visible(fn () => app(Recognitions::class)->customAllowed() && auth()->user()->can('recognition.manage'))
                ->mutateDataUsing(fn (array $data) => BadgeResource::normalise($data)),
            Action::make('standard')->label('Add standard badges')->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->visible(fn () => auth()->user()->can('recognition.manage'))
                ->requiresConfirmation()->modalDescription('Adds HROne’s standard badges that are missing. Badges you already have are left as they are.')
                ->action(function () {
                    $added = app(RecognitionDefaults::class)->seed();
                    Notification::make()->success()->title($added.' standard badge'.($added === 1 ? '' : 's').' added')->send();
                }),
            // HROne setup parity HP-9c (Social wall → certificate format, PREVIEW): a certificate filled for one employee.
            Action::make('previewCertificate')->label('Preview certificate')->icon(Heroicon::OutlinedEye)->color('gray')
                ->visible(fn () => auth()->user()->can('recognition.manage') && (auth()->user()->can('letter.issue') || auth()->user()->can('letter.manage')))
                ->schema([
                    Select::make('template_id')->label('Certificate (letter template)')->options(fn () => BadgeResource::certificateTemplates())->required(),
                    Select::make('badge_id')->label('Badge')->options(fn () => Badge::query()->orderBy('sort_order')->pluck('name', 'id')->all())->required(),
                    Select::make('employee_id')->label('Employee')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Employee::query()->with('person')->employed()->whereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])->all())
                        ->getOptionLabelUsing(fn ($value) => Employee::query()->find($value)?->auditLabel()),
                ])
                ->action(function (array $data) {
                    try {
                        $badge = Badge::query()->findOrFail($data['badge_id']);
                        $extra = ['badge' => ['name' => $badge->name, 'description' => $badge->description],
                            'recognition' => ['reference' => 'PREVIEW', 'date' => now()->format('d M Y'), 'given_by' => auth()->user()->name, 'reason' => 'Preview']];
                        ['content' => $content, 'name' => $name, 'mime' => $mime] = app(Letters::class)->preview(LetterTemplate::query()->findOrFail($data['template_id']), Employee::query()->findOrFail($data['employee_id']), auth()->user(), $extra);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Cannot preview')->body($e->getMessage())->send();

                        return null;
                    }

                    return response()->streamDownload(fn () => print ($content), $name, ['Content-Type' => $mime]);
                }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'system' => Tab::make('Badges')->modifyQueryUsing(fn (Builder $query) => $query->where('is_system', true)),
            'custom' => Tab::make('Custom badges')->modifyQueryUsing(fn (Builder $query) => $query->where('is_system', false)),
        ];
    }
}
