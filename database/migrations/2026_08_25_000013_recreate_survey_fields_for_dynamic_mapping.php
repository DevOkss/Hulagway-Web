<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh start per Q3: remove existing surveys and responses (households handled via cascade)
        Schema::disableForeignKeyConstraints();
        DB::table('survey_answers')->truncate();
        DB::table('survey_responses')->truncate();
        DB::table('household_members')->truncate();
        DB::table('households')->truncate();
        DB::table('survey_options')->truncate();
        DB::table('survey_questions')->truncate();
        DB::table('surveys')->truncate();
        Schema::enableForeignKeyConstraints();

        // Recreate survey fields per prompt: add data_scope + map_enabled to survey_questions
        // Keep survey_questions table for backward compat, but add prompt's recommended columns
        if (! Schema::hasColumn('survey_questions', 'data_scope')) {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->string('data_scope', 20)->default('response')->after('type'); // individual|household|response|location
                $table->boolean('map_enabled')->default(true)->after('data_scope');
                $table->json('options_json')->nullable()->after('code'); // denormalized options for faster map (prompt's options JSON)
            });
        }

        // Ensure survey_responses has purok string (already) and household_id (already), but ensure purok_id not needed per Q1
        // No purok table per Q1 (only barangay pins)

        // Seed a clean state: ensure at least one barangay exists for FK
        if (DB::table('barangays')->count() === 0) {
            DB::table('barangays')->insert(['name' => 'Tangub City', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('survey_questions', 'data_scope')) {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->dropColumn(['data_scope', 'map_enabled', 'options_json']);
            });
        }
    }
};
