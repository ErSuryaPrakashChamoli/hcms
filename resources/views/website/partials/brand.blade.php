{{-- The site's wordmark: the PeopleOS monogram and the site name in the display serif, the last two letters accented when the name is PeopleOS. --}}
@php($name = $site->name())
<a href="{{ url('/') }}" class="ps-brand" aria-label="{{ $name }} home">
    <svg class="ps-brand-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="9" fill="#574bc4" />
        <circle cx="16" cy="12" r="4.2" fill="#ffffff" />
        <path d="M8.5 24.5c1.1-4.1 4-6.4 7.5-6.4s6.4 2.3 7.5 6.4" fill="none" stroke="#e2c48c" stroke-width="2.4" stroke-linecap="round" />
    </svg>
    @if (str_ends_with($name, 'OS') && mb_strlen($name) > 2)
        <span class="ps-brand-word">{{ mb_substr($name, 0, -2) }}<span>OS</span></span>
    @else
        <span class="ps-brand-word">{{ $name }}</span>
    @endif
</a>
