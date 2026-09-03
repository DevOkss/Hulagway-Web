<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_activity_documents', function (Blueprint $table) {
            $table->date('activity_date')->nullable()->after('extension_activity_id');
            $table->unsignedTinyInteger('progress')->nullable()->after('mime_type');
            $table->string('caption')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('extension_activity_documents', function (Blueprint $table) {
            $table->dropColumn(['activity_date', 'progress', 'caption']);
        });
    }
};
