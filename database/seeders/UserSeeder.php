<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Short code map for coordinator emails.
     * Keeps addresses concise: coord.<code>@hulagway.com
     */
    private const PROGRAM_CODES = [
        'Bachelor of Science in Computer Science' => 'bscs',
        'Bachelor of Science in Office Administration' => 'bsoa',
        'Bachelor of Science in Business Administration major in Marketing Management' => 'bsbamm',
        'Bachelor of Science in Business Administration major in Human Resource Management' => 'bsbahrm',
        'Bachelor of Science in Business Administration major in Financial Management' => 'bsbafm',
        'Bachelor of Science in Criminology' => 'bscrim',
        'Bachelor of Science in Industrial Security Management' => 'bsism',
        'Bachelor of Science in Midwifery' => 'bsmid',
        'Bachelor of Secondary Education major in English' => 'bsedeng',
        'Bachelor of Secondary Education major in Filipino' => 'bsedfil',
        'Bachelor in Elementary Education' => 'beed',
        'Bachelor of Secondary Education major in Social Studies' => 'bsedsocstud',
    ];

    /**
     * Default accounts (change passwords before production).
     *
     * Each program gets exactly ONE CAES Coordinator account,
     * responsible for its own extension activities.
     */
    public function run(): void
    {
        // Clean up legacy .test domain accounts (previous seeder used hulagway.test with long slugs)
        User::where('email', 'like', '%@hulagway.test')->delete();

        $accounts = [
            [
                'name' => 'CAES Administrator',
                'email' => 'officer@hulagway.com',
                'role' => Role::OFFICER,
                'password' => 'password',
            ],
            [
                'name' => 'Field Personnel',
                'email' => 'field@hulagway.com',
                'role' => Role::FIELD_PERSONNEL,
                'password' => 'password',
            ],
            [
                'name' => 'City Mayor',
                'email' => 'mayor@hulagway.com',
                'role' => Role::MAYOR,
                'password' => 'password',
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $account['password'],
                    'role_id' => Role::where('name', $account['role'])->value('id'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
        }

        // One CAES Coordinator per program (12 programs across 5 institutes)
        $programs = Program::with('institute')->get();

        foreach ($programs as $program) {
            User::updateOrCreate(
                ['email' => $this->coordinatorEmail($program)],
                [
                    'name' => "Coordinator - {$program->name}",
                    'password' => 'password',
                    'role_id' => Role::where('name', Role::COORDINATOR)->value('id'),
                    'institute_id' => $program->institute_id,
                    'program_id' => $program->id,
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
        }
    }

    private function coordinatorEmail(Program $program): string
    {
        $code = self::PROGRAM_CODES[$program->name] ?? null;

        // Fallback: generate short slug from initials if map missing (should not happen)
        if ($code === null) {
            $code = strtolower(preg_replace('/[^a-z0-9]/', '', substr($program->name, 0, 10)));
        }

        return "coord.{$code}@hulagway.com";
    }
}
