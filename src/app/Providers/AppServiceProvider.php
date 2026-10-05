<?php

namespace App\Providers;

use App\Services\Messaging\MessagePublisher;
use App\Services\Messaging\RabbitConnectionFactory;
use App\Services\Messaging\RabbitMessagePublisher;
use Illuminate\Support\ServiceProvider;

use Illuminate\Database\Schema\Blueprint;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MessagePublisher::class, function ($app) {
            return new RabbitMessagePublisher(
                $app->make(RabbitConnectionFactory::class),
                (int) config('rabbitmq.publish_confirm_timeout', 5),
            );
        });
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
