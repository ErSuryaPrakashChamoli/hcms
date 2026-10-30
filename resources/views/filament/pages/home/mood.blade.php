{{-- HROne setup parity HP-9f: the mood bot's question, while it asks this person (once per period). --}}
@if ($h['mood'] ?? null)
    @php($m = $h['mood'])
    <x-pos.section title="How’s your mood today?" :sub="$m['label'].' · '.['anonymous' => 'Anonymous: never linked to you.', 'confidential' => 'Confidential.', 'identified' => 'HR sees your answer.'][$m['privacy']]">
        <div class="pos-panel pos-stream">
            <div class="pos-stream-row">
                <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-face-smile" class="size-4" /></span>
                <span class="pos-stream-body">
                    <span class="flex flex-wrap gap-2">
                        @foreach (\App\Domain\Engagement\Models\MoodCheckIn::MOODS as $value => $label)
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('moodCheckIn', @js(['mood' => $value]))" data-pos-action="mood">{{ $label }}</button>
                        @endforeach
                    </span>
                </span>
                <span></span>
            </div>
        </div>
    </x-pos.section>
@endif
