return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'chart.js' => [
        'version' => '4.4.0',
        'entrypoint' => true,  // <-- Adicione esta linha
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
];
