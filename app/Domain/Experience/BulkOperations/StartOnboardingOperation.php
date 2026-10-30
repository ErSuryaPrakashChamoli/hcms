<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity WO-1 (§23.2.2 #8 "Initiate onboarding", §23.3.3 #4 pre-boarding "Initiate"): starts each employee's
 * onboarding (or the chosen template's plan) through Onboarding::start — the best matching template when none is chosen.
 */
final class StartOnboardingOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.start_onboarding';
    }

    public function label(): string
    {
        return 'Start onboarding';
    }

    public function description(): string
    {
        return 'Starts a plan for each employee from the chosen template, or from the best match by the templates\' rules. Employees with a plan of that kind in progress are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'onboarding.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-clipboard-document-check';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [
            Select::make('template_id')->label('Template')->placeholder('Best match by rules')
                ->options(fn () => OnboardingTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Leave empty to pick, for each employee, the highest-priority template whose conditions match.'),
            Textarea::make('reason')->rows(2)->maxLength(500),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $template = filled($data['template_id'] ?? null) ? (int) $data['template_id'] : null;
        if ($template !== null && ! OnboardingTemplate::query()->whereKey($template)->where('status', 'active')->exists()) {
            throw new RuntimeException('Choose an active onboarding template.');
        }

        return ['template_id' => $template, 'reason' => filled($data['reason'] ?? null) ? mb_substr((string) $data['reason'], 0, 500) : null];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $template = $input['template_id'] ? OnboardingTemplate::query()->find($input['template_id']) : null;
        if ($input['template_id'] && $template === null) {
            throw new RuntimeException('The template is no longer available.');
        }
        $plan = app(Onboarding::class)->start($record, $template, $input['reason'] ?? null);

        return 'Plan started with '.$plan->tasks()->count().' task(s)';
    }
}
