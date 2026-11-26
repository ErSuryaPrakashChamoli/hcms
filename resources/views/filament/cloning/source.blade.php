{{-- Clone wizard, step 1: what is being copied (configuration cloning), as a card of facts like the Review's. --}}
@php
    $outcome = \App\Filament\Support\Review\Outcome::make('What you are copying')->icon('heroicon-o-document-duplicate');
    foreach ($rows as [$label, $value]) {
        $outcome->fact($label, $value);
    }
    $outcome->fact('Applies to today', implode("\n", $rules), empty: 'Nobody: it has no current applicability rules');
    if (($notCopied ?? 0) > 0) {
        $outcome->note($notCopied.' inactive or ended rule(s) are not copied.', 'info', 'Left out:');
    }
    if ($copies > 0) {
        $outcome->note('Cloned '.$copies.' time(s) before.', 'info', 'Earlier copies:');
    }
    $outcome->note('One shared record is easier to keep up than two copies: extend this record\'s applicability instead.', 'next', 'Same rules for another company?');
@endphp
<div class="pos-clone">
    {{ $outcome }}
    @if ($rulesUrl)
        <p class="pos-body-sm" style="margin-top: var(--pos-space-2)"><a class="underline" href="{{ $rulesUrl }}">Open its rules</a></p>
    @endif
</div>
