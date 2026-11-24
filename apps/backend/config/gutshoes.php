<?php

return [
    // Proxy/load balancer yang boleh mengirim X-Forwarded-* (IP/CIDR dipisah koma, atau "*" bila app
    // hanya bisa dijangkau lewat proxy). Kosong = header itu diabaikan sehingga IP klien tidak bisa
    // dipalsukan; tetapi di belakang proxy, rate limit per IP akan memakai IP proxy.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // V1: fitur refund ditunda. Saat false, pesanan yang sudah dibayar tidak bisa dibatalkan
    // lewat aplikasi dan endpoint proses refund ditutup. Pencatatan refund untuk pembayaran
    // yang terlambat (rekonsiliasi) tetap dibuat agar bisa ditangani manual.
    'refunds_enabled' => (bool) env('GUTSHOES_REFUNDS_ENABLED', false),

    'shipping_driver' => env('SHIPPING_DRIVER', 'fake'),
    'tracking_driver' => env('TRACKING_DRIVER', env('SHIPPING_DRIVER', 'fake')),
    // 0 disables caching. Rates may be up to this many minutes old at checkout.
    'shipping_quote_cache_minutes' => (int) env('SHIPPING_QUOTE_CACHE_MINUTES', 15),

    // Safety net for missed Midtrans webhooks: pending payments older than
    // `after` minutes are re-checked against Midtrans at most once per `interval`.
    'payment_reconcile' => [
        'after_minutes' => (int) env('PAYMENT_RECONCILE_AFTER_MINUTES', 5),
        'interval_minutes' => (int) env('PAYMENT_RECONCILE_INTERVAL_MINUTES', 10),
        'batch_size' => (int) env('PAYMENT_RECONCILE_BATCH_SIZE', 50),
    ],

    'bootstrap_admin' => [
        'email' => env('GUTSHOES_ADMIN_EMAIL'),
        'name' => env('GUTSHOES_ADMIN_NAME'),
        'password' => env('GUTSHOES_ADMIN_PASSWORD'),
    ],
];
