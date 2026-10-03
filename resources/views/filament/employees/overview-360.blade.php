@php($sections = $getState() ?? [])
<div class="grid gap-3 md:grid-cols-3">
    @foreach ($sections as $section)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold">{{ $section['label'] }}</span>
                <span class="text-xs text-gray-500">{{ $section['owner'] }}</span>
            </div>
            <dl class="mt-2 space-y-1 text-sm">
                @foreach ($section['facts'] as $label => $value)
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-right">{{ $value ?? '—' }}</dd></div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
