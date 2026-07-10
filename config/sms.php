<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SMS driver
    |--------------------------------------------------------------------------
    |
    | Supported: "log" (default — messages go to the application log),
    | "null" (discard), and "msgowl" (the MsgOwl gateway,
    | https://www.msgowl.com/docs). All drivers sit behind the same
    | App\Services\Sms\SmsSender interface, so switching never touches
    | business logic (FR-NOT-10).
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Gateway configuration (INT-SMS-01)
    |--------------------------------------------------------------------------
    |
    | Credentials come from the environment so secrets never live in source
    | control (NFR-SEC-02). sender_id must be a header approved by MsgOwl for
    | the council's account.
    |
    */

    'sender_id' => env('SMS_SENDER_ID', 'COUNCIL'),

    'gateway' => [
        'endpoint' => env('SMS_GATEWAY_ENDPOINT', 'https://rest.msgowl.com'),
        'api_key' => env('SMS_GATEWAY_KEY'),
    ],

];
