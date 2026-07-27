<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\RevenueCatController;
use App\Http\Controllers\Api\TravelOrderController;
use App\Http\Controllers\Api\TravelOrderExportController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'timestamp' => now()->toIso8601String(),
]));

Route::prefix('auth')->group(function () {
    Route::post('google', [AuthController::class, 'google']);
    Route::post('apple', [AuthController::class, 'apple']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('revenuecat/sync-credits', [RevenueCatController::class, 'syncCredits']);
        Route::post('credits/consume', [RevenueCatController::class, 'consumeCredit']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('company', [CompanyController::class, 'show']);
    Route::post('company', [CompanyController::class, 'store']);
    Route::patch('company', [CompanyController::class, 'update']);
    Route::post('company/join', [CompanyController::class, 'join']);
    Route::get('exports', [TravelOrderExportController::class, 'index']);
    Route::get('travel-orders/{travelOrder}/exports/{exportFormat:name}', [TravelOrderExportController::class, 'show']);
    Route::apiResource('travel-orders', TravelOrderController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});
