<div class="space-y-1">
    <p class="text-sm font-medium text-gray-950 dark:text-white">Current signature</p>
    @if ($image)
        <div class="inline-block rounded-lg bg-white p-3 ring-1 ring-gray-950/10">
            <img alt="Current signature" class="h-16 w-auto" src="data:{{ $image['mime'] }};base64,{{ base64_encode($image['bytes']) }}">
        </div>
    @else
        <p class="text-sm text-danger-600 dark:text-danger-400">The signature file is missing or is not a PNG or JPEG image. Upload it again.</p>
    @endif
</div>
