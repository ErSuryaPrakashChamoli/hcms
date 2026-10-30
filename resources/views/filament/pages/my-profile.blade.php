<x-filament-panels::page>
    @php($me = $this::me())
    @php($profile = $this->profile())
    @php($user = auth()->user())
    @php($head = $profile->header($me))
    @php($sections = $this->sections())
    <div class="text-sm text-gray-600">{{ $head['name'] }} · {{ $head['code'] }}@if ($head['line']) · {{ $head['line'] }}@endif</div>

    <x-filament::tabs>
        @foreach ($sections as $key => $label)
            <x-filament::tabs.item :active="$section === $key" wire:click="setSection('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @switch($section)
        @case('personal')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'personal'])
            @php($personal = $profile->personal($user, $me, $revealed))
            @if ($personal['sensitive'])
                <x-filament::section heading="Identification" description="Masked to the last four characters. Showing a value in full is recorded.">
                    @if ($personal['identifiers'] === [])
                        <p class="text-sm text-gray-500">Nothing recorded yet.</p>
                    @else
                        <dl class="grid gap-4 text-sm md:grid-cols-4">
                            @foreach ($personal['identifiers'] as $id)
                                <div>
                                    <dt class="text-gray-500">{{ $id['label'] }}</dt>
                                    <dd data-identifier="{{ $id['field'] }}">{{ $id['value'] }}
                                        @if ($id['revealed'])
                                            <x-filament::link tag="button" size="sm" wire:click="conceal('{{ $id['field'] }}')">Hide</x-filament::link>
                                        @else
                                            <x-filament::link tag="button" size="sm" wire:click="reveal('{{ $id['field'] }}')">Show</x-filament::link>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </x-filament::section>
            @endif
            <x-filament::section heading="Contact">
                <dl class="grid gap-4 text-sm md:grid-cols-4">
                    @forelse ($personal['contact'] as $label => $value)
                        <div><dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @empty
                        <p class="text-sm text-gray-500">No personal contact details recorded.</p>
                    @endforelse
                </dl>
            </x-filament::section>
            <x-filament::section heading="Addresses">
                @forelse ($personal['addresses'] as $a)
                    <p class="text-sm"><span class="text-gray-500">{{ $a['type'] }}:</span> {{ $a['address'] }}</p>
                @empty
                    <p class="text-sm text-gray-500">No address recorded.</p>
                @endforelse
            </x-filament::section>
            <x-filament::section heading="Emergency contacts">
                @forelse ($personal['emergency'] as $c)
                    <p class="text-sm">{{ $c['name'] }}@if ($c['relation']) ({{ $c['relation'] }})@endif · {{ $c['phone'] }}@if ($c['email']) · {{ $c['email'] }}@endif</p>
                @empty
                    <p class="text-sm text-gray-500">No emergency contact recorded.</p>
                @endforelse
            </x-filament::section>
            @break

        @case('family')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'family'])
            @php($family = $profile->family($user, $me))
            <x-filament::section heading="Family">
                <p class="text-sm"><span class="text-gray-500">Marital status:</span> {{ $family['marital_status'] ?? '—' }}</p>
                <table class="mt-3 w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Name</th><th>Relation</th><th>Date of birth</th><th>Dependent</th><th>Nominee</th></tr></thead>
                    <tbody>
                        @forelse ($family['members'] as $m)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $m['name'] }}</td><td>{{ $m['relation'] }}</td><td>{{ $m['date_of_birth'] ?? '—' }}</td><td>{{ $m['dependent'] }}</td><td>{{ $m['nominee'] }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500">No family members recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
            @break

        @case('professional')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'professional'])
            @php($pro = $profile->professional($user, $me))
            <x-filament::section heading="Work experience" :description="$pro['total_months'] > 0 ? 'Earlier experience: '.intdiv($pro['total_months'], 12).' years '.($pro['total_months'] % 12).' months' : null">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Company</th><th>Designation</th><th>From</th><th>To</th><th>Months</th></tr></thead>
                    <tbody>
                        @forelse ($pro['experience'] as $x)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $x['employer'] }}</td><td>{{ $x['designation'] ?? '—' }}</td><td>{{ $x['from'] ?? '—' }}</td><td>{{ $x['to'] }}</td><td>{{ $x['months'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500">No earlier employment recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
            <x-filament::section heading="Education">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Qualification</th><th>Specialisation</th><th>Institute</th><th>Board / university</th><th>Year</th><th>Grade</th></tr></thead>
                    <tbody>
                        @forelse ($pro['education'] as $q)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $q['qualification'] }}</td><td>{{ $q['specialisation'] ?? '—' }}</td><td>{{ $q['institution'] ?? '—' }}</td><td>{{ $q['board'] ?? '—' }}</td><td>{{ $q['year'] ?? '—' }}</td><td>{{ $q['grade'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="py-2 text-gray-500">No qualifications recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
            @break

        @case('skills')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'skills'])
            <x-filament::section heading="Skills">
                @forelse ($profile->skills($user, $me) as $s)
                    <p class="text-sm">{{ $s['skill'] }}@if ($s['level']) · {{ $s['level'] }}@endif @if ($s['verified'])<span class="text-xs text-primary-600">(verified)</span>@endif</p>
                @empty
                    <p class="text-sm text-gray-500">No skills on your profile yet. Add them in My learning.</p>
                @endforelse
            </x-filament::section>
            {{-- HROne parity MS-3: the skills the person declares on their profile (person_skills). --}}
            @php($declared = $profile->profileSkills($user, $me))
            @if ($declared !== [])
                <x-filament::section heading="Skills you declared">
                    @foreach ($declared as $s)
                        <p class="text-sm">{{ $s['skill'] }}@if ($s['level']) · {{ $s['level'] }}@endif @if ($s['years'] !== null)<span class="text-xs text-gray-500">({{ $s['years'] }} years)</span>@endif</p>
                    @endforeach
                </x-filament::section>
            @endif
            @break

        @case('other_details')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'other_details'])
            <x-filament::section heading="Other details">
                <dl class="grid gap-4 text-sm md:grid-cols-4">
                    @forelse ($profile->otherDetails($user, $me) as $f)
                        <div><dt class="text-gray-500">{{ $f['label'] }}</dt><dd>{{ $f['value'] ?? '—' }}</dd></div>
                    @empty
                        <p class="text-sm text-gray-500">No other details.</p>
                    @endforelse
                </dl>
            </x-filament::section>
            @break

        @case('bank')
            @include('filament.pages.my-profile.edit-units', ['tab' => 'bank'])
            @php($bank = $profile->bank($user, $me, $revealed))
            <x-filament::section heading="Salary payment" :description="$this->changes()->editable($user, $me, 'bank') ? null : 'Changes go through HR (Bank account change).'">
                <dl class="grid gap-4 text-sm md:grid-cols-4">
                    <div><dt class="text-gray-500">Salary pay mode</dt><dd>{{ $bank['pay_mode'] }}</dd></div>
                    <div><dt class="text-gray-500">Reimbursements</dt><dd>{{ $bank['reimbursement_mode'] ?? 'As salary' }}</dd></div>
                </dl>
                <table class="mt-3 w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Bank</th><th>Branch</th><th>IFSC</th><th>Type</th>@if ($bank['sensitive'])<th>Account number</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($bank['accounts'] as $a)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1">{{ $a['bank'] }}@if ($a['primary']) <span class="text-xs text-primary-600">(primary)</span>@endif</td>
                                <td>{{ $a['branch'] ?? '—' }}</td><td>{{ $a['ifsc'] ?? '—' }}</td><td>{{ $a['type'] ?? '—' }}</td>
                                @if ($bank['sensitive'])
                                    <td data-account="{{ $a['id'] }}">{{ $a['number'] }}
                                        @if ($a['revealed'])
                                            <x-filament::link tag="button" size="sm" wire:click="conceal('bank:{{ $a['id'] }}')">Hide</x-filament::link>
                                        @else
                                            <x-filament::link tag="button" size="sm" wire:click="reveal('bank:{{ $a['id'] }}')">Show</x-filament::link>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500">No bank account recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
            @break

        @case('previous_employer')
            @php($previous = $profile->previousEmployer($user, $me))
            <x-filament::section heading="Previous employer ({{ $previous['year'] }})" description="From your investment declaration for this financial year.">
                @if ($previous['declared'])
                    <dl class="grid gap-4 text-sm md:grid-cols-4">
                        @foreach ($previous['facts'] as $label => $value)
                            <div><dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                @else
                    <p class="text-sm text-gray-500">No previous employer declared for this financial year.</p>
                @endif
            </x-filament::section>
            @break

        @case('documents')
            @php($groups = $profile->documents($user, $me))
            @php($links = $this->documentLinks())
            @php($zip = $this->downloadAllUrl())
            <x-filament::section heading="Documents" description="Every document type you may see, uploaded or not. HR verifies what you upload in My HR.">
                @if ($zip)
                    <x-slot name="afterHeader"><x-filament::button tag="a" :href="$zip" size="sm" color="gray" icon="heroicon-o-arrow-down-tray">Download all</x-filament::button></x-slot>
                @endif
                @forelse ($groups as $category => $rows)
                    <p class="mt-3 text-sm font-semibold">{{ $category }}</p>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1">#</th><th>Document</th><th>File</th><th>Last updated</th><th>Status</th><th>Expires</th><th>Your comment</th><th>HR's note</th><th>Sample</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $i => $d)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-1">{{ $i + 1 }}</td>
                                    <td>{{ $d['name'] }}</td>
                                    <td>{{ $d['file'] ?? '—' }}</td>
                                    <td>{{ $d['updated'] ?? '—' }}</td>
                                    <td>{{ $d['status'] }}</td>
                                    <td>{{ $d['expires'] ?? '—' }}</td>
                                    <td>{{ $d['comment'] ?? '—' }}</td>
                                    <td>{{ $d['note'] ?? '—' }}</td>
                                    <td>@if ($d['sample'])<x-filament::link :href="$d['sample']" target="_blank" size="sm">Sample</x-filament::link>@elseif ($d['instructions']){{ $d['instructions'] }}@else — @endif</td>
                                    <td>@if ($d['document_id'] && isset($links[$d['document_id']]))<x-filament::link :href="$links[$d['document_id']]" size="sm">Download</x-filament::link>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @empty
                    <p class="text-sm text-gray-500">No documents yet.</p>
                @endforelse
            </x-filament::section>
            @break

        @case('history')
            @php($history = $profile->history($user, $me))
            <x-filament::section heading="Timeline">
                @forelse ($history['timeline'] as $t)
                    <p class="text-sm"><span class="text-gray-500">{{ $t['date'] }}</span> · {{ $t['title'] }} <span class="text-xs text-gray-500">({{ $t['category'] }})</span>@if ($t['detail']) — {{ $t['detail'] }}@endif</p>
                @empty
                    <p class="text-sm text-gray-500">Nothing yet.</p>
                @endforelse
            </x-filament::section>
            <x-filament::section heading="My changes" description="Changes to your profile, with earlier and new values. Sensitive fields (identifiers, bank, pay) are not listed.">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">When</th><th>Record</th><th>Field</th><th>Earlier</th><th>New</th><th>By</th><th>Request</th></tr></thead>
                    <tbody>
                        @forelse ($history['changes'] as $change)
                            @foreach ($change['fields'] as $f)
                                <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $change['when']?->format('j M Y, H:i') }}</td><td>{{ $change['record'] }}</td><td>{{ $f['field'] }}</td><td>{{ $f['before'] ?? '—' }}</td><td>{{ $f['after'] ?? '—' }}</td><td>{{ $change['by'] }}</td><td>{{ $change['request'] ?? '—' }}</td></tr>
                            @endforeach
                        @empty
                            <tr><td colspan="7" class="py-2 text-gray-500">No changes recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
            {{-- HROne parity MS-3 (22.2.12 #5, #6): the person's profile change requests and their details. --}}
            @php($requests = $this->changeHistory())
            @if (count($requests) > 0)
                <x-filament::section heading="Profile change requests" description="What you asked to change on your profile and what became of it.">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1">Request</th><th>Profile type</th><th>Status</th><th>On</th><th>Last decided</th><th>Pending with</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($requests as $r)
                                <tr class="border-t border-gray-200 dark:border-gray-700" data-change-request="{{ $r->id }}">
                                    <td class="py-1">{{ $r->reference() }}</td>
                                    <td>{{ \App\Domain\People\Support\ProfileEditSections::label((string) $r->section) }}</td>
                                    <td>{{ \App\Domain\People\Models\ProfileChangeRequest::STATUSES[$r->status] ?? $r->status }}</td>
                                    <td>{{ $r->created_at?->format('j M Y') }}</td>
                                    <td>{{ $r->decided_at?->format('j M Y') ?? '—' }}@if ($r->decider) · {{ $r->decider->name }}@endif</td>
                                    <td>@if ($r->isPending()){{ implode(', ', $this->waitingWith($r)) ?: '—' }}@else — @endif</td>
                                    <td>{{ ($this->changeDetailsAction)(['request' => $r->id]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif
            @break

        @default
            @include('filament.pages.my-profile.edit-units', ['tab' => 'snapshot'])
            <x-filament::section heading="Snapshot">
                <dl class="grid gap-4 text-sm md:grid-cols-4">
                    @foreach ($profile->snapshot($user, $me) as $label => $value)
                        <div><dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
    @endswitch
</x-filament-panels::page>
