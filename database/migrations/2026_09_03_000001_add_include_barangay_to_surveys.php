<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            if (!Schema::hasColumn('surveys', 'include_barangay')) {
                $table->boolean('include_barangay')->default(false)->after('barangay_id');
            }
        });

        // Backfill: household surveys always include barangay, generic keep as is (false unless barangay_id already set)
        try {
            DB::table('surveys')->where('type', 'household')->update(['include_barangay' => true]);
            DB::table('surveys')->where('type', 'generic')->whereNotNull('barangay_id')->update(['include_barangay' => true]);
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            if (Schema::hasColumn('surveys', 'include_barangay')) {
                $table->dropColumn('include_barangay');
            }
        });
    }
};
