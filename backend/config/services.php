<?php

return [
    'embeddings' => [
        'url' => env('EMBEDDINGS_API_URL'),
        'key' => env('EMBEDDINGS_API_KEY'),
        'model' => env('EMBEDDINGS_MODEL'),
        'dimensions' => env('EMBEDDINGS_DIMENSIONS'),
        'timeout' => (int) env('EMBEDDINGS_TIMEOUT_SECONDS', 30),
    ],
    'ai_chat' => [
        'url' => env('AI_CHAT_API_URL'),
        'key' => env('AI_CHAT_API_KEY'),
        'model' => env('AI_CHAT_MODEL'),
        'timeout' => (int) env('AI_CHAT_TIMEOUT_SECONDS', 45),
    ],
];
