<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\LampyrisAuthController;
use App\Http\Controllers\Api\RevenueCatController;
use App\Http\Controllers\Api\ReceiptScanController;
use App\Http\Controllers\Api\TravelOrderController;
use App\Http\Controllers\Api\TravelOrderExportController;
use App\Http\Controllers\Api\UserVehicleController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'timestamp' => now()->toIso8601String(),
]));

Route::prefix('auth')->group(function () {
    Route::post('google', [AuthController::class, 'google']);
    Route::post('apple', [AuthController::class, 'apple']);

    Route::middleware(['auth:sanctum', 'putni.user'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'update']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('revenuecat/sync-credits', [RevenueCatController::class, 'syncCredits']);
        Route::post('credits/consume', [RevenueCatController::class, 'consumeCredit']);
    });
});

Route::prefix('lampyris/auth')->group(function () {
    Route::post('google', [LampyrisAuthController::class, 'google']);
    Route::post('apple', [LampyrisAuthController::class, 'apple']);

    Route::middleware(['auth:sanctum', 'lampyris.user'])->group(function () {
        Route::get('me', [LampyrisAuthController::class, 'me']);
        Route::post('logout', [LampyrisAuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'putni.user'])->group(function () {
    Route::get('vehicles/catalog', [UserVehicleController::class, 'catalog']);
    Route::apiResource('vehicles', UserVehicleController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('receipt-scans', [ReceiptScanController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('company', [CompanyController::class, 'show']);
    Route::post('company', [CompanyController::class, 'store']);
    Route::patch('company', [CompanyController::class, 'update']);
    Route::post('company/join', [CompanyController::class, 'join']);
    Route::get('company/members/{member}', [CompanyController::class, 'member']);
    Route::delete('company/members/{member}', [CompanyController::class, 'removeMember']);
    Route::get('exports', [TravelOrderExportController::class, 'index']);
    Route::get('travel-orders/{travelOrder}/exports/{exportFormat:name}', [TravelOrderExportController::class, 'show']);
    Route::apiResource('travel-orders', TravelOrderController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});
