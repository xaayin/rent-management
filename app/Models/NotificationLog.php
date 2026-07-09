<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one attempted notification send: recipient, channel, content,
 * timestamp and provider outcome (FR-NOT-05, INT-SMS-03).
 */
class NotificationLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'kind',
        'channel',
        'recipient',
        'content',
        'status',
        'provider_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'status' => NotificationStatus::class,
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
