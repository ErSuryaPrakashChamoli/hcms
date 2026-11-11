<?php

namespace App\Filament\Support;

use App\Filament\Support\Forms\PeopleWizard;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Record forms as step-by-step wizards, the way the policy wizard works: each top-level section of a form is a step,
 * loose fields between sections are a step of their own, and the form ends in Review, which lists what was filled in
 * (create) or changed (edit) and asks for the reason for change. Steps are made from the form's own components, so
 * fields, validation, relationships and the page's save path stay exactly as the resource defines them.
 *
 * Used by record pages (Pages\Concerns\FollowsFormWizard), record drawers and action modals (Actions\Concerns\FollowsFormWizard).
 */
final class FormWizard
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    /** An action's modal form (not a record form): filled in once, then the action's own button (Streamline S6). */
    public const ACTION = 'action';

    /** The fewest content steps (Review not counted) that make a drawer or modal form a wizard; record pages always are. */
    public const DRAWER_MINIMUM = 2;

    /**
     * Or this many fields (streamline S6 and its review, owner 2026-11-10: a wizard wherever there is something to fill in,
     * but a short note, status change or quick edit stays one screen).
     */
    public const MODAL_MINIMUM_FIELDS = 4;

    /** Whether a drawer's or modal's form is long enough to be followed as a wizard. */
    public static function wants(?Schema $form): bool
    {
        return $form !== null && (self::contentSteps($form) >= self::DRAWER_MINIMUM || self::fieldCount($form) >= self::MODAL_MINIMUM_FIELDS);
    }

    /** The most loose components in one step: longer runs of fields are split into steps of about this size. */
    public const LOOSE_STEP_MAXIMUM = 6;

    /**
     * The steps of a form, ending in Review; null when the form has nothing to fill in. Read from the form's schema, so
     * every component already belongs to the form when a step asks whether it shows.
     *
     * @return list<Step>|null
     */
    public static function steps(Schema $form, string $mode, string $subject): ?array
    {
        $parts = self::parts($form->getComponents(withHidden: true));
        if ($parts['steps'] === []) {
            return null;
        }

        $steps = [];
        $labels = [];
        foreach ($parts['steps'] as $i => $part) {
            $content = $part instanceof Component ? self::asStep($part) : Section::make($i === 0 ? $subject : 'More details')->columns(2)->schema($part);
            // A step shows while something in it does (asked of the form's own components, which belong to the form already).
            $shown = $part instanceof Component ? [$part] : $part;
            $steps[] = Step::make(self::label($content, $labels))->columns(1)->schema([$content])
                ->visible(fn (): bool => collect($shown)->contains(fn (mixed $c): bool => ! $c instanceof Component || $c->isVisible()));
        }
        $steps[] = self::reviewStep(isset($labels['Review']) ? 'Review and save' : 'Review', $mode, $parts['reason'], $parts['hidden']);

        return $steps;
    }

    /** How many steps a form makes before Review. */
    public static function contentSteps(?Schema $form): int
    {
        return $form === null ? 0 : count(self::parts($form->getComponents(withHidden: true))['steps']);
    }

    /** Fields a person fills in: at the top and inside layout (a repeater counts once), not hidden ones or the reason for change. */
    public static function fieldCount(Schema|array|null $form): int
    {
        $components = $form instanceof Schema ? $form->getComponents(withHidden: true) : (array) $form;

        return (int) collect($components)->sum(fn (mixed $c): int => match (true) {
            $c instanceof Field => $c->getName() === AuditReasonField::NAME || $c instanceof Hidden ? 0 : 1,
            $c instanceof Component => self::fieldCount((array) $c->getDefaultChildComponents()),
            default => 0,
        });
    }

    /** The wizard around the steps: a step list beside the step on record pages, above it in drawers. */
    public static function wizard(array $steps, string $mode, string $layout): PeopleWizard
    {
        return PeopleWizard::make($steps)
            ->label('Steps')
            // Editing: any step can be opened from the list (the record is complete already); creating and actions go in order.
            ->skippable($mode === self::EDIT)
            ->contained(false)
            ->extraAttributes(['class' => "pos-wizard pos-wizard-{$layout}"]);
    }

    /**
     * Sorts top-level components into steps: a titled section or fieldset is a step; consecutive other components
     * make one step together; hidden fields and the reason for change go to Review.
     *
     * @return array{steps: list<Component|list<Component>>, hidden: list<Component>, reason: list<Component>}
     */
    private static function parts(array $components): array
    {
        $steps = [];
        $loose = [];
        $hidden = [];
        $reason = [];
        foreach ($components as $component) {
            if (! $component instanceof Component) {
                $loose[] = $component;
            } elseif ($component instanceof Field && $component->getName() === AuditReasonField::NAME) {
                $reason[] = $component;
            } elseif ($component instanceof Hidden) {
                $hidden[] = $component;
            } elseif (self::isTitled($component)) {
                array_push($steps, ...self::chunk($loose));
                $loose = [];
                $steps[] = $component;
            } else {
                $loose[] = $component;
            }
        }
        array_push($steps, ...self::chunk($loose));

        return ['steps' => $steps, 'hidden' => $hidden, 'reason' => $reason];
    }

    /**
     * A run of loose components as steps: one step, or (more than LOOSE_STEP_MAXIMUM) steps of about equal size in
     * the form's own order, so a long form without sections is still filled in a few fields at a time.
     *
     * @param  list<mixed>  $loose
     * @return list<list<mixed>>
     */
    private static function chunk(array $loose): array
    {
        if ($loose === []) {
            return [];
        }
        $parts = (int) ceil(count($loose) / self::LOOSE_STEP_MAXIMUM);

        return array_chunk($loose, (int) ceil(count($loose) / $parts));
    }

    private static function isTitled(Component $component): bool
    {
        return ($component instanceof Section && filled($component->getHeading()))
            || ($component instanceof Fieldset && filled($component->getLabel()));
    }

    /** A section shown as a step: open, and as wide as the step. */
    private static function asStep(Component $component): Component
    {
        if ($component instanceof Section) {
            $component->collapsed(false)->collapsible(false);
        }

        return $component->columnSpanFull();
    }

    /** The step's name, from its section; repeated names are numbered so each step stays distinct. */
    private static function label(Component $content, array &$labels): string
    {
        $label = $content instanceof Fieldset ? $content->getLabel() : $content->getHeading();
        $label = trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
        $taken = $labels[$label] ?? 0;
        $labels[$label] = $taken + 1;

        return $taken === 0 ? $label : $label.' ('.($taken + 1).')';
    }

    /** The Review step on its own, for a wizard that builds its own steps (the hire wizard). */
    public static function review(string $mode = self::CREATE): Step
    {
        return self::reviewStep('Review', $mode, [], []);
    }

    /**
     * @param  list<Component>  $reason
     * @param  list<Component>  $hidden
     */
    private static function reviewStep(string $label, string $mode, array $reason, array $hidden): Step
    {
        return Step::make($label)->columns(1)->schema([
            Section::make($label)
                ->description(match ($mode) {
                    self::CREATE => 'What you filled in, step by step. Go back to any step to change it, then create.',
                    self::ACTION => 'What you filled in, step by step. Go back to any step to change it, then confirm.',
                    default => 'What you changed, before → after. Open any step to change more, then save.',
                })
                // The review lists what was filled in for creating and for actions, and what changed for editing.
                ->schema([View::make('filament.shell.wizard-review')->viewData(['mode' => $mode === self::EDIT ? self::EDIT : self::CREATE]), ...$reason]),
            ...$hidden,
        ]);
    }
}
