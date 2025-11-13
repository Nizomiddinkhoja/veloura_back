<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DealerController;
use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Http\Middleware\HandleCors;

Route::get('/test', function () {

});

Route::prefix('v1')->middleware([HandleCors::class])->group(function () {

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::apiResource('products', ProductController::class);
    Route::apiResource('notes', NoteController::class);
    Route::apiResource('dealers', DealerController::class);
    Route::apiResource('purchases', PurchaseController::class);
    Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('sales/paid/{id}', [SaleController::class, 'paid']);
    Route::patch('sales/comment/{id}', [SaleController::class, 'updateComment']);
    Route::post('sales/return/{id}', [SaleController::class, 'returnItems']);
    Route::get('analytics', [SaleController::class, 'analytics']);
});
