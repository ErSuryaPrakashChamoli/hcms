<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\PersonPeek;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * UX.15: one host for person peeks. The interaction layer asks for a person after a short hover or
 * keyboard focus on any [data-person] chip; the answer is the PersonPeek read model (directory fields the
 * viewer may already see). Nothing is rendered server-side per peek; the card is drawn from the answer.
 */
class PeekHost extends Component
{
    /** @return array<string, mixed>|null */
    #[Renderless]
    public function peek(int $id): ?array
    {
        $user = auth()->user();

        return $user === null ? null : app(PersonPeek::class)->for($user, $id);
    }

    public function render(): View
    {
        return view('livewire.experience.peek-host');
    }
}
