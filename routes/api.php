<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MobileSurveyController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});

// Mobile PWA (field data collection)
Route::prefix('mobile')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/surveys', [MobileSurveyController::class, 'surveys']);
        Route::get('/surveys/{survey}', [MobileSurveyController::class, 'show']);
        Route::get('/barangays', [MobileSurveyController::class, 'barangays']);
        Route::get('/submitted-records', [MobileSurveyController::class, 'submittedRecords']);
        Route::get('/surveys/{survey}/responses', [MobileSurveyController::class, 'surveyResponses']);
        Route::post('/sync', [MobileSurveyController::class, 'sync'])->name('api.mobile.sync');
    });

// Map + dashboard data
Route::get('map/community', [MapController::class, 'community'])->name('api.map.community');
Route::get('map/aggregation', [MapController::class, 'aggregation'])->name('api.map.aggregation');
Route::get('map/extensions', [MapController::class, 'extensions'])->name('api.map.extensions');

Route::prefix('dashboard')->group(function () {
    Route::get('statistics', [DashboardController::class, 'statistics'])->name('api.dashboard.statistics');
    Route::get('activities', [DashboardController::class, 'activities'])->name('api.dashboard.activities');
});
