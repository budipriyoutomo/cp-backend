<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Detik menunggu konfirmasi broker per publish.
    'publish_confirm_timeout' => (int) env('RABBITMQ_PUBLISH_CONFIRM_TIMEOUT', 5),

    // Tujuan pesan `closingreport.submitted` ke BI. Default = kontrak yang
    // disepakati; ubah hanya kalau sisi BI ikut berubah.
    'closing_report' => [
        'exchange' => env('RABBITMQ_CLOSING_REPORT_EXCHANGE', 'closingreport_exchange'),
        'routing_key' => env('RABBITMQ_CLOSING_REPORT_ROUTING_KEY', 'closingreport.submitted'),

        // Batas percobaan `closing-report:publish-pending`. Jeda tumbuh 1, 2,
        // 4, … menit lalu tertahan di 60, jadi 30 percobaan ≈ 25 jam — cukup
        // untuk broker yang mati semalaman. Lewat batas, kirim manual dengan --id.
        'max_attempts' => (int) env('RABBITMQ_CLOSING_REPORT_MAX_ATTEMPTS', 30),
    ],
];
