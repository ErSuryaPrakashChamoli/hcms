<x-filament-panels::page>
    @if ($this->switchedOn())
        <p class="text-sm text-gray-500">While you are away, the colleague you name sees the requests waiting for you in their Approvals and Task inbox and decides them in your place. The record shows who decided, on whose behalf. Nobody decides their own request.</p>
    @else
        <p class="text-sm text-gray-500">Delegation is not switched on. An administrator chooses which requests may be delegated in Setup → Automation → Inbox actions.</p>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
