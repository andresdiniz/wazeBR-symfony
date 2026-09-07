<?php

/**
 * Returns the importmap for this application.
 *
 * @return array<string, array{path?: string, version?: string, entrypoint?: bool}>
 */
return [
    'app' => [
        'path' => 'assets/app.js',
        'entrypoint' => true,
    ],
    ,
    'admin-partner' => [
        'path' => 'assets/admin-partner.js',
        'entrypoint' => true,
    ],
];
