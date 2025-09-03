<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CBEController;
use App\Http\Controllers\BOAController;
use App\Http\Controllers\TelebirrController;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
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

// // QR Code Scanner Routes
// Route::prefix('qr')->group(function () {
//     Route::post('/scan', [QRCodeController::class, 'scanAndVerify']);
//     Route::get('/status', [QRCodeController::class, 'status']);
// });
