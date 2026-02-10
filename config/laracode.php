<?php

return [
    'agents' => [
        'default' => 'claude',
        'paths' => [],
    ],

    'defaultMode' => 'interactive',

    'watch' => [
        'paths' => [],
        'mode' => 'interactive',
        'excludePatterns' => [
            '**/.idea/**',
            '**/.vscode/**',
            '**/*.log',
            '**/.DS_Store',
        ],
    ],
];
