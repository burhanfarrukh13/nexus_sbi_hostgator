<?php

return [
    'embeddings' => [
        'url' => env('OPENROUTER_EMBEDDINGS_API_URL', 'https://openrouter.ai/api/v1/embeddings'),
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_EMBEDDINGS_MODEL'),
        'dimensions' => env('OPENROUTER_EMBEDDINGS_DIMENSIONS'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 30),
    ],
    'ai_chat' => [
        'url' => env('OLLAMA_API_URL', 'https://ollama.com/v1/chat/completions'),
        'key' => env('OLLAMA_API_KEY'),
        'model' => env('OLLAMA_MODEL'),
        'timeout' => (int) env('OLLAMA_TIMEOUT_SECONDS', 90),
    ],
];
