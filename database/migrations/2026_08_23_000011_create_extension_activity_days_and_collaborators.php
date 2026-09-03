<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fragmented activity days: Day 1..N each with its own selected date
        Schema::create('extension_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->date('activity_date');
            $table->timestamps();

            $table->unique(['extension_activity_id', 'day_number']);
            $table->index('activity_date');
        });

        // Collaboration: other programs joining an activity led by `program_id`
        Schema::create('extension_activity_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained();
            $table->timestamps();

            $table->unique(['extension_activity_id', 'program_id'], 'ext_activity_program_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_activity_collaborators');
        Schema::dropIfExists('extension_activity_days');
    }
};
