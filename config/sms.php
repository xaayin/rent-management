<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SMS driver
    |--------------------------------------------------------------------------
    |
    | The council has not yet supplied an SMS gateway (PRD §8.1), so the
    | default driver writes messages to the application log. Supported:
    | "log", "null". A real gateway driver plugs in behind the same
    | App\Services\Sms\SmsSender interface without touching business logic
    | (FR-NOT-10).
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Gateway configuration (INT-SMS-01)
    |--------------------------------------------------------------------------
    |
    | Credential slots for the real provider, supplied via environment so
    | secrets never live in source control (NFR-SEC-02).
    |
    */

    'sender_id' => env('SMS_SENDER_ID', 'COUNCIL'),

    'gateway' => [
        'endpoint' => env('SMS_GATEWAY_ENDPOINT'),
        'api_key' => env('SMS_GATEWAY_KEY'),
    ],

];
