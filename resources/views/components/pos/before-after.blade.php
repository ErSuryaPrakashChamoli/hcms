@props(['changes' => [], 'title' => 'What changes', 'effective' => null])
{{-- Kept for existing callers; renders the one PeopleChange component. --}}
<x-pos.change :changes="$changes" :title="$title" :effective="$effective" {{ $attributes }} />
