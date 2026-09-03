<?php

namespace Database\Seeders;

use App\Models\Institute;
use App\Models\Program;
use Illuminate\Database\Seeder;

class InstituteSeeder extends Seeder
{
    /**
     * Institutes of the college and their degree programs.
     * Each CAES Coordinator belongs to one institute and manages
     * its extension activities.
     */
    public const INSTITUTES = [
        [
            'name' => 'Institute of Computer Studies',
            'programs' => [
                'Bachelor of Science in Computer Science',
            ],
        ],
        [
            'name' => 'Institute of Business and Financial Services',
            'programs' => [
                'Bachelor of Science in Office Administration',
                'Bachelor of Science in Business Administration major in Marketing Management',
                'Bachelor of Science in Business Administration major in Human Resource Management',
                'Bachelor of Science in Business Administration major in Financial Management',
            ],
        ],
        [
            'name' => 'Institute of Criminal Justice Education',
            'programs' => [
                'Bachelor of Science in Criminology',
                'Bachelor of Science in Industrial Security Management',
            ],
        ],
        [
            'name' => 'Institute of Health Science',
            'programs' => [
                'Bachelor of Science in Midwifery',
            ],
        ],
        [
            'name' => 'Institute of Teacher Education',
            'programs' => [
                'Bachelor of Secondary Education major in English',
                'Bachelor of Secondary Education major in Filipino',
                'Bachelor in Elementary Education',
                'Bachelor of Secondary Education major in Social Studies',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::INSTITUTES as $data) {
            $institute = Institute::updateOrCreate(['name' => $data['name']]);

            foreach ($data['programs'] as $programName) {
                Program::updateOrCreate(
                    ['name' => $programName],
                    ['institute_id' => $institute->id],
                );
            }
        }
    }
}
