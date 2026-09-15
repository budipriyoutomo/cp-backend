<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php')); 
        });


        // `{id}` dibatasi bentuk uuid. Semua primary key domain bertipe uuid di
        // PostgreSQL, dan `find('abc')` di kolom uuid bukan menghasilkan "tidak
        // ketemu" melainkan 22P02 — 500 dengan SQL bocor ke klien. Dengan
        // batasan ini segmen yang bentuknya salah tidak pernah cocok dengan
        // route-nya, jadi jawabannya 404 seperti seharusnya.
        Route::macro('crud', function ($uri, $controller, $name) {
            Route::get("$uri",        [$controller, "{$name}Index"]);
            Route::post("$uri",       [$controller, "{$name}Store"]);
            Route::get("$uri/{id}",   [$controller, "{$name}Show"])->whereUuid('id');
            Route::put("$uri/{id}",   [$controller, "{$name}Update"])->whereUuid('id');
            Route::delete("$uri/{id}",[$controller, "{$name}Destroy"])->whereUuid('id');
        });
    }
}
