{{-- HROne parity MS-3: one own profile change — earlier / new per field (masked: the values themselves are never kept), reason, trail. --}}
<div class="space-y-4 text-sm">
    <dl class="grid gap-3 md:grid-cols-3">
        <div><dt class="text-gray-500">Status</dt><dd>{{ \App\Domain\People\Models\ProfileChangeRequest::STATUSES[$request->status] ?? $request->status }}@if ($request->route) · {{ \App\Domain\People\Models\ProfileChangeRequest::ROUTES[$request->route] ?? $request->route }}@endif</dd></div>
        <div><dt class="text-gray-500">Requested on</dt><dd>{{ $request->created_at?->format('j M Y, H:i') }}</dd></div>
        <div><dt class="text-gray-500">Decided</dt><dd>@if ($request->decided_at){{ $request->decided_at->format('j M Y, H:i') }}@if ($request->decider) · {{ $request->decider->name }}@endif @else — @endif</dd></div>
    </dl>
    <p><span class="text-gray-500">Your reason:</span> {{ $request->comment }}</p>
    @if (($request->document_ids ?? []) !== [])
        <p class="text-gray-500">A supporting document was attached (see Documents).</p>
    @endif
    <table class="w-full">
        <thead><tr class="text-left text-gray-500"><th class="py-1">Field</th><th>Earlier</th><th>New</th></tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $row['label'] }}</td><td>{{ $row['earlier'] ?? '—' }}</td><td>{{ $row['new'] ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="3" class="py-2 text-gray-500">No changes.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="text-xs text-gray-500">Values are shown masked: the request keeps only which fields changed. Your profile shows the values themselves.</p>
    @if ($trail && ($trail['entries'] ?? []) !== [])
        <div>
            <p class="font-semibold">Profile history</p>
            @foreach ($trail['entries'] as $entry)
                <p><span class="text-gray-500">{{ $entry['at']?->format('j M Y, H:i') }}</span> · {{ $entry['title'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif @if ($entry['note'])— {{ $entry['note'] }}@endif</p>
            @endforeach
        </div>
    @endif
</div>
