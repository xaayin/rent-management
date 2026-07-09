<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceLineType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single itemised charge on an invoice — rent, CSR, or (later) a fine — with
 * a `meta` breakdown so every figure can be independently verified (FR-INV-02).
 */
class InvoiceLineItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'type',
        'description',
        'amount_laari',
        'meta',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
            'amount_laari' => 'integer',
            'meta' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function amount(): Money
    {
        return Money::fromLaari($this->amount_laari);
    }
}
