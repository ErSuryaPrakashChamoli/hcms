<?php

/*
| Livewire's `$wire` object answers some plain names itself (its `aliases` map in livewire.js: upload, get, set, call,
| dispatch …). A component method with one of those names is shadowed in the browser: `wire:click="upload"` runs Livewire's
| file-upload helper instead of the method, so the click does nothing visible, while Livewire tests (`->call('upload')`)
| still pass because they call the server directly. Data import's "Upload and check" button failed this way (2026-11-01).
*/

it('keeps Livewire component methods clear of the names $wire answers itself', function () {
    $js = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));
    expect(preg_match('/var aliases = \{(.*?)\};/s', $js, $block))->toBe(1, 'livewire.js no longer has its aliases map: update this test');
    preg_match_all('/"([A-Za-z]+)":\s*"\$/', $block[1], $names);
    $reserved = $names[1];
    expect($reserved)->toContain('upload');

    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || ! preg_match('#/app/(Filament|Livewire)/#', $file->getPathname())) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($reserved as $name) {
            if (preg_match('/public\s+function\s+'.preg_quote($name, '/').'\s*\(/', $source) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).'::'.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
});
