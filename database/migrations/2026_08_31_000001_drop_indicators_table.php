<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('indicators');
    }

    public function down(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('source_field_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->string('entity_scope', 20)->default('response');
            $table->string('operator', 20)->default('=');
            $table->text('condition_value')->nullable();
            $table->string('aggregation', 20)->default('count');
            $table->string('geographic_level', 20)->default('barangay');
            $table->string('visualization', 20)->default('choropleth');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['survey_id', 'name']);
            $table->index('source_field_id');
        });
    }
};
