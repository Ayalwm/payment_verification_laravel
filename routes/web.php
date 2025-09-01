<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Payment Verification API',
        'status' => 'running',
        'timestamp' => now()
    ]);
});
