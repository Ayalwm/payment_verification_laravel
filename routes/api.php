<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CBEController;

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
    Route::get('/history', [CBEController::class, 'history']);
    Route::get('/{id}', [CBEController::class, 'show']);
});
