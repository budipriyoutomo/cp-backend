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
use App\Http\Controllers\UserController;

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


// TIDAK publik. `register` menerima `role` dan `module_app` dari payload, jadi
// selama route ini terbuka siapa pun bisa mendaftarkan dirinya sebagai admin —
// dan semua middleware `role:admin` di bawah menjadi tidak ada artinya.
// Pembuatan user sehari-hari lewat `/users` (UserController); route ini tinggal
// sebagai jalur register yang langsung mengembalikan token.
Route::post('/register', [AuthController::class, 'register'])
    ->middleware(['auth:api', 'role:admin']);
Route::post('/login', [AuthController::class, 'login']);
// Tighter than the global 60/min: a 6-digit PIN is guessable, so brute force is
// the realistic attack here. Not tighter than 20 though — the limit is keyed by
// IP and a whole outlet of tablets shares one, so a shift change with a few
// typos must not lock the kitchen out. 20/min still means ~18 days of sustained
// guessing to cover half of a 6-digit keyspace.
Route::post('/login-pin', [AuthController::class, 'loginByPin'])
    ->middleware('throttle:20,1');
Route::middleware('auth:api')->get('/auth/me', [AuthController::class, 'me']);
Route::middleware('auth:api')->post('/logout', [AuthController::class, 'logout']);
// Tanpa `auth:api` — dan itu disengaja. Guard memvalidasi klaim `exp`, jadi
// token yang sudah mati ditolak 401 sebelum controller jalan; endpoint refresh
// pun cuma melayani token yang belum perlu di-refresh, yaitu kebalikan dari
// gunanya. Otorisasinya tetap ada, hanya pindah ke dalam controller:
// `parseToken()->refresh()` menolak token rusak, yang sudah di-blacklist, dan
// yang lewat `refresh_ttl`. Throttle 20/menit menjaganya dari percobaan buta —
// satu outlet berbagi satu IP, sama seperti alasan di `/login-pin`.
Route::post('/auth/refresh', [AuthController::class, 'refresh'])
    ->middleware('throttle:20,1');


// ======================================================
// MASTER ROUTES
// ======================================================
Route::prefix('master')
    ->middleware(['auth:api', 'outlet.access'])
    ->group(function () {

        Route::middleware('role:admin')->group(function () {

            Route::crud('platecolor', MasterController::class, 'platecolor');
            Route::crud('menu', MasterController::class, 'menu');
            Route::crud('outlet', MasterController::class, 'outlet');
            Route::crud('waste-reason', MasterController::class, 'wastereason');
            Route::crud('brand', MasterController::class, 'brand');

        });

        Route::middleware('role:admin,kitchen')->group(function () {

            Route::get('/platecolor', [MasterController::class, 'platecolorindex']);
            Route::get('/menu', [MasterController::class, 'menuindex']);
            Route::get('/outlet', [MasterController::class, 'outletindex']);
            Route::get('/waste-reason', [MasterController::class, 'wastereasonindex']);
            // Dapur perlu baca brand untuk menyaring menu (Fase 4), jadi read
            // dibuka ke role yang sama dengan master lain sejak sekarang.
            // Role `service` dulu ikut di sini; sekarang lebur ke `kitchen`.
            Route::get('/brand', [MasterController::class, 'brandindex']);
            Route::get('/brand/{id}', [MasterController::class, 'brandshow']);

        });

    });

// ======================================================
// USER MANAGEMENT ROUTES (admin only)
// ======================================================
Route::prefix('users')
    ->middleware(['auth:api', 'role:admin'])
    ->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

// ======================================================
// PRODUCTION ROUTES
// ======================================================

Route::prefix('production')->middleware(['auth:api', 'outlet.access'])->group(function () {

    Route::get('/stats', [ProductionController::class, 'stats']);
    Route::get('/plan', [ProductionController::class, 'plan']);
    Route::post('/plan', [ProductionController::class, 'savePlan']);

    Route::get('/conveyor', [ProductionController::class, 'conveyor']);
    Route::post('/produce', [ProductionController::class, 'produce']);
    Route::post('/mark-sold', [ProductionController::class, 'markSold']);
    Route::post('/mark-waste', [ProductionController::class, 'markWaste']);
    Route::post('/close-day', [ProductionController::class, 'closeDay']);

    
    Route::get('/expired', [ProductionController::class, 'expired']);
    
    Route::put('/expired/{id}', [ProductionController::class, 'updateExpired']);

    Route::post('/waste', [ProductionController::class, 'wasteStore']);
    Route::get('/waste', [ProductionController::class, 'wasteIndex']);
    Route::get('/items', [ProductionController::class, 'productionList']);
});

// ======================================================
// REPORT ROUTES
// ======================================================

Route::prefix('reports')->middleware(['auth:api', 'outlet.access'])->group(function () {

    Route::get('/pos-data', [POSController::class, 'getposData']);
    Route::get('/production-menu-detail', [ProductionController::class, 'productionMenuDetail']);
    Route::get('/daily-summary', [ReportsController::class, 'dailySummary']);
    Route::get('/waste-analysis', [ReportsController::class, 'wasteAnalysis']);
});

Route::prefix('sales')->middleware(['auth:api', 'outlet.access'])->group(function () {
    Route::get('/', [SalesController::class, 'drafts']);
    Route::post('/', [SalesController::class, 'store']);
    // NOTE: static routes must be declared before the /{id} wildcard, otherwise
    // /sales/by-date is captured by show() with id = "by-date".
    Route::get('/by-date', [SalesController::class, 'byDate']);
    Route::get('/{id}', [SalesController::class, 'show']);
});

Route::prefix('closing-reports')->middleware(['auth:api', 'outlet.access'])->group(function () {
    Route::get('/', [ClosingReportController::class, 'index']);
    Route::get('/data', [ClosingReportController::class, 'data']);
    Route::post('/submit', [ClosingReportController::class, 'submit']);
    Route::post('/upload-photos', [ClosingReportController::class, 'uploadWastePhotos']);
    Route::get('/{id}', [ClosingReportController::class, 'show']);

    // Deleting a signed-off report is the one destructive action in this group.
    Route::delete('/{id}', [ClosingReportController::class, 'destroy'])
        ->middleware('role:admin,manager');
});

Route::prefix('waste')->middleware(['auth:api', 'outlet.access'])->group(function () {

    Route::get('/', [WasteController::class, 'index']);
    Route::get('/summary', [WasteController::class, 'summary']);
    Route::get('/{waste}', [WasteController::class, 'show']);
});
