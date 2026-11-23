<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Domain\Configuration\Cloning\CloneLineage;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Policies;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Cloning\CloneAction;
use App\Filament\Support\Cloning\CloneLinks;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class EditPolicy extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = PolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('wizard')
                ->label('Edit in wizard')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('primary')
                ->visible(fn () => auth()->user()->can('policy.update'))
                ->url(fn (Policy $record) => PolicyWizard::getUrl(['policy' => $record->id])),
            Action::make('publish')
                ->label('Publish draft')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->authorize(fn () => auth()->user()->can('policy.publish'))
                ->visible(fn (Policy $record) => $record->draft()->exists())
                ->modalDescription(fn (Policy $record) => app(Policies::class)->awaitsCloneActivation($record)
                    ? 'This is a copy that has not been published yet: publishing it also turns on its '.$record->assignmentRules()->count().' applicability rule(s).' : null)
                ->fillForm(['effective_from' => now()->toDateString()])
                ->schema([
                    DatePicker::make('effective_from')->native(false)->required()->helperText('The previous version ends the day before.'),
                    AuditReasonField::make(),
                ])
                ->action(function (Policy $record, array $data) {
                    try {
                        $version = app(Policies::class)->publish($record, $data['effective_from'], $data[AuditReasonField::NAME] ?? null);
                        Notification::make()->success()->title("Published v{$version->version}")->send();
                        $this->reloadPage($record);
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();
                    }
                }),
            Action::make('newDraft')
                ->label('New draft')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn (Policy $record) => $record->draft()->doesntExist())
                ->action(function (Policy $record) {
                    $draft = app(Policies::class)->draft($record);
                    Notification::make()->success()->title("Draft v{$draft->version} created")->body('Open it with Edit draft under Versions & settings.')->send();
                    $this->reloadPage($record);
                }),
            CloneAction::make('policy'),
            Action::make('compareWithSource')
                ->label('Compare with source')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible(fn (Policy $record) => app(CloneLineage::class)->origin('policy', (int) $record->id) !== null)
                ->modalHeading('Compare with source')
                ->modalContent(fn (Policy $record) => view('filament.cloning.compare', CloneLinks::compare('policy', $record) ?? ['intro' => 'The source is no longer known.', 'rows' => []]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth(Width::FiveExtraLarge),
            DeleteAction::make(),
        ];
    }

    /** A policy with no assignment rules applies to nobody: say so where it is edited. */
    public function getSubheading(): ?string
    {
        $policy = $this->getRecord();
        $lines = [self::lineage($policy)];
        $lines[] = $policy->assignmentRules()->exists() ? null : 'This policy applies to nobody yet: choose who it applies to in the wizard (Applicability) or add a rule under Assignment rules.';

        return implode(' ', array_filter($lines)) ?: null;
    }

    /** Configuration cloning (FR-20): where this policy came from, and where it was copied to. */
    public static function lineage(Policy $policy): ?string
    {
        $lineage = app(CloneLineage::class);
        $parts = [];
        if ($origin = $lineage->origin('policy', (int) $policy->id)) {
            $parts[] = 'Copied from '.($origin->operation?->source_label ?? 'another policy').' on '.$origin->created_at?->format('d M Y')
                .($origin->operation?->initiator ? ' by '.$origin->operation->initiator->name : '').'.'
                .(app(Policies::class)->awaitsCloneActivation($policy) ? ' Inactive until you publish it: publishing also turns on its applicability rules.' : '');
        }
        $copies = $lineage->copies('policy', (int) $policy->id);
        if ($copies->isNotEmpty()) {
            $names = Policy::query()->whereKey($copies->pluck('target_id'))->pluck('code', 'id');
            $parts[] = 'Copied '.$copies->count().' time(s): '.$copies->take(5)->map(fn ($c) => ($names[$c->target_id] ?? 'deleted').($c->destinationCompany ? " ({$c->destinationCompany->name})" : ''))->implode(', ')
                .($copies->count() > 5 ? ', …' : '').'.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** Reload so the versions list shows the new draft or published version (relation managers keep their own state). */
    private function reloadPage(Policy $record): void
    {
        $this->redirect(PolicyResource::getUrl('edit', ['record' => $record]));
    }
}
