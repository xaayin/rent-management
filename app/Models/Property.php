<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\PropertyStatus;
use App\Enums\UsageType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'land_number',
        'size_sqft',
        'usage_type',
        'location_notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'size_sqft' => 'integer',
            'usage_type' => UsageType::class,
            'status' => PropertyStatus::class,
        ];
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** @return HasMany<Lease, $this> */
    public function activeLeases(): HasMany
    {
        return $this->leases()->where('status', LeaseStatus::Active->value);
    }

    public function hasActiveLease(): bool
    {
        return $this->activeLeases()->exists();
    }

    public function isArchived(): bool
    {
        return $this->status === PropertyStatus::Archived;
    }

    public function archive(): void
    {
        $this->update(['status' => PropertyStatus::Archived]);
    }

    public function restore(): void
    {
        $this->update(['status' => PropertyStatus::Active]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'land_number', 'size_sqft', 'usage_type', 'location_notes', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('property');
    }
}
