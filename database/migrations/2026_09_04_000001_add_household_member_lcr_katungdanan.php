<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            if (!Schema::hasColumn('household_members', 'lcr_registered')) {
                $table->string('lcr_registered', 10)->default('No')->after('is_senior');
            }
            if (!Schema::hasColumn('household_members', 'lcr_reason')) {
                $table->text('lcr_reason')->nullable()->after('lcr_registered');
            }
            if (!Schema::hasColumn('household_members', 'katungdanan_status')) {
                $table->string('katungdanan_status', 10)->default('No')->after('lcr_reason');
            }
            if (!Schema::hasColumn('household_members', 'katungdanan_position')) {
                $table->text('katungdanan_position')->nullable()->after('katungdanan_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            foreach (['lcr_registered','lcr_reason','katungdanan_status','katungdanan_position'] as $col) {
                if (Schema::hasColumn('household_members', $col)) $table->dropColumn($col);
            }
        });
    }
};
