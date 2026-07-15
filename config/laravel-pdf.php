<?php

declare(strict_types=1);

use Spatie\LaravelPdf\Caching\DefaultPdfCache;
use Spatie\LaravelPdf\Encryption\DefaultPdfEncrypter;
use Spatie\LaravelPdf\Jobs\GeneratePdfJob;

/*
|--------------------------------------------------------------------------
| PDF rendering — invoices, receipts and report exports
|--------------------------------------------------------------------------
|
| Every council document (invoice, receipt, arrears/income report) is rendered
| from a Blade view by a real Chrome, because the register contains **Thaana
| (Dhivehi) tenant names** which need proper complex-script shaping. Do not
| switch to the `dompdf` driver without checking that: dompdf has no RTL/Thaana
| shaping and will mangle those names, and it does not support the flexbox
| letterhead in `resources/views/pdf/*`.
|
| Driver choice is environment-driven so the same code runs everywhere:
|
|   local / self-hosted  → `browsershot` (Node + puppeteer + local Chrome)
|   Laravel Cloud        → see docs/DEPLOYMENT.md. Browsershot needs BOTH the
|                          `puppeteer` node module and a Chrome binary present
|                          at runtime; when they are missing you get
|                          "Cannot find module 'puppeteer'". `chrome` (no Node
|                          at runtime) and `gotenberg`/`cloudflare` (no
|                          binaries at all) are the supported fallbacks.
|
*/

return [
    /*
     * Supported: "browsershot", "chrome", "gotenberg", "cloudflare", "dompdf".
     * See the Thaana warning above before choosing "dompdf".
     */
    'driver' => env('LARAVEL_PDF_DRIVER', 'browsershot'),

    /*
     * Browsershot — shells out to Node, which requires the `puppeteer` module
     * AND a Chrome binary to be present at runtime.
     */
    'browsershot' => [
        'node_binary' => env('LARAVEL_PDF_NODE_BINARY'),
        'npm_binary' => env('LARAVEL_PDF_NPM_BINARY'),
        'include_path' => env('LARAVEL_PDF_INCLUDE_PATH'),

        /*
         * Absolute path to the Chrome/chrome-headless-shell executable. Leave
         * null to let puppeteer resolve it (it reads PUPPETEER_CACHE_DIR).
         */
        'chrome_path' => env('LARAVEL_PDF_CHROME_PATH'),

        /*
         * Where the `puppeteer` module lives. Node resolves it by walking up
         * from vendor/spatie/browsershot/bin/, so this only needs setting when
         * node_modules is not at the project root (e.g. a hosting platform
         * that relocates or prunes it).
         */
        'node_modules_path' => env('LARAVEL_PDF_NODE_MODULES_PATH'),

        'bin_path' => env('LARAVEL_PDF_BIN_PATH'),
        'temp_path' => env('LARAVEL_PDF_TEMP_PATH'),

        /*
         * The letterhead embeds the council lockup as a base64 PNG, so the
         * payload is well past a safe command-line length — keep this on.
         */
        'write_options_to_file' => env('LARAVEL_PDF_WRITE_OPTIONS_TO_FILE', true),

        /*
         * Required wherever Chrome runs inside a container (Laravel Cloud,
         * Docker). Harmless to leave off locally on macOS.
         */
        'no_sandbox' => env('LARAVEL_PDF_NO_SANDBOX', false),
    ],

    /*
     * Chrome (chrome-php/chrome) — talks to a Chrome binary directly over the
     * DevTools protocol, so it needs **no Node and no puppeteer at runtime**.
     * The lightest fix for Laravel Cloud: install the binary in a build
     * command, then point LARAVEL_PDF_CHROME_BINARY at it.
     *
     * Requires: composer require chrome-php/chrome
     */
    'chrome' => [
        'chrome_binary' => env('LARAVEL_PDF_CHROME_BINARY'),
        'no_sandbox' => env('LARAVEL_PDF_CHROME_NO_SANDBOX', false),
        'startup_timeout' => env('LARAVEL_PDF_CHROME_STARTUP_TIMEOUT', 30),
        'timeout' => env('LARAVEL_PDF_CHROME_TIMEOUT', 30000),
        'operation_timeout' => env('LARAVEL_PDF_CHROME_OPERATION_TIMEOUT', 5000),
        'user_data_dir' => env('LARAVEL_PDF_CHROME_USER_DATA_DIR'),
        'custom_flags' => [],
        'env_variables' => [],
    ],

    /*
     * Gotenberg — a Chrome-backed rendering service. Renders identically to
     * the local Chrome (Thaana included) and needs nothing installed on the
     * app container. Self-hostable, so tenant data can stay under council
     * control; note that whichever host you point this at receives the
     * document HTML (tenant names, national IDs).
     */
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://localhost:3000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

    /*
     * Cloudflare Browser Rendering — hosted Chrome, no binaries required.
     * Same data-residency caveat as Gotenberg: the document HTML is sent to
     * Cloudflare to be rendered.
     */
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    ],

    /*
     * DOMPDF — pure PHP. Present only as a last resort; it cannot shape Thaana
     * and does not support the flexbox letterhead. See the warning above.
     */
    'dompdf' => [
        'is_remote_enabled' => env('LARAVEL_PDF_DOMPDF_REMOTE_ENABLED', false),
        'chroot' => env('LARAVEL_PDF_DOMPDF_CHROOT'),
    ],

    'cache' => [
        'class' => DefaultPdfCache::class,
        'automatic' => env('LARAVEL_PDF_CACHE_AUTOMATIC', false),
        'store' => env('LARAVEL_PDF_CACHE_STORE'),
        'prefix' => 'laravel-pdf',
        'ttl' => env('LARAVEL_PDF_CACHE_TTL', 60 * 60 * 24),
    ],

    'job' => GeneratePdfJob::class,

    'encrypter' => DefaultPdfEncrypter::class,
];
