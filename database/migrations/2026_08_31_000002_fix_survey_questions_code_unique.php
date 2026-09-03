<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change survey_questions.code from global UNIQUE to UNIQUE(survey_id, code)
        // Allows same code (e.g. pwd, bedridden) across different surveys.
        try {
            Schema::table('survey_questions', function (Blueprint $table) {
                // Drop legacy global unique on `code` (name varies by driver)
                try {
                    $table->dropUnique(['code']);
                } catch (\Throwable $e) {
                    try { DB::statement('DROP INDEX survey_questions_code_unique ON survey_questions'); } catch (\Throwable $e2) {}
                    try { DB::statement('DROP INDEX `survey_questions_code_unique` ON `survey_questions`'); } catch (\Throwable $e3) {}
                    // SQLite: index name auto-generated, try raw
                    try { DB::statement('DROP INDEX IF EXISTS survey_questions_code_unique'); } catch (\Throwable $e4) {}
                }
            });
        } catch (\Throwable $e) {}

        // De-duplicate existing codes per survey so unique constraint can be created
        $dups = DB::table('survey_questions')
            ->selectRaw('survey_id, code, COUNT(*) as cnt')
            ->whereNotNull('code')
            ->groupBy('survey_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($dups as $dup) {
            $rows = DB::table('survey_questions')
                ->where('survey_id', $dup->survey_id)
                ->where('code', $dup->code)
                ->orderBy('id')
                ->get(['id']);
            // Keep first, null out the rest (code is nullable)
            foreach ($rows->slice(1) as $row) {
                DB::table('survey_questions')->where('id', $row->id)->update(['code' => null]);
            }
        }

        try {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->unique(['survey_id', 'code'], 'survey_questions_survey_code_unique');
            });
        } catch (\Throwable $e) {
            // Already exists or SQLite quirk
        }
    }

    public function down(): void
    {
        try {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->dropUnique('survey_questions_survey_code_unique');
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->unique('code', 'survey_questions_code_unique');
            });
        } catch (\Throwable $e) {}
    }
};
