<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            InstituteSeeder::class,
            UserSeeder::class,
            BarangaySeeder::class,
            BarangayBoundarySeeder::class,
            SustainableDevelopmentGoalSeeder::class,
            HulagwaySurveySeeder::class,
        ]);
    }
}
