<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_activity_daily_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_activity_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('scheduled_date');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_activity_daily_tasks');
    }
};
