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
        'timeout' => (int) env('OLLAMA_TIMEOUT_SECONDS', 300),
        'notebook_timeout' => (int) env('OLLAMA_CHAT_TIMEOUT_SECONDS', 60),
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT_SECONDS', 15),
        'retry_delays' => [1, 3, 8],
    ],
    'ai_notebook' => [
        'day_reset_timezone' => env('AI_NOTEBOOK_DAY_RESET_TIMEZONE', 'Asia/Karachi'),
        'max_concurrent' => max(1, min(8, (int) env('NOTEBOOK_MAX_CONCURRENT', 8))),
    ],
    'grading' => [
        // Typed answers are queued for the background worker so web requests never
        // wait on the AI provider (shared hosting has strict process limits).
        // Set GRADING_QUEUE_TYPED=false to grade typed answers during the request.
        'queue_typed' => filter_var(env('GRADING_QUEUE_TYPED', true), FILTER_VALIDATE_BOOL),
        // Maximum number of submissions graded at the same time across all processes.
        'workers' => max(1, min(4, (int) env('GRADING_WORKERS', 2))),
        // A PROCESSING submission untouched for this long is treated as a crashed run.
        'stale_minutes' => max(10, min(180, (int) env('GRADING_STALE_MINUTES', 45))),
        // Stop starting new submissions after this many seconds (cPanel cron runs every 15 minutes).
        'run_seconds' => max(30, min(1500, (int) env('GRADING_RUN_SECONDS', 780))),
    ],
    'pdf_renderer' => [
        'binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    ],
];
