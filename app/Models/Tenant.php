<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\TenantType;
use App\Support\Money;
use Database\Factories\TenantFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/*
 * Tenant implements Authenticatable for the PORTAL guard only (T2): sign-in is
 * an SMS one-time code — there is no password column and never will be, and
 * remember-me is unsupported (no remember_token either; the session guard
 * skips token cycling when it is empty).
 */
class Tenant extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    /** @use HasFactory<TenantFactory> */
    use HasFactory, LogsActivity, Notifiable;

    protected $fillable = [
        'type',
        'name',
        'national_id',
        'company_reg_no',
        'contact_person',
        'mobile',
        'email',
        'sms_opt_out',
        'postal_address',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'sms_opt_out' => 'boolean',
        ];
    }

    /**
     * Where the custom SMS channel delivers for this tenant (FR-TEN-02/03).
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->mobile;
    }

    /**
     * Whether reminders may be sent at all (§5.5.26): a mobile number exists
     * and the tenant has not opted out.
     */
    public function canReceiveSms(): bool
    {
        return ! $this->sms_opt_out && filled($this->mobile);
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** @return HasMany<TransferClaim, $this> */
    public function transferClaims(): HasMany
    {
        return $this->hasMany(TransferClaim::class);
    }

    public function isIndividual(): bool
    {
        return $this->type === TenantType::Individual;
    }

    public function isOrganisation(): bool
    {
        return $this->type === TenantType::Organisation;
    }

    /**
     * The identifier shown to staff: national ID for individuals, company
     * registration number for organisations (FR-TEN-01).
     */
    public function registryNumber(): ?string
    {
        return $this->isIndividual() ? $this->national_id : $this->company_reg_no;
    }

    /**
     * Everything this tenant currently owes, across all leases — the figure a
     * statement or confirmation SMS quotes (FR-TEN-05).
     */
    public function outstandingBalance(): Money
    {
        $laari = $this->unsettledInvoices()
            ->get()
            ->sum(fn ($invoice): int => max($invoice->outstandingTotalLaari(), 0));

        return Money::fromLaari((int) $laari);
    }

    public function outstandingInvoiceCount(): int
    {
        return $this->unsettledInvoices()->count();
    }

    /**
     * @return Builder<Invoice>
     */
    private function unsettledInvoices()
    {
        return Invoice::query()
            ->whereHas('lease', fn ($query) => $query->where('tenant_id', $this->id))
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'type', 'name', 'national_id', 'company_reg_no',
                'contact_person', 'mobile', 'email', 'sms_opt_out', 'postal_address',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant');
    }
}
