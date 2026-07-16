<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant portal OTP testing bypass
    |--------------------------------------------------------------------------
    |
    | FOR TESTING/QA ONLY. When a 6-digit code is set here, the portal skips
    | sending an SMS and lets that ONE code sign in ANY tenant — so testers can
    | log in without a real phone.
    |
    | This is a master key to every tenant account, so it is DELIBERATELY
    | double-guarded: it is ignored outright when APP_ENV=production, no matter
    | what this value is (see PortalOtpService::bypassCode). Leave it blank to
    | disable. Never set it on a production or shared-staging deployment.
    |
    */
    'otp_bypass_code' => env('PORTAL_OTP_BYPASS_CODE'),

];
