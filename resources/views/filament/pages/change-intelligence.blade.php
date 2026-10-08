<x-filament-panels::page>
    <p class="text-sm text-gray-500">Read from the immutable audit trail. Values of sensitive fields are masked as written; values on financial, statutory or confidential records are masked unless you may see sensitive employee data. Organisation-scoped users see only records of employees in their scope.</p>
    {{ $this->table }}
</x-filament-panels::page>
