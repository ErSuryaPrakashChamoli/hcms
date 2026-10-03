{{-- Skeleton sized like the content it stands for; announced politely, never used as an empty state. --}}
<section class="pos-card" aria-busy="true" aria-live="polite">
    <span class="sr-only">Loading {{ $title ?? 'content' }}…</span>
    <div class="pos-skeleton h-3 w-28"></div>
    <div class="pos-skeleton mt-3 h-5 w-56"></div>
    <ul class="mt-4 space-y-4" aria-hidden="true">
        @for ($i = 0; $i < ($rows ?? 3); $i++)
            <li class="flex items-center gap-3">
                <div class="pos-skeleton size-9 rounded-full"></div>
                <div class="flex-1 space-y-2">
                    <div class="pos-skeleton h-3.5 w-3/5"></div>
                    <div class="pos-skeleton h-3 w-2/5"></div>
                </div>
            </li>
        @endfor
    </ul>
</section>
