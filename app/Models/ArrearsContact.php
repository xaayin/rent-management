<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactChannel;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One logged attempt to collect arrears from a tenant, and the promise (if any)
 * that came out of it. Written only through ArrearsFollowUpService.
 */
class ArrearsContact extends Model
{
    use LogsActivity;

    protected $fillable = [
        'tenant_id',
        'contacted_on',
        'channel',
        'note',
        'promised_on',
        'promised_amount_laari',
        'outstanding_at_contact_laari',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'contacted_on' => 'date',
            'promised_on' => 'date',
            'channel' => ContactChannel::class,
            'promised_amount_laari' => 'integer',
            'outstanding_at_contact_laari' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isPromise(): bool
    {
        return $this->promised_on !== null;
    }

    /**
     * What the tenant undertook to pay: the stated amount, or — when none was
     * stated — everything they owed at the time.
     */
    public function promisedTargetLaari(): int
    {
        return $this->promised_amount_laari ?? $this->outstanding_at_contact_laari;
    }

    public function promisedTarget(): Money
    {
        return Money::fromLaari($this->promisedTargetLaari());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenant_id', 'contacted_on', 'channel', 'note', 'promised_on', 'promised_amount_laari'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('arrears_contact');
    }
}
