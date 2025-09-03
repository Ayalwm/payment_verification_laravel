<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Payment Verification API',
        'status' => 'running',
        'services' => [
            'CBE' => [
                'name' => 'Commercial Bank of Ethiopia',
                'endpoint' => '/api/cbe/verify',
                'method' => 'POST',
                'params' => ['transaction_id', 'account_number']
            ],
            'BOA' => [
                'name' => 'Bank of Abyssinia',
                'endpoint' => '/api/boa/verify',
                'method' => 'POST',
                'params' => ['transaction_id', 'sender_account_last_5_digits']
            ],
            'Telebirr' => [
                'name' => 'Telebirr Mobile Payment',
                'endpoint' => '/api/telebirr/verify',
                'method' => 'POST',
                'params' => ['transaction_id']
            ]
        //     'QR_Scanner' => [
        // 'name' => 'QR Code Scanner (Multi-Service)',
        // 'endpoint' => '/api/qr/scan',
        // 'method' => 'POST',
        // 'params' => ['image', 'account_number (optional)', 'service (optional)'],
        // 'supported_services' => [
        //     'CBE' => 'account_number (optional, defaults to 76316166)',
        //     'Telebirr' => 'no additional parameters needed',
        //     'BOA' => 'account_number (required, last 5 digits of source account)'
        // ]
    // ]
        ],
        'timestamp' => now()
    ]);
});