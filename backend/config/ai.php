<?php

return [
    'default' => env('AI_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', false),
            'models' => [
                'text' => ['default' => env('OPENAI_MODEL', 'gpt-4.1-mini')],
            ],
        ],
    ],
];
