<?php

/*
| PeopleOS's own icons (resources/svg/peopleos), named pos-<file>: e.g. the rail's section icons pos-rail-people. Only
| the sets are set here; every other option keeps blade-icons' default. After adding an icon, `php artisan icons:cache`
| (part of `php artisan optimize`) refreshes the cached icon list where one exists.
*/

return [
    'sets' => [
        'peopleos' => [
            'path' => 'resources/svg/peopleos',
            'prefix' => 'pos',
        ],
    ],
];
