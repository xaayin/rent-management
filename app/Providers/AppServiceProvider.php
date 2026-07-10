<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\MsgOwlSmsSender;
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
        // The SMS provider seam (FR-NOT-10): swap the driver in config/sms.php.
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            return match (config('sms.driver')) {
                'msgowl' => new MsgOwlSmsSender(
                    endpoint: (string) config('sms.gateway.endpoint'),
                    apiKey: (string) config('sms.gateway.api_key'),
                    senderId: (string) config('sms.sender_id'),
                ),
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
