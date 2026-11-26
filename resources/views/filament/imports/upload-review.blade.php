{{-- An upload's own page (owner request 2026-11-26): the columns of the file and the field each is read as (a heading
     PeopleOS did not recognise is flagged to choose its field), and the names the file gives that need a decision
     (not available: choose an existing record or add a new one). Both re-check the file; the page reloads after. --}}
<div class="pos-di" style="gap: var(--pos-space-4)">
    @if ($columns)
        @livewire(\App\Livewire\Imports\ColumnMatch::class, ['importId' => $import->id], key('cols-'.$import->id.'-'.$stamp))
    @endif
    @if ($references)
        @livewire(\App\Livewire\Resolution\ReferenceReview::class, ['report' => $references, 'scope' => 'employee_imports:'.$import->id], key('refs-'.$import->id.'-'.$stamp))
    @endif
</div>
