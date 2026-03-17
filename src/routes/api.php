<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterController;

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

Route::middleware(['auth:api', 'role:admin,purchase'])
    ->prefix('master')
    ->group(function () {
 
        Route::crud('platecolor', MasterController::class, 'platecolor'); 
        Route::crud('menu', MasterController::class, 'menu');
        Route::crud('outlet', MasterController::class, 'outlet');

});
