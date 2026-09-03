<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix unique constraint to allow per-member answers: (response_id, question_id, household_member_id)
        // MySQL treats NULL as distinct, so multiple household-level answers (member_id NULL) for same question will still be unique per response+question
        // For SQLite tests, we need to drop and recreate
        try {
            Schema::table('survey_answers', function (Blueprint $table) {
                // Drop old unique if exists (name varies by driver)
                try {
                    $table->dropUnique(['survey_response_id', 'survey_question_id']);
                } catch (\Throwable $e) {
                    // Try default Laravel name
                    try { DB::statement('DROP INDEX survey_answers_survey_response_id_survey_question_id_unique ON survey_answers'); } catch (\Throwable $e2) {}
                }
            });
        } catch (\Throwable $e) {}

        // Add new unique with household_member_id (allow multiple per-member rows)
        try {
            Schema::table('survey_answers', function (Blueprint $table) {
                $table->unique(['survey_response_id', 'survey_question_id', 'household_member_id'], 'survey_answers_unique_member');
            });
        } catch (\Throwable $e) {
            // If already exists or SQLite handles NULL differently, ignore
        }

        // Fix duplicate labels: "Bedridden - Bedridden" -> "Bedridden", "PWD - PWD" -> "PWD", etc.
        $fixes = [
            'Bedridden - Bedridden' => 'Bedridden',
            'PWD - PWD' => 'PWD',
            'Mentally Challenged - Mentally Challenged' => 'Mentally Challenged',
            'Pregnant - Pregnant' => 'Pregnant',
            'Out-of-School Youth - Out-of-School Youth' => 'Out-of-School Youth',
            'Live-in - Live-in' => 'Live-in',
            'Senior Citizen - Senior Citizen' => 'Senior Citizen',
        ];
        foreach ($fixes as $old => $new) {
            // Exact match
            DB::table('survey_questions')->where('question_text', $old)->update(['question_text' => $new]);
            // With prefix like "3. PWD - PWD?" -> "3. PWD?"
            DB::table('survey_questions')->where('question_text', 'like', "% - {$new}%")->where('question_text', 'like', "%{$old}%")->update(['question_text' => DB::raw("REPLACE(question_text, ' - {$new}', '')")]);
            // More generic: if question_text contains " - " and both sides equal (case-insensitive)
            // Handle "Bedridden - Bedridden?" with question mark
            $like = "%{$new} - {$new}%";
            DB::table('survey_questions')->where('question_text', 'like', $like)->update(['question_text' => $new]);
        }
        // Handle numbered prefix duplicates like "3. PWD - PWD?" -> "PWD?"
        // For Hulagway seeder: "3. PWD - PWD?" should be "PWD"
        $all = DB::table('survey_questions')->get(['id','question_text']);
        foreach ($all as $q) {
            $text = $q->question_text;
            // Pattern: "X. Label - Label" or "Label - Label"
            if (preg_match('/^(?:\d+\.\s*)?(.+?)\s*-\s*\1(\?)?$/i', $text, $m)) {
                $new = trim($m[1]) . ($m[2] ?? '');
                DB::table('survey_questions')->where('id', $q->id)->update(['question_text' => $new]);
            }
        }
    }

    public function down(): void
    {
        try {
            Schema::table('survey_answers', function (Blueprint $table) {
                $table->dropUnique('survey_answers_unique_member');
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('survey_answers', function (Blueprint $table) {
                $table->unique(['survey_response_id', 'survey_question_id']);
            });
        } catch (\Throwable $e) {}
    }
};
