<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sdgs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('number')->unique(); // 1..17
            $table->string('code', 10)->unique(); // SDG_1
            $table->string('title');
            $table->string('short_title')->nullable();
            $table->string('color', 7); // #E5243B
            $table->string('icon_url');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('extension_activity_sdg', function (Blueprint $table) {
            $table->foreignId('extension_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sdg_id')->constrained('sdgs')->cascadeOnDelete();
            $table->primary(['extension_activity_id', 'sdg_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_activity_sdg');
        Schema::dropIfExists('sdgs');
    }
};
