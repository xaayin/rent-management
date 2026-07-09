<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantType;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Tenant extends Model
{
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
