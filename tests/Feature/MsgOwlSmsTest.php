<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Notifications\PaymentReminder;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Sms\MsgOwlSmsSender;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function msgOwl(string $key = 'test-key'): MsgOwlSmsSender
{
    return new MsgOwlSmsSender(
        endpoint: 'https://rest.msgowl.com',
        apiKey: $key,
        senderId: 'COUNCIL',
    );
}

it('posts the message to MsgOwl with the documented payload and auth header', function () {
    Http::fake(['rest.msgowl.com/*' => Http::response(['message' => 'Message has been sent successfully.', 'id' => 8848], 201)]);

    $result = msgOwl()->send('+960 777-1234', 'Rent of MVR 500.00 is due today.');

    expect($result->success)->toBeTrue()
        ->and($result->providerId)->toBe('8848');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rest.msgowl.com/messages'
        && $request->method() === 'POST'
        && $request->header('Authorization')[0] === 'AccessKey test-key'
        && $request['recipients'] === '9607771234'          // digits only
        && $request['sender_id'] === 'COUNCIL'
        && $request['body'] === 'Rent of MVR 500.00 is due today.');
});

it('reports a gateway rejection as a failure with the provider message', function () {
    Http::fake(['rest.msgowl.com/*' => Http::response(['message' => 'Request not allowed (incorrect access_key)'], 401)]);

    $result = msgOwl()->send('+9607771234', 'Hello');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('Request not allowed')
        ->and($result->error)->toContain('401');
});

it('retries after a 429 rate limit and succeeds (INT-SMS-04)', function () {
    Http::fakeSequence('rest.msgowl.com/*')
        ->push(['message' => 'Too many requests'], 429)
        ->push(['message' => 'Message has been sent successfully.', 'id' => 9001], 201);

    $result = msgOwl()->send('+9607771234', 'Hello');

    expect($result->success)->toBeTrue()
        ->and($result->providerId)->toBe('9001');

    Http::assertSentCount(2);
});

it('fails fast without calling the gateway when the key is missing', function () {
    Http::fake();

    $result = msgOwl(key: '')->send('+9607771234', 'Hello');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('SMS_GATEWAY_KEY');

    Http::assertNothingSent();
});

it('delivers a reminder end-to-end through the msgowl driver and logs the provider id', function () {
    config()->set('sms.driver', 'msgowl');
    config()->set('sms.gateway.api_key', 'live-key');

    Http::fake(['rest.msgowl.com/*' => Http::response(['message' => 'ok', 'id' => 5150], 201)]);

    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01', 'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
    ]);
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    $lease->tenant->notify(new PaymentReminder($invoice, ReminderKind::Manual, 'Please settle invoice '.$invoice->number.'.'));

    $log = NotificationLog::sole();

    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->provider_id)->toBe('5150')
        ->and($log->recipient)->toBe($lease->tenant->mobile);

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'AccessKey live-key');
});
