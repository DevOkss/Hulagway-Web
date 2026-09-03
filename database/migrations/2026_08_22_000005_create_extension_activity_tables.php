<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_activities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('barangay_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('location')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('planned'); // planned | ongoing | completed | cancelled
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->unsignedInteger('faculty_participants')->default(0);
            $table->unsignedInteger('student_participants')->default(0);
            $table->unsignedInteger('beneficiaries')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'start_date']);
        });

        Schema::create('extension_activity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_activity_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_activity_documents');
        Schema::dropIfExists('extension_activities');
    }
};
