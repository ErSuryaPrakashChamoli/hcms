<?php

use Illuminate\Support\Str;

/*
| SaaS.2: architecture checks that close a class of defect rather than one instance.
*/

/** @return array<string, string> file => source without comments, for every PHP file under app/ */
$appSources = function (): array {
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = '';
        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $sources[Str::after($file->getPathname(), base_path().'/')] = $code;
    }

    return $sources;
};

it('checks only permission keys that exist in the catalogue', function () use ($appSources) {
    $catalogue = collect(config('peopleos.permissions'))->flatMap(fn (array $keys) => array_keys($keys))->flip();
    $unknown = [];

    foreach ($appSources() as $file => $code) {
        preg_match_all('/(?:hasPermission|->can|->cannot|Gate::allows|Gate::denies|Gate::authorize)\(\s*[\'"]([a-z_]+\.[a-z_.]+)[\'"]/', $code, $m);
        // Literal lists of keys handed to a permission check in a loop (AdminCentre-style).
        preg_match_all('/foreach\s*\(\s*\[([^\]]+)\]\s*as\s*\$permission\)/', $code, $lists);
        $keys = [...$m[1], ...collect($lists[1])->flatMap(fn (string $list) => preg_match_all('/[\'"]([a-z_]+\.[a-z_.]+)[\'"]/', $list, $k) ? $k[1] : [])->all()];

        foreach ($keys as $key) {
            if (! $catalogue->has($key)) {
                $unknown[] = "{$file}: {$key}";
            }
        }
    }

    expect($unknown)->toBe([]);
});
