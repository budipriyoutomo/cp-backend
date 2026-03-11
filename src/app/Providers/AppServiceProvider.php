<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Database\Schema\Blueprint;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Blueprint::macro('fullstamps', function () {
            $this->char('created_by', 36)->nullable();
            $this->char('updated_by', 36)->nullable();
            $this->char('deleted_by', 36)->nullable();
        });
    }
}
