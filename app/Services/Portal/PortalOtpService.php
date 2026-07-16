<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * SMS one-time codes for the tenant portal (T2).
 *
 * Security posture:
 *  - `request()` never reveals whether a mobile is registered — the portal
 *    shows the same "if this number is registered…" message either way, so the
 *    login page cannot be used to enumerate the council's tenants.
 *  - Codes are stored hashed, live 5 minutes, die after 5 wrong attempts, and
 *    at most 3 codes/hour may be requested per mobile.
 *  - OTP delivery IGNORES sms_opt_out: the tenant is asking us to text them
 *    right now — that is transactional, not marketing. The audit log keeps the
 *    attempt but MASKS the code (notification_logs stores message content
 *    verbatim, and a live code must never sit in a database table).
 */
class PortalOtpService
{
    private const int CODE_TTL_MINUTES = 5;

    private const int MAX_ATTEMPTS = 5;

    private const int MAX_CODES_PER_HOUR = 3;

    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Issue and text a code for this mobile — silently doing nothing when no
     * tenant matches, so responses are identical for both cases.
     *
     * @return bool whether the caller should be shown the generic "sent"
     *              message (always true unless rate-limited)
     */
    public function request(string $mobile): bool
    {
        $normalised = self::normalise($mobile);
        $tenants = $this->tenantsFor($normalised);

        if ($tenants->isEmpty()) {
            return true;   // pretend: same response as a real send
        }

        // Testing bypass: the fixed code already works in verify(), so there is
        // nothing to generate or send. Anti-enumeration is preserved — an
        // unknown mobile still short-circuited above.
        if ($this->bypassCode() !== null) {
            return true;
        }

        $recentCodes = DB::table('portal_otp_codes')
            ->where('mobile', $normalised)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCodes >= self::MAX_CODES_PER_HOUR) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        DB::table('portal_otp_codes')->insert([
            'mobile' => $normalised,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->sms->send(
            (string) $tenants->first()->mobile,
            "Your Kanduhulhudhoo Council sign-in code is {$code}. It expires in ".self::CODE_TTL_MINUTES.' minutes.',
        );

        // Audit the attempt, never the code.
        NotificationLog::create([
            'tenant_id' => $tenants->first()->id,
            'kind' => ReminderKind::PortalOtp->value,
            'channel' => 'sms',
            'recipient' => (string) $tenants->first()->mobile,
            'content' => 'Portal sign-in code (masked).',
            'status' => ($result->success ? NotificationStatus::Sent : NotificationStatus::Failed)->value,
            'provider_id' => $result->providerId,
            'error' => $result->error,
        ]);

        return true;
    }

    /**
     * Check a code. On success the code is consumed and every tenant record
     * behind the mobile is returned (the legacy import keyed tenants by
     * name + mobile, so one number can hold several tenancies).
     *
     * @return Collection<int, Tenant>|null null when the code is wrong,
     *                                      expired, or burned out
     */
    public function verify(string $mobile, string $code): ?Collection
    {
        $normalised = self::normalise($mobile);

        // Testing bypass: the configured code signs in any tenant behind the
        // mobile, no stored row needed. Still returns null for an unknown
        // mobile (tenantsFor is empty), so it cannot be used to enumerate.
        $bypass = $this->bypassCode();

        if ($bypass !== null && hash_equals($bypass, $code)) {
            $tenants = $this->tenantsFor($normalised);

            if ($tenants->isNotEmpty()) {
                Log::warning('Tenant portal OTP bypass used — this must never happen in production.', [
                    'mobile' => $normalised,
                ]);
            }

            return $tenants->isEmpty() ? null : $tenants;
        }

        $row = DB::table('portal_otp_codes')
            ->where('mobile', $normalised)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        DB::table('portal_otp_codes')->where('id', $row->id)->increment('attempts');

        if (! Hash::check($code, $row->code_hash)) {
            return null;
        }

        DB::table('portal_otp_codes')->where('id', $row->id)->update(['consumed_at' => now()]);

        $tenants = $this->tenantsFor($normalised);

        return $tenants->isEmpty() ? null : $tenants;
    }

    /**
     * Every tenant whose stored mobile matches, comparing normalised digits —
     * the register holds numbers in mixed formats (+960 prefixes, spacing).
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsFor(string $normalisedMobile): Collection
    {
        if ($normalisedMobile === '') {
            return collect();
        }

        return Tenant::query()
            ->whereNotNull('mobile')
            ->get()
            ->filter(fn (Tenant $tenant) => self::normalise((string) $tenant->mobile) === $normalisedMobile)
            ->values();
    }

    /**
     * The testing bypass code, or null when the bypass is off.
     *
     * FOR TESTING/QA ONLY: this code signs in ANY tenant, so it is a master key.
     * Two guards, not one: it is refused outright in production regardless of
     * config, and it must be a well-formed 6-digit code. See config/portal.php.
     */
    private function bypassCode(): ?string
    {
        if (app()->isProduction()) {
            return null;   // never, no matter what env is set
        }

        $code = (string) config('portal.otp_bypass_code', '');

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : null;
    }

    /**
     * Digits only, without the +960 country prefix — the canonical form both
     * sides of a comparison are reduced to.
     */
    public static function normalise(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '960') && strlen($digits) > 7) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }
}
