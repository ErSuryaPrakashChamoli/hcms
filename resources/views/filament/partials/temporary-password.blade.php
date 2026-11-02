{{--
    A generated temporary password, shown once to the administrator who asked for it (App\Filament\Support\SignInActions).
    PeopleOS keeps only its hash, so closing this is the last time it can be seen.
--}}
<div class="space-y-4 text-sm" x-data="{ copied: false }">
    <div class="flex items-center gap-2 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <code class="flex-1 select-all break-all font-mono text-lg font-semibold tracking-wider text-gray-950 dark:text-white" data-temporary-password>{{ $password }}</code>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-clipboard-document"
            x-on:click="navigator.clipboard?.writeText($el.closest('div').querySelector('[data-temporary-password]').textContent); copied = true">
            <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
        </x-filament::button>
    </div>
    <ul class="list-disc space-y-1 ps-5 text-gray-600 dark:text-gray-300">
        <li>Sign-in e-mail: <span class="font-medium text-gray-950 dark:text-white">{{ $email }}</span></li>
        @if ($until)
            <li>Works until {{ $until }} (UTC).</li>
        @endif
        <li>They choose their own password when they sign in, and go straight in.</li>
        <li>This is the only time it is shown. PeopleOS does not keep it; generate a new one if it is lost.</li>
    </ul>
</div>
