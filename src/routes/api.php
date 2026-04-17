<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\POSController;

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

// ======================================================
// MASTER ROUTES
// ======================================================

Route::middleware(['auth:api', 'role:admin'])
    ->prefix('master')
    ->group(function () {
 
        Route::crud('platecolor', MasterController::class, 'platecolor'); 
        Route::crud('menu', MasterController::class, 'menu');
        Route::crud('outlet', MasterController::class, 'outlet');
        Route::crud('waste-reason', MasterController::class, 'wastereason');

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
});

// ======================================================
// REPORT ROUTES
// ======================================================

Route::prefix('reports')->group(function () {

    Route::get('/pos-data', [POSController::class, 'getposData']);
     
});