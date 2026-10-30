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
 * Used by record pages (Pages\Concerns\FollowsFormWizard) and record drawers (Actions\Concerns\FollowsFormWizard).
 */
final class FormWizard
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    /** The fewest content steps (Review not counted) that make a drawer form a wizard; record pages always are. */
    public const DRAWER_MINIMUM = 2;

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

    /** The wizard around the steps: a step list beside the step on record pages, above it in drawers. */
    public static function wizard(array $steps, string $mode, string $layout): PeopleWizard
    {
        return PeopleWizard::make($steps)
            ->label('Steps')
            // Editing: any step can be opened from the list (the record is complete already); creating goes in order.
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
                if ($loose !== []) {
                    $steps[] = $loose;
                    $loose = [];
                }
                $steps[] = $component;
            } else {
                $loose[] = $component;
            }
        }
        if ($loose !== []) {
            $steps[] = $loose;
        }

        return ['steps' => $steps, 'hidden' => $hidden, 'reason' => $reason];
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

    /**
     * @param  list<Component>  $reason
     * @param  list<Component>  $hidden
     */
    private static function reviewStep(string $label, string $mode, array $reason, array $hidden): Step
    {
        return Step::make($label)->columns(1)->schema([
            Section::make($label)
                ->description($mode === self::CREATE
                    ? 'What you filled in, step by step. Go back to any step to change it, then create.'
                    : 'What you changed, before → after. Open any step to change more, then save.')
                ->schema([View::make('filament.shell.wizard-review')->viewData(['mode' => $mode]), ...$reason]),
            ...$hidden,
        ]);
    }
}
