<?php

use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyGoalController;
use App\Http\Controllers\Api\KPIController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::options('{any}', function () {

    return response()->json(null, 204);
    
})->where('any', '.*');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::resource('users', UserController::class);
Route::middleware('auth:sanctum')->group(function () {
    Route::resource('customers', CustomerController::class);
    Route::resource('kpis', KPIController::class);
    Route::resource('daily-goals', DailyGoalController::class);
    // additional helper route to fetch daily goals by user + kpi
    Route::get('daily-goals/user/{user}/kpi/{kpi}', [DailyGoalController::class, 'byUserKpi'])->name('daily-goals.byUserKpi');
    Route::resource('badges', BadgeController::class);
    Route::resource('progress', ProgressController::class);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/progress/submit', [ProgressController::class, 'store']);
    // Accept both POST and PUT for update (frontend may send PUT)
    Route::match(['post','put'], '/progress/update/{id}', [ProgressController::class, 'update']);
    Route::post('customers/{customer}/skip-kpi', [CustomerController::class, 'skipKpi']);
    Route::get('users/{user}/stats', [UserController::class, 'getStats']);
    Route::get('sales/pipelines', [UserController::class, 'getSalesWithPipelines']);
    Route::patch('/user/update-settings', [UserController::class, 'updateSettings']);

    // Reports
    Route::get('/reports/progress', [ReportController::class, 'progressReport']);
    Route::delete('/progress/reset-prospect/{id}', [ProgressController::class, 'resetProspect']);
});
