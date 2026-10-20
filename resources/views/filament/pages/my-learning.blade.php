<x-filament-panels::page>
    @php($enrolments = $this->enrolments())
    <x-filament::section heading="My learning" :description="$enrolments->whereIn('status', ['assigned','enrolled','approved','in_progress','overdue'])->count().' open · '.$enrolments->where('status', 'overdue')->count().' overdue'">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Course</th><th>Status</th><th>Progress</th><th>Due</th><th>Version</th></tr></thead>
            <tbody>
                @forelse ($enrolments as $e)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1"><a class="text-primary-600 hover:underline" href="{{ $this->enrolmentUrl($e) }}">{{ $e->courseVersion?->title ?? $e->course?->title }}</a>@if ($e->is_mandatory) <x-filament::badge color="danger" size="sm">mandatory</x-filament::badge>@endif</td>
                        <td><x-filament::badge :color="\App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource::statusColor($e->status)">{{ config('peopleos.learning.enrolment_statuses.'.$e->status, $e->status) }}</x-filament::badge></td>
                        <td>
                            <div class="h-2 w-24 rounded bg-gray-200 dark:bg-gray-700"><div class="h-2 rounded bg-primary-500" style="width: {{ (int) $e->progress }}%"></div></div>
                        </td>
                        <td>{{ $e->due_on?->toDateString() ?? '—' }}</td>
                        <td>v{{ $e->courseVersion?->version ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Nothing assigned yet. Use “Request learning” to ask for a course.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="My certificates">
            <ul class="text-sm">
                @forelse ($this->certificates() as $c)
                    <li class="py-1">{{ $c->course?->title }} — {{ $c->number }}
                        <x-filament::badge :color="match ($c->status) { 'valid' => 'success', 'expiring' => 'warning', 'revoked' => 'gray', default => 'danger' }" size="sm">{{ $c->status }}</x-filament::badge>
                        <span class="text-gray-500">{{ $c->expires_on ? 'expires '.$c->expires_on->toDateString() : 'no expiry' }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">No certificates yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="My skills" description="Verified levels come from assessments, certifications and learning; self-declared levels are shown separately.">
            <ul class="text-sm">
                @forelse ($this->skills() as $s)
                    <li class="py-1">{{ $s['skill'] }} — {{ $s['label'] ?? '—' }}
                        @if ($s['verified']) <x-filament::badge color="success" size="sm">verified</x-filament::badge> @elseif ($s['basis']) <x-filament::badge color="gray" size="sm">{{ $s['basis'] }}</x-filament::badge> @endif
                        @if ($s['target'] !== null) <span class="text-gray-500">target {{ $s['target'] }}{{ $s['gap'] ? ', gap '.$s['gap'] : '' }}</span> @endif
                    </li>
                @empty
                    <li class="text-gray-500">No skills recorded yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="My assessments">
            <ul class="text-sm">
                @forelse ($this->assessments() as $a)
                    <li class="py-1">{{ $a->skill?->name }} — {{ $a->scaleVersion?->labelFor((float) $a->level) }} ({{ \App\Domain\Skills\Models\SkillAssessment::TYPES[$a->assessment_type] }}, {{ $a->assessed_on?->toDateString() }}){{ $a->status === 'superseded' ? ' — corrected' : '' }}</li>
                @empty
                    <li class="text-gray-500">No finalized assessments.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="My development plans">
            <ul class="text-sm">
                @forelse ($this->plans() as $p)
                    <li class="py-1">{{ $p->title }} <x-filament::badge color="gray" size="sm">{{ \App\Domain\Development\Models\DevelopmentPlan::STATUSES[$p->status] }}</x-filament::badge> <span class="text-gray-500">{{ $p->items_count - $p->open_items_count }} / {{ $p->items_count }} items done</span></li>
                @empty
                    <li class="text-gray-500">No development plans yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
