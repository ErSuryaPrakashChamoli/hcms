<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity WO-13 (§23.7.1 #5, "Generate & publish" on the letters a process assigned): issues each approved letter as
 * generated (the policy's template), or — "change the letter template" — generates it again from the chosen template as a
 * new letter linked to the old one (D-WO6, never an overwrite) and issues that when it needs no approval. Letters waiting
 * for approval are refused: approval is never skipped.
 */
final class PublishLettersOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'letters.publish';
    }

    public function label(): string
    {
        return 'Generate & publish';
    }

    public function description(): string
    {
        return 'Issues each approved letter to the employee. With "change the template", each letter is generated again from the chosen template (the old one is kept, revoked) and issued when it needs no approval. Letters waiting for approval are not issued.';
    }

    public function model(): string
    {
        return Letter::class;
    }

    public function permission(): string
    {
        return 'letter.issue';
    }

    public function icon(): string
    {
        return 'heroicon-m-paper-airplane';
    }

    public function with(): array
    {
        return ['employee.person'];
    }

    public function schema(): array
    {
        return [
            Toggle::make('change_template')->label('Change the letter template? Off: each letter is issued as generated (as per policy)')->default(false)->live(),
            Select::make('template_id')->label('Template')->options(fn () => LetterTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => (bool) $get('change_template'))->required(fn (Get $get) => (bool) $get('change_template')),
            Textarea::make('reason')->label('Why the letters are generated again')->rows(2)->maxLength(500)
                ->visible(fn (Get $get) => (bool) $get('change_template'))->required(fn (Get $get) => (bool) $get('change_template')),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $change = (bool) ($data['change_template'] ?? false);
        $template = null;
        if ($change) {
            $template = LetterTemplate::query()->where('status', 'active')->find((int) ($data['template_id'] ?? 0))?->id ?? throw new RuntimeException('Choose an active letter template.');
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw new RuntimeException('Say why the letters are generated again.');
            }
        }

        return ['change_template' => $change, 'template_id' => $template, 'reason' => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 500)];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Letter $record */
        return in_array($record->status, ['draft', 'pending_approval', 'approved'], true) ? null : 'Only letters not yet issued are generated and published.';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Letter $record */
        $letters = app(Letters::class);
        if ($input['change_template']) {
            $template = LetterTemplate::query()->where('status', 'active')->find($input['template_id']) ?? $this->refuse('The template is no longer active.');
            $new = $letters->supersede($record, $actor, $input['reason'] ?: 'Generated again from another template', $template, issue: true);

            return "Replaced by {$new->number} (".config("peopleos.letters.statuses.{$new->status}", $new->status).')';
        }
        if ($record->status !== 'approved') {
            $this->refuse('Waiting for approval: approve it first.');
        }

        return $letters->issue($record, $actor)->number.' issued';
    }
}
