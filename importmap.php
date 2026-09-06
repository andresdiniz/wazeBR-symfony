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
    'chart.js' => [
        'version' => '4.5.1',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    'reset_password' => [
        'path' => 'assets/controllers/reset_password.js',
        'entrypoint' => true,
    ],
];
