<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const ROLES = [
        ['name' => Role::OFFICER, 'label' => 'CAES Officer'],
        ['name' => Role::COORDINATOR, 'label' => 'CAES Coordinator'],
        ['name' => Role::FIELD_PERSONNEL, 'label' => 'Field Extension Personnel'],
        ['name' => Role::LGU, 'label' => 'LGU Representative'],
        ['name' => Role::MAYOR, 'label' => 'City Mayor'],
        ['name' => Role::GUEST, 'label' => 'Guest'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::updateOrCreate(['name' => $role['name']], ['label' => $role['label']]);
        }
    }
}
