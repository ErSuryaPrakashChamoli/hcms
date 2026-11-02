<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity WO-13 (§23.7.1 #5, "Bulk generate"): a letter for each selected employee through Letters::generate — from a
 * chosen template, or "as per policy": the template the employee's own policy names for a process (e.g. the confirmation
 * policy's letter on confirmation). Letters that need approval wait for it as usual; the others are issued at once only
 * when asked ("Generate & publish").
 */
final class GenerateLettersOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.generate_letter';
    }

    public function label(): string
    {
        return 'Generate letters';
    }

    public function description(): string
    {
        return 'Generates a letter for each employee, from the chosen template or the one their policy names. Letters that need approval go for approval; employees whose policy names no template are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'letter.issue';
    }

    public function icon(): string
    {
        return 'heroicon-m-document-plus';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [
            Radio::make('template_mode')->label('Which template?')->options(['chosen' => 'Choose a template', 'policy' => 'As per policy'])->default('chosen')->inline()->live()->required(),
            Select::make('template_id')->label('Template')->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => $get('template_mode') !== 'policy')->required(fn (Get $get) => $get('template_mode') !== 'policy'),
            Select::make('hook')->label('The letter the policy names for')->options(PolicyLetters::HOOKS)
                ->visible(fn (Get $get) => $get('template_mode') === 'policy')->required(fn (Get $get) => $get('template_mode') === 'policy'),
            Select::make('signatory_ids')->label('Signatories (up to four, in signing order)')->multiple()->maxItems(4)->placeholder('As on the template')
                ->options(fn () => LetterTemplateResource::signatories()),
            KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value'),
            Toggle::make('issue')->label('Issue at once when the template needs no approval (Generate & publish)')->default(false),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $mode = ($data['template_mode'] ?? 'chosen') === 'policy' ? 'policy' : 'chosen';
        $template = null;
        $hook = null;
        if ($mode === 'chosen') {
            $template = LetterTemplate::query()->where('status', 'active')->find((int) ($data['template_id'] ?? 0))?->id ?? throw new RuntimeException('Choose an active letter template.');
        } else {
            $hook = (string) ($data['hook'] ?? '');
            if (! array_key_exists($hook, PolicyLetters::HOOKS)) {
                throw new RuntimeException('Choose which policy letter to generate.');
            }
        }
        $signatories = array_values(array_unique(array_map('intval', array_filter((array) ($data['signatory_ids'] ?? []), 'is_numeric'))));
        if (count($signatories) > 4 || ($signatories !== [] && LetterSignatory::query()->where('status', ActiveStatus::Active)->whereKey($signatories)->count() !== count($signatories))) {
            throw new RuntimeException('Choose up to four active signatories.');
        }
        $extra = collect((array) ($data['extra'] ?? []))->filter(fn ($v, $k) => filled($k) && preg_match('/^[a-zA-Z0-9_.]{1,64}$/', (string) $k))
            ->map(fn ($v) => mb_substr((string) $v, 0, 500))->all();

        return ['template_id' => $template, 'hook' => $hook, 'signatory_ids' => $signatories, 'extra' => $extra, 'issue' => (bool) ($data['issue'] ?? false)];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $template = $input['template_id'] ? LetterTemplate::query()->where('status', 'active')->find($input['template_id']) : PolicyLetters::template((string) $input['hook'], $record);
        if ($template === null) {
            $this->refuse($input['template_id'] ? 'The template is no longer active.' : 'The employee\'s policy names no letter for '.mb_strtolower(PolicyLetters::HOOKS[$input['hook']] ?? 'this').'.');
        }
        $found = LetterSignatory::query()->where('status', ActiveStatus::Active)->whereKey($input['signatory_ids'] ?? [])->get()->keyBy('id');
        $ordered = array_values(array_filter(array_map(fn ($id) => $found->get((int) $id), $input['signatory_ids'] ?? [])));
        $others = [];
        foreach (array_slice($ordered, 1, 3) as $i => $signatory) {
            $others[$i + 2] = $signatory;
        }
        $letters = app(Letters::class);
        $letter = $letters->generate($template, $record, (array) ($input['extra'] ?? []), $actor, null, $ordered[0] ?? null, $ordered === [] ? null : $others, $input['hook'] ?? 'bulk');
        if ($input['issue'] && $letter->status === 'approved') {
            $letter = $letters->issue($letter, $actor);

            return "{$letter->number} issued";
        }

        return $letter->number.' '.($letter->status === 'approved' ? 'ready to issue' : 'sent for approval');
    }
}
