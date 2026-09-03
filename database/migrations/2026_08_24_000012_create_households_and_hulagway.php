<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Households — one per survey submission (per Purok household, as in Silanga/Maquilao samples)
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $table->string('purok', 50); // required input, e.g. "4" / "PUROK 4", not aggregated
            $table->string('household_code')->unique()->nullable(); // e.g. BRGY-001-0001, generated if not provided
            $table->string('address')->nullable();
            $table->string('head_name');
            $table->string('contact_no')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['barangay_id', 'purok']);
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_response_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('sex', 20)->nullable(); // Male/Female/Others
            $table->string('civil_status', 30)->nullable();
            $table->string('relationship', 50)->nullable(); // Head, Asawa, Anak, etc.
            $table->boolean('is_head')->default(false);
            $table->timestamps();

            $table->index('household_id');
        });

        // Link survey_responses to household + add purok (required input but not aggregated per your spec)
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('survey_id')->constrained()->nullOnDelete();
            $table->string('purok', 50)->nullable()->after('barangay_id');
        });

        // Hulagway code for stable map aggregation (Category 2 only for vulnerable groups)
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('type')->unique();
        });

        // Backfill existing null barangay_id to first barangay (barangay mandatory per spec, enforced via validation)
        $firstBarangayId = DB::table('barangays')->orderBy('id')->value('id');
        if ($firstBarangayId) {
            DB::table('surveys')->whereNull('barangay_id')->update(['barangay_id' => $firstBarangayId]);
            DB::table('survey_responses')->whereNull('barangay_id')->update(['barangay_id' => $firstBarangayId]);
            DB::table('survey_responses')->whereNull('purok')->update(['purok' => '1']);
        }
        // Note: barangay_id and purok remain nullable at DB level for SQLite compatibility (tests use :memory:),
        // but are enforced as required via FormRequest validation and service checks.
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropColumn('code');
        });
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn('purok');
        });
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');
    }
};
