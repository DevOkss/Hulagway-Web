<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('source_field_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->string('entity_scope', 20)->default('response'); // individual|household|response
            $table->string('operator', 20)->default('='); // =, !=, <, <=, >, >=, contains, in, between, is_true
            $table->text('condition_value')->nullable(); // string or JSON array
            $table->string('aggregation', 20)->default('count'); // count, percentage, avg, sum, min, max, mode, per_option
            $table->string('geographic_level', 20)->default('barangay'); // barangay only per Q1
            $table->string('visualization', 20)->default('choropleth'); // pins|choropleth
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['survey_id', 'name']);
            $table->index('source_field_id');
        });

        // Link individual-scope answers to a specific household member
        if (! Schema::hasColumn('survey_answers', 'household_member_id')) {
            Schema::table('survey_answers', function (Blueprint $table) {
                $table->foreignId('household_member_id')->nullable()->after('survey_question_id')->constrained('household_members')->nullOnDelete();
                $table->index('household_member_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('survey_answers', 'household_member_id')) {
            Schema::table('survey_answers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('household_member_id');
            });
        }
        Schema::dropIfExists('indicators');
    }
};
