<?php

use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyGoalController;
use App\Http\Controllers\Api\KPIController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('customers', CustomerController::class);
    Route::resource('users', UserController::class);
    Route::resource('kpis', KPIController::class);
    Route::resource('daily-goals', DailyGoalController::class);
    // additional helper route to fetch daily goals by user + kpi
    Route::get('daily-goals/user/{user}/kpi/{kpi}', [DailyGoalController::class, 'byUserKpi'])->name('daily-goals.byUserKpi');
    Route::resource('badges', BadgeController::class);
    Route::resource('progress-updates', ProgressController::class);
});
