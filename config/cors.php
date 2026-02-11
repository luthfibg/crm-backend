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

    'allowed_origins' => [
        'http://127.0.0.1:3000',      // Local React dev
        'http://localhost:3000',       // Local React dev
        'http://127.0.0.1:8000',       // Local Laravel dev
        'http://localhost:8000',       // Local Laravel dev
        'http://crm-backend.local',    // Local network
        'http://backend.test',         // Local test domain
        'https://crm-frontend-dusky.vercel.app',     // Vercel frontend production
        'https://vercel.app',          // Allow all Vercel preview deployments
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];