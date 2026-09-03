<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            // Per-member attributes — previously stored as individual survey_answers, now embedded under each member per spec
            if (! Schema::hasColumn('household_members', 'is_pwd')) {
                $table->boolean('is_pwd')->default(false)->after('relationship');
            }
            if (! Schema::hasColumn('household_members', 'is_mentally_challenged')) {
                $table->boolean('is_mentally_challenged')->default(false)->after('is_pwd');
            }
            if (! Schema::hasColumn('household_members', 'is_osy')) {
                $table->boolean('is_osy')->nullable()->after('is_mentally_challenged');
            }
            if (! Schema::hasColumn('household_members', 'osy_last_grade')) {
                $table->string('osy_last_grade', 100)->nullable()->after('is_osy');
            }
            if (! Schema::hasColumn('household_members', 'bedridden_status')) {
                $table->string('bedridden_status', 50)->default('No')->after('osy_last_grade');
            }
            if (! Schema::hasColumn('household_members', 'is_pregnant')) {
                $table->boolean('is_pregnant')->default(false)->after('bedridden_status');
            }
            // is_senior is derived from age >=60, but add stored column for fast aggregation if needed (nullable, computed on save)
            if (! Schema::hasColumn('household_members', 'is_senior')) {
                $table->boolean('is_senior')->default(false)->after('is_pregnant');
            }
        });

        Schema::table('households', function (Blueprint $table) {
            if (! Schema::hasColumn('households', 'live_in_status')) {
                $table->string('live_in_status', 10)->nullable()->after('head_name');
            }
            if (! Schema::hasColumn('households', 'live_in_years')) {
                $table->unsignedSmallInteger('live_in_years')->nullable()->after('live_in_status');
            }
            if (! Schema::hasColumn('households', 'live_in_reason')) {
                $table->text('live_in_reason')->nullable()->after('live_in_years');
            }
        });

        // Migrate existing survey_answers for individual questions into household_members where possible (best-effort)
        // Old codes: pwd_cat2, mentally_cat2, osy, bedridden_cat2, pregnant, seniors_90
        // We do not delete old answers; analytics will fallback to either source.
        try {
            $codeToMemberField = [
                'pwd_cat2' => 'is_pwd',
                'mentally_cat2' => 'is_mentally_challenged',
                'osy' => 'is_osy',
                'pregnant' => 'is_pregnant',
                'seniors_90' => 'is_senior',
            ];
            // Bedridden: old had Yes/No, new has 3 options; map Yes -> "Yes - Di na kabakod" as closest
            foreach ($codeToMemberField as $code => $field) {
                $answers = DB::table('survey_answers')
                    ->join('survey_questions', 'survey_questions.id', '=', 'survey_answers.survey_question_id')
                    ->where('survey_questions.code', $code)
                    ->whereNotNull('survey_answers.household_member_id')
                    ->get(['survey_answers.household_member_id', 'survey_answers.answer']);
                foreach ($answers as $a) {
                    $val = strtolower(trim($a->answer));
                    $bool = in_array($val, ['yes', '1', 'true', 'y', 'oo'], true);
                    if ($code === 'seniors_90') $bool = $bool || $val === 'yes';
                    try {
                        DB::table('household_members')->where('id', $a->household_member_id)->update([$field => $bool ? 1 : 0]);
                    } catch (\Throwable $e) {}
                }
            }
            // Bedridden separate
            $bedriddenAnswers = DB::table('survey_answers')
                ->join('survey_questions', 'survey_questions.id', '=', 'survey_answers.survey_question_id')
                ->where('survey_questions.code', 'bedridden_cat2')
                ->whereNotNull('survey_answers.household_member_id')
                ->get(['survey_answers.household_member_id', 'survey_answers.answer']);
            foreach ($bedriddenAnswers as $a) {
                $val = trim($a->answer);
                $status = 'No';
                if (stripos($val, 'Yes') !== false || strtolower($val) === 'yes') {
                    $status = 'Yes - Di na kabakod';
                }
                if (stripos($val, 'Mabakod') !== false) $status = 'Yes - Mabakod pa';
                try { DB::table('household_members')->where('id', $a->household_member_id)->update(['bedridden_status' => $status]); } catch (\Throwable $e) {}
            }
            // Backfill is_senior from age >=60
            DB::table('household_members')->where('age', '>=', 60)->update(['is_senior' => 1]);
            DB::table('household_members')->where('age', '<', 60)->orWhereNull('age')->update(['is_senior' => 0]);
        } catch (\Throwable $e) {
            // Non-critical during migration
        }
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $cols = ['is_pwd','is_mentally_challenged','is_osy','osy_last_grade','bedridden_status','is_pregnant','is_senior'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('household_members', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('households', function (Blueprint $table) {
            foreach (['live_in_status','live_in_years','live_in_reason'] as $col) {
                if (Schema::hasColumn('households', $col)) $table->dropColumn($col);
            }
        });
    }
};
