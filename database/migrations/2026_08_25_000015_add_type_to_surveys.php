<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('surveys', 'type')) {
            Schema::table('surveys', function (Blueprint $table) {
                $table->string('type', 20)->default('generic')->after('description'); // household|generic
            });
        }
        // Existing surveys are household per Q1
        DB::table('surveys')->whereNull('type')->orWhere('type', '')->update(['type' => 'household']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('surveys', 'type')) {
            Schema::table('surveys', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
