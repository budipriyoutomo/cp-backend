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
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://*.calira.my.id',
        'https://*.maharasa.id',
    ],

    // Dulu `#^https://.*\.vusercontent\.net$#` ditulis tetap di sini. Itu domain
    // preview v0.app — siapa pun bisa membuat halaman di sana, jadi halaman
    // orang lain boleh memanggil API ini dari browser korban. Pola tambahan
    // kini dari env, regex dipisah koma (jadi regex-nya tidak boleh berisi
    // koma). **Kosongkan di produksi.** Untuk preview v0 di lokal/staging:
    //   CORS_EXTRA_ORIGIN_PATTERNS="#^https://.*\.vusercontent\.net$#"
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_EXTRA_ORIGIN_PATTERNS', ''))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
