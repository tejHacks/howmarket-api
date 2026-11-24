<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PriceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true, 'app' => 'howmarket-api']);

// Signing up and in: 10 tries a minute per IP, against password guessing.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Prices are public to read.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/snapshot', [PriceController::class, 'snapshot']);
    Route::get('/items/{item}/prices', [PriceController::class, 'itemPrices']);
});

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/reports', [PriceController::class, 'report']);
    Route::post('/reports/{report}/vote', [PriceController::class, 'vote'])->whereNumber('report');
});
