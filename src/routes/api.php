<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductionImportController;
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

        Route::middleware(['role:admin', 'module:admin'])->group(function () {

            Route::crud('platecolor', MasterController::class, 'platecolor');
            Route::crud('menu', MasterController::class, 'menu');
            Route::crud('outlet', MasterController::class, 'outlet');
            Route::crud('waste-reason', MasterController::class, 'wastereason');
            Route::crud('brand', MasterController::class, 'brand');

        });

        // Baca master TIDAK dipagari role maupun modul, dan itu disengaja.
        //
        // Plate color, menu, outlet, waste reason, dan brand adalah data acuan
        // yang dibutuhkan setiap modul: `OutletProvider` memanggil
        // `/master/outlet` di layout SEMUA modul, dan layar production maupun
        // kitchen menyaring menu lewat plate color. Gerbang lamanya
        // `role:admin,kitchen` membuat role `manager`, `operation`, dan
        // `production` dijawab 403 di sini — selector outletnya kosong, dan
        // seluruh modul mereka berhenti mengambil data tanpa pesan apa pun.
        //
        // Yang sensitif adalah menulisnya, dan itu dijaga grup di atas.
        // Batas datanya tetap ada: `outlet.access` di grup induk.
        Route::get('/platecolor', [MasterController::class, 'platecolorindex']);
        Route::get('/menu', [MasterController::class, 'menuindex']);
        Route::get('/outlet', [MasterController::class, 'outletindex']);
        Route::get('/waste-reason', [MasterController::class, 'wastereasonindex']);
        Route::get('/brand', [MasterController::class, 'brandindex']);
        Route::get('/brand/{id}', [MasterController::class, 'brandshow'])->whereUuid('id');

    });

// ======================================================
// USER MANAGEMENT ROUTES (admin only)
// ======================================================
Route::prefix('users')
    ->middleware(['auth:api', 'role:admin', 'module:admin'])
    ->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [UserController::class, 'destroy'])->whereNumber('id');
    });

// ======================================================
// PRODUCTION ROUTES
// ======================================================

// Modul yang memakai grup ini: `kitchen`/`service` (dashboard, produce,
// conveyor, expired), `production` (planning, waste), dan `report`
// (production-item-list membaca `/production/items`).
Route::prefix('production')
    ->middleware(['auth:api', 'outlet.access', 'module:kitchen,service,production,report'])
    ->group(function () {

    Route::get('/stats', [ProductionController::class, 'stats']);
    Route::get('/plan', [ProductionController::class, 'plan']);
    Route::post('/plan', [ProductionController::class, 'savePlan']);

    // Belt dibaca per batch produksi, bukan per piring. Bentuk per-piring
    // (`/conveyor`, `/expired`, `PUT /expired/{id}`) sudah dibuang: satu piring
    // adalah satu baris, jadi belt seribu piring berarti seribu objek tiap 30
    // detik per tablet, dan menutup satu batch berarti puluhan request.
    Route::get('/conveyor-grouped', [ProductionController::class, 'conveyorGrouped']);

    Route::post('/produce', [ProductionController::class, 'produce']);
    Route::post('/mark-sold', [ProductionController::class, 'markSold']);
    Route::post('/mark-waste', [ProductionController::class, 'markWaste']);
    Route::post('/close-day', [ProductionController::class, 'closeDay']);

    Route::get('/expired-grouped', [ProductionController::class, 'expiredGrouped']);
    Route::post('/expired/bulk', [ProductionController::class, 'updateExpiredBulk']);

    Route::post('/waste', [ProductionController::class, 'wasteStore']);
    Route::get('/waste', [ProductionController::class, 'wasteIndex']);
    Route::get('/items', [ProductionController::class, 'productionList']);

});

// Backfill produksi hari lalu — layar `/admin/production-import`.
//
// Blok terpisah, bukan nested di grup production, karena modulnya berbeda:
// grup di atas milik modul dapur/produksi/report, sedangkan layar ini milik
// modul `admin`. Kalau dinested, kedua gerbang modul harus lolos sekaligus —
// dan tidak ada user yang bisa memenuhi keduanya.
//
// `role:admin` bukan sekadar kehati-hatian: ini satu-satunya jalur yang boleh
// menulis `final_status` di luar hari produksi. Preview tidak menulis apa pun,
// tapi ia membaca master brand penuh dan menghitung tabrakan — dijaga sama
// supaya tidak jadi celah baca.
Route::prefix('production')
    ->middleware(['auth:api', 'outlet.access', 'role:admin', 'module:admin'])
    ->group(function () {
        Route::get('/import-backdate/template', [ProductionImportController::class, 'template']);
        Route::post('/import-backdate/preview', [ProductionImportController::class, 'preview']);
        Route::post('/import-backdate', [ProductionImportController::class, 'store']);
    });

// ======================================================
// REPORT ROUTES
// ======================================================

// `operation` ikut di sini: layar sales-input membaca `/reports/pos-data`
// untuk merekonsiliasi angka POS.
Route::prefix('reports')
    ->middleware(['auth:api', 'outlet.access', 'module:operation,report'])
    ->group(function () {

    Route::get('/pos-data', [POSController::class, 'getposData']);
    Route::get('/production-menu-detail', [ProductionController::class, 'productionMenuDetail']);
    Route::get('/daily-summary', [ReportsController::class, 'dailySummary']);
    Route::get('/waste-analysis', [ReportsController::class, 'wasteAnalysis']);
});

Route::prefix('sales')
    ->middleware(['auth:api', 'outlet.access', 'module:operation'])
    ->group(function () {
    Route::get('/', [SalesController::class, 'drafts']);
    Route::post('/', [SalesController::class, 'store']);
    // NOTE: static routes must be declared before the /{id} wildcard, otherwise
    // /sales/by-date is captured by show() with id = "by-date".
    Route::get('/by-date', [SalesController::class, 'byDate']);
    Route::get('/{id}', [SalesController::class, 'show'])->whereUuid('id');
});

// `operation` menyusun dan menandatangani; `report` membacanya di
// `/report/closing-reports`.
Route::prefix('closing-reports')
    ->middleware(['auth:api', 'outlet.access', 'module:operation,report'])
    ->group(function () {
    Route::get('/', [ClosingReportController::class, 'index']);
    Route::get('/data', [ClosingReportController::class, 'data']);
    Route::post('/submit', [ClosingReportController::class, 'submit']);
    Route::post('/upload-photos', [ClosingReportController::class, 'uploadWastePhotos']);
    Route::get('/{id}', [ClosingReportController::class, 'show'])->whereUuid('id');

    // Deleting a signed-off report is the one destructive action in this group.
    Route::delete('/{id}', [ClosingReportController::class, 'destroy'])->whereUuid('id')
        ->middleware('role:admin,manager');
});

// Hanya layar `/production/waste` yang memakai grup ini. Analisis waste di
// modul report jalan lewat `/reports/waste-analysis`, bukan dari sini.
Route::prefix('waste')
    ->middleware(['auth:api', 'outlet.access', 'module:production'])
    ->group(function () {

    Route::get('/', [WasteController::class, 'index']);
    Route::get('/summary', [WasteController::class, 'summary']);
    Route::get('/{waste}', [WasteController::class, 'show'])->whereUuid('waste');
});
