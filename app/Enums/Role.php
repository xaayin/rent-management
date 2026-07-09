<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Council staff roles (PRD §2, §6.1). Backing values are the role names stored
 * by spatie/laravel-permission.
 */
enum Role: string
{
    case Administrator = 'administrator';
    case FinanceOfficer = 'finance_officer';
    case LandOfficer = 'land_officer';
    case Supervisor = 'supervisor';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::FinanceOfficer => 'Finance / Revenue Officer',
            self::LandOfficer => 'Land / Lease Officer',
            self::Supervisor => 'Supervisor / Manager',
            self::Auditor => 'Auditor',
        };
    }
}
