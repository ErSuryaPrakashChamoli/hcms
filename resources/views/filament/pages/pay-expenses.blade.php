<x-filament-panels::page>
    <p class="text-sm text-gray-500">Approved expense reports with an amount still owed to the employee, after open advances were settled against them. Record the payment made outside PeopleOS with its reference, or pay it with payroll: the next payroll repays it (non-taxable) and marks it paid when that payroll is finalized. A report a payroll being prepared already includes is paid by that payroll. Your own reports are paid by someone else.</p>
    {{ $this->table }}
</x-filament-panels::page>
