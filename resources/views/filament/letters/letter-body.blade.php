@if ($page !== null)
    {{-- Sandboxed: the letter exactly as issued, with no script and no access to this page. --}}
    <iframe sandbox="" srcdoc="{{ $page }}" title="Letter" class="h-[75vh] w-full rounded-lg bg-white ring-1 ring-gray-950/10 dark:ring-white/10"></iframe>
@else
    <div class="space-y-2">
        <p class="text-sm text-gray-500 dark:text-gray-400">The text of the Word letter. Download it to see it with its layout, annexure table and signature.</p>
        <div class="max-h-[70vh] overflow-y-auto whitespace-pre-wrap rounded-lg bg-white px-5 py-4 font-serif text-sm text-gray-900 ring-1 ring-gray-950/10 dark:bg-gray-950 dark:text-gray-100 dark:ring-white/10">{{ $text }}</div>
    </div>
@endif
