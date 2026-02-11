<?php

use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyGoalController;
use App\Http\Controllers\Api\KPIController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SummaryController;
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

// Product list route - accessible to all authenticated users (for modal product selection)
Route::get('/products/list', [ProductController::class, 'list']);

// Product statistics route - accessible to all authenticated users (for dashboard display)
Route::get('/products/statistics', [ProductController::class, 'statistics']);

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('users', UserController::class);
    // Custom customer routes (must be before resource to avoid conflict)
    Route::get('customers/available-for-prospect', [CustomerController::class, 'getAvailableForProspect']);
    Route::get('customers/sales-history', [CustomerController::class, 'getSalesHistory']);
    Route::post('customers/{customer}/convert-to-prospect', [CustomerController::class, 'convertToProspect']);
    Route::resource('customers', CustomerController::class);
    Route::resource('kpis', KPIController::class);
    Route::resource('daily-goals', DailyGoalController::class);
    // additional helper route to fetch daily goals by user + kpi
    Route::get('daily-goals/user/{user}/kpi/{kpi}', [DailyGoalController::class, 'byUserKpi'])->name('daily-goals.byUserKpi');
    Route::resource('badges', BadgeController::class);
    // Custom progress routes (must be before resource to avoid conflict)
    Route::get('/progress/last-followup', [ProgressController::class, 'getLastFollowUp']);
    Route::resource('progress', ProgressController::class);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/progress/submit', [ProgressController::class, 'store']);
    // Accept both POST and PUT for update (frontend may send PUT)
    Route::match(['post','put'], '/progress/update/{id}', [ProgressController::class, 'update']);
    Route::post('/progress/revert/{progressId}', [ProgressController::class, 'revert']);
    
    // Summary routes
    Route::resource('summaries', SummaryController::class)->only(['store', 'show']);
    Route::get('/summaries/customer/{customerId}', [SummaryController::class, 'showByCustomer']);
    Route::post('/summaries/submit-and-advance', [SummaryController::class, 'submitAndAdvance']);
    
    Route::post('customers/{customer}/skip-kpi', [CustomerController::class, 'skipKpi']);
    Route::get('users/{user}/stats', [UserController::class, 'getStats']);
    Route::get('sales/pipelines', [UserController::class, 'getSalesWithPipelines']);
    Route::patch('/user/update-settings', [UserController::class, 'updateSettings']);

    // Reports
    Route::get('/reports/progress', [ReportController::class, 'progressReport']);
    Route::delete('/progress/reset-prospect/{id}', [ProgressController::class, 'resetProspect']);

// Product routes - only for administrators (full CRUD)
    Route::resource('products', ProductController::class);
    Route::patch('/products/{id}/toggle-active', [ProductController::class, 'toggleActive']);

    // Customer-Product relationship routes
    Route::get('customers/{customer}/products', [CustomerController::class, 'getProducts']);
    Route::post('customers/{customer}/products', [CustomerController::class, 'attachProduct']);
    Route::delete('customers/{customer}/products/{product}', [CustomerController::class, 'detachProduct']);

    // File access routes
});

Route::get('/progress/attachment/{progressId}', [ProgressController::class, 'getAttachment'])->name('progress.attachment');
