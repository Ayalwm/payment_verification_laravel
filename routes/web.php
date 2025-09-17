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
            ],
            'Image_Verification' => [
                'name' => 'Image-based Verification',
                'endpoints' => [
                    'CBE' => [
                        'endpoint' => '/api/image/cbe/verify',
                        'method' => 'POST',
                        'params' => ['image (file)', 'account_number (required)']
                    ],
                    'BOA' => [
                        'endpoint' => '/api/image/boa/verify',
                        'method' => 'POST',
                        'params' => ['image (file)', 'sender_account (optional)']
                    ],
                    'Telebirr' => [
                        'endpoint' => '/api/image/telebirr/verify',
                        'method' => 'POST',
                        'params' => ['image (file)']
                    ]
                ]
            ]
        ],
        'timestamp' => now()
    ]);
});