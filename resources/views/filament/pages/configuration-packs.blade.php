<x-filament-panels::page>
    <x-filament::section heading="Available packs" description="Starting configurations for common organisation types. Applying a pack adds or updates records by code and never deletes anything.">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); gap: 1rem;">
            @foreach ($this->packs as $key => $pack)
                <div style="border:1px solid rgb(var(--gray-200)); border-radius:.75rem; padding:1rem;">
                    <div style="font-weight:600; margin-bottom:.25rem;">{{ $pack['name'] }}</div>
                    <div style="font-size:.875rem; opacity:.75;">{{ $pack['description'] }}</div>
                    <div style="font-size:.75rem; opacity:.5; margin-top:.5rem;">{{ $key }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Blueprints" description="A blueprint is this tenant's configuration only: settings, features, roles, people setup, custom fields, forms and policies. It never contains employee data. Use it to copy configuration between group companies or from staging to production.">
        <p style="font-size:.875rem; opacity:.8;">Export from the header, or import a blueprint file exported from another tenant. Imports are additive and match records by code.</p>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
