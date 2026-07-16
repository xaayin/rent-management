<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Models\Tenant;
use App\Services\Portal\PortalOtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The tenant portal's front door (T2): mobile number → SMS one-time code →
 * signed in. No passwords anywhere. When one mobile holds several tenant
 * records (a legacy-import reality), a chooser follows the code.
 */
#[Layout('components.layouts.portal')]
class Login extends Component
{
    /** mobile | code | choose */
    public string $step = 'mobile';

    public string $mobile = '';

    public string $code = '';

    /**
     * Set only by a successful OTP check in THIS session — the chooser trusts
     * nothing else.
     */
    public function mount(): void
    {
        if (Auth::guard('tenant')->check()) {
            $this->redirect(route('portal.home'));
        }
    }

    public function requestCode(PortalOtpService $otp): void
    {
        $this->validate(
            ['mobile' => ['required', 'string', 'min:7', 'max:20']],
            ['mobile.min' => 'Enter the mobile number the council has on record.'],
        );

        if (! $otp->request($this->mobile)) {
            $this->addError('mobile', 'Too many codes requested — please wait a while and try again.');

            return;
        }

        $this->code = '';
        $this->step = 'code';
    }

    public function verify(PortalOtpService $otp): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);

        $tenants = $otp->verify($this->mobile, $this->code);

        if ($tenants === null) {
            $this->addError('code', 'That code is not right, or has expired. Request a new one if needed.');

            return;
        }

        if ($tenants->count() === 1) {
            $this->signIn($tenants->first());

            return;
        }

        // Several tenancies share this mobile: remember the verified number
        // server-side and let them pick which record to open.
        session()->put('portal_verified_mobile', PortalOtpService::normalise($this->mobile));
        $this->step = 'choose';
    }

    public function choose(int $tenantId, PortalOtpService $otp): void
    {
        $verified = (string) session()->get('portal_verified_mobile');
        abort_if($verified === '', 403);

        $tenant = $otp->tenantsFor($verified)->firstWhere('id', $tenantId);

        // Only a record actually behind the verified number may be chosen.
        abort_if($tenant === null, 403);

        session()->forget('portal_verified_mobile');
        $this->signIn($tenant);
    }

    public function render(PortalOtpService $otp): View
    {
        $choices = $this->step === 'choose'
            ? $otp->tenantsFor((string) session()->get('portal_verified_mobile'))
            : collect();

        return view('livewire.portal.login', ['choices' => $choices]);
    }

    private function signIn(Tenant $tenant): void
    {
        Auth::guard('tenant')->login($tenant);
        session()->regenerate();

        $this->redirect(route('portal.home'));
    }
}
