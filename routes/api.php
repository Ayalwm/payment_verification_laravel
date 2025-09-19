<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CBEController;
use App\Http\Controllers\BOAController;
use App\Http\Controllers\TelebirrController;
use App\Http\Controllers\ImageVerificationController;
// use App\Http\Controllers\QRCodeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


// API Health Check endpoint for frontend monitoring
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now(),
        'version' => '1.0.0',
        'services' => [
            'cbe' => 'active',
            'boa' => 'active', 
            'telebirr' => 'active',
            'image_verification' => 'active'
        ]
    ])->header('Access-Control-Allow-Origin', 'http://localhost:3000')
      ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
      ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
});

// General API health check endpoint
Route::get('/', function () {
    return response()->json([
        'message' => 'Payment Verification API',
        'status' => 'running',
        'timestamp' => now(),
        'version' => '1.0.0'
    ])->header('Access-Control-Allow-Origin', 'http://localhost:3000')
      ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
      ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
});


// CBE Transaction Verification Routes
Route::prefix('cbe')->group(function () { 
    Route::post('/verify', [CBEController::class, 'verifyPayment']);
    Route::get('/status', [CBEController::class, 'status']);
});

// BOA Transaction Verification Routes
Route::prefix('boa')->group(function () {
    Route::post('/verify', [BOAController::class, 'verifyPayment']);
    Route::get('/status', [BOAController::class, 'status']);
});

// Telebirr Transaction Verification Routes
Route::prefix('telebirr')->group(function () {
    Route::post('/verify', [TelebirrController::class, 'verifyPayment']);
    Route::get('/status', [TelebirrController::class, 'status']);
});

// Image Verification Routes
Route::prefix('image')->group(function () {
    Route::post('/cbe/verify', [ImageVerificationController::class, 'verifyCbeFromImage']);
    Route::post('/boa/verify', [ImageVerificationController::class, 'verifyBoaFromImage']);
    Route::post('/telebirr/verify', [ImageVerificationController::class, 'verifyTelebirrFromImage']);
});

// // QR Code Scanner Routes
// Route::prefix('qr')->group(function () {
//     Route::post('/scan', [QRCodeController::class, 'scanAndVerify']);
//     Route::get('/status', [QRCodeController::class, 'status']);
// });