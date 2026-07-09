<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderKind;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One configurable reminder schedule entry (FR-NOT-02): whether it fires, how
 * many days from the due date, and the editable English template with merge
 * fields (FR-NOT-03). Changes are audit-logged (§4.10 configuration changes).
 */
class ReminderRule extends Model
{
    use LogsActivity;

    protected $fillable = [
        'kind',
        'enabled',
        'days',
        'template',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'enabled' => 'boolean',
            'days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'enabled', 'days', 'template'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('reminder_rule');
    }
}
