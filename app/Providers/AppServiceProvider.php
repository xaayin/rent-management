<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NullSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The SMS provider seam (FR-NOT-10): swap the driver in config/sms.php
        // — a real gateway adapter binds here when the council supplies one.
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            return match (config('sms.driver')) {
                'null' => new NullSmsSender,
                default => new LogSmsSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
