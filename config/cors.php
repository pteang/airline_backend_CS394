<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Frontend origins allowed to call the API. Set CORS_ALLOWED_ORIGINS in the
     * environment to a comma-separated list of your deployed frontend URLs
     * (e.g. "https://app.example.com,https://admin.example.com"). When unset,
     * the common local dev-server ports are allowed so the frontend works out
     * of the box.
     */
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', [
            'http://localhost:5173', // Vite dev server (npm run dev)
            'http://localhost:3000', // CRA / Next.js
            'http://localhost:8080', // Dockerized frontend (nginx, docker-compose)
        ])))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
