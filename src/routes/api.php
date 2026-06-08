<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\WasteController;
use App\Http\Controllers\ClosingReportController;
use App\Http\Controllers\ReportsController;

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


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login-pin', [AuthController::class, 'loginByPin']);
Route::middleware('auth:api')->get('/auth/me', [AuthController::class, 'me']);
Route::middleware('auth:api')->post('/logout', [AuthController::class, 'logout']);


// ======================================================
// MASTER ROUTES
// ======================================================
Route::prefix('master')
    ->middleware('auth:api')
    ->group(function () {

        Route::middleware('role:admin')->group(function () {

            Route::crud('platecolor', MasterController::class, 'platecolor');
            Route::crud('menu', MasterController::class, 'menu');
            Route::crud('outlet', MasterController::class, 'outlet');
            Route::crud('waste-reason', MasterController::class, 'wastereason');

        });

        Route::middleware('role:admin,kitchen,service')->group(function () {

            Route::get('/platecolor', [MasterController::class, 'platecolorindex']);
            Route::get('/menu', [MasterController::class, 'menuindex']);
            Route::get('/outlet', [MasterController::class, 'outletindex']);
            Route::get('/waste-reason', [MasterController::class, 'wastereasonindex']);

        });

    });

// ======================================================
// PRODUCTION ROUTES
// ======================================================

Route::prefix('production')->group(function () {

    Route::get('/stats', [ProductionController::class, 'stats']);
    Route::get('/plan', [ProductionController::class, 'plan']);
    Route::post('/plan', [ProductionController::class, 'savePlan']);

    Route::get('/conveyor', [ProductionController::class, 'conveyor']);
    Route::post('/produce', [ProductionController::class, 'produce']);
    Route::post('/remove-expired', [ProductionController::class, 'removeExpired']);
    Route::post('/mark-sold', [ProductionController::class, 'markSold']);
    Route::post('/mark-waste', [ProductionController::class, 'markWaste']);

    
    Route::get('/expired', [ProductionController::class, 'expired']);
    
    Route::put('/expired/{id}', [ProductionController::class, 'updateExpired']);

    Route::post('/waste', [ProductionController::class, 'wasteStore']);
    Route::get('/waste', [ProductionController::class, 'wasteIndex']);
    Route::get('/items', [ProductionController::class, 'productionList']);
});

// ======================================================
// REPORT ROUTES
// ======================================================

Route::prefix('reports')->group(function () {
    
    Route::get('/pos-data', [POSController::class, 'getposData']);
    Route::get('/production-menu-detail', [ProductionController::class, 'productionMenuDetail']);
    Route::get('/daily-summary', [ReportsController::class, 'dailySummary']);
});

Route::prefix('sales')->group(function () {
    Route::get('/', [SalesController::class, 'drafts']);
    Route::post('/', [SalesController::class, 'store']);
    Route::get('/{id}', [SalesController::class, 'show']);
    Route::get('/by-date', [SalesController::class, 'byDate']);
});

Route::prefix('closing-reports')->group(function () {
    Route::get('/', [ClosingReportController::class, 'index']);
    Route::get('/data', [ClosingReportController::class, 'data']); 
    Route::post('/submit', [ClosingReportController::class, 'submit']);
    Route::post('/upload-photos', [ClosingReportController::class, 'uploadWastePhotos']);
    Route::get('/{id}', [ClosingReportController::class, 'show']); 
    Route::delete('/{id}', [ClosingReportController::class, 'destroy']);
});

Route::prefix('waste')->group(function () {

    Route::get('/', [WasteController::class, 'index']);
    Route::get('/summary', [WasteController::class, 'summary']);
    Route::get('/{waste}', [WasteController::class, 'show']);
});
