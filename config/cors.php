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
        'http://localhost:3000',  // React default
        'http://localhost:3001',  // Alternative React port
        'http://localhost:5173',  // Vite default
        'http://localhost:8080',  // Vue default
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        'https://paymentverifier.vercel.app/',
    ],

    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',  // Allow any localhost port
        '/^http:\/\/127\.0\.0\.1:\d+$/',  // Allow any 127.0.0.1 port
        '/^https:\/\/.*\.vercel\.app$/',  // Allow any Vercel deployment
        '/^https:\/\/.*\.vercel\.dev$/',  // Allow Vercel preview deployments
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [
        'X-Total-Count',
        'X-Page-Count',
    ],

    'max_age' => 86400,  // 24 hours

    'supports_credentials' => true,

];