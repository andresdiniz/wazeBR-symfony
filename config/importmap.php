<?php

return [
    'app' => [
        'path' => './assets/js/app.js',
        'entrypoint' => true,
    ],
    'app-init' => [
        'path' => './assets/js/core/app-init.js',
        'entrypoint' => true,
    ],
    'dashboard' => [
        'path' => './assets/js/pages/dashboard.js',
        'entrypoint' => false,
    ],
    'leaflet' => [
        'version' => '1.9.4',
    ],
    'leaflet/dist/leaflet.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
    'bootstrap-icons/font/bootstrap-icons.css' => [
        'version' => '1.13.1',
        'type' => 'css',
    ],
];
