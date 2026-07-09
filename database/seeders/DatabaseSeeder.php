<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ReminderRulesSeeder::class);

        // One demo account per role for local sign-in (password: "password").
        // The permission matrix (§6.1) means, e.g., the administrator does not
        // manage the registry — sign in as the supervisor/land officer for that.
        $accounts = [
            ['Administrator', 'admin@example.com', Role::Administrator],
            ['Supervisor', 'supervisor@example.com', Role::Supervisor],
            ['Land Officer', 'land@example.com', Role::LandOfficer],
            ['Finance Officer', 'finance@example.com', Role::FinanceOfficer],
            ['Auditor', 'auditor@example.com', Role::Auditor],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            User::factory()->create(['name' => $name, 'email' => $email])
                ->assignRole($role->value);
        }

        $this->call(DemoRegistrySeeder::class);
    }
}
