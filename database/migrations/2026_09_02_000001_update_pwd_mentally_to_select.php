<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert is_pwd and is_mentally_challenged from boolean to string with 3 options
        // PWD: No, Yes - Makalakaw pa, Yes - Di na ka lakaw
        // Mentally: No, Yes - dili problema sa katilingban, Yes - hasol sa katilingban
        // Use raw SQL to avoid doctrine/dbal requirement

        // For MySQL / MariaDB
        try {
            // is_pwd: boolean -> varchar
            // First handle existing data conversion, then alter column
            if (Schema::hasColumn('household_members', 'is_pwd')) {
                // Convert existing boolean values to new strings (1 => Yes - Di na ka lakaw, 0 => No)
                try {
                    DB::table('household_members')->where('is_pwd', 1)->update(['is_pwd' => 'Yes - Di na ka lakaw']);
                    DB::table('household_members')->where('is_pwd', 0)->update(['is_pwd' => 'No']);
                    // Also handle string '1' / 'true'
                    DB::table('household_members')->where('is_pwd', '1')->update(['is_pwd' => 'Yes - Di na ka lakaw']);
                } catch (\Throwable $e) {}

                try {
                    DB::statement("ALTER TABLE household_members MODIFY COLUMN is_pwd VARCHAR(50) NOT NULL DEFAULT 'No'");
                } catch (\Throwable $e) {
                    // SQLite fallback: recreate column via copy (will be handled by sqlite migration)
                    try {
                        DB::statement("ALTER TABLE household_members ALTER COLUMN is_pwd TYPE VARCHAR(50)");
                    } catch (\Throwable $e2) {}
                }
            }

            if (Schema::hasColumn('household_members', 'is_mentally_challenged')) {
                try {
                    DB::table('household_members')->where('is_mentally_challenged', 1)->update(['is_mentally_challenged' => 'Yes - hasol sa katilingban']);
                    DB::table('household_members')->where('is_mentally_challenged', 0)->update(['is_mentally_challenged' => 'No']);
                    DB::table('household_members')->where('is_mentally_challenged', '1')->update(['is_mentally_challenged' => 'Yes - hasol sa katilingban']);
                } catch (\Throwable $e) {}

                try {
                    DB::statement("ALTER TABLE household_members MODIFY COLUMN is_mentally_challenged VARCHAR(50) NOT NULL DEFAULT 'No'");
                } catch (\Throwable $e) {
                    try {
                        DB::statement("ALTER TABLE household_members ALTER COLUMN is_mentally_challenged TYPE VARCHAR(50)");
                    } catch (\Throwable $e2) {}
                }
            }

            // Ensure defaults for any nulls
            DB::table('household_members')->whereNull('is_pwd')->update(['is_pwd' => 'No']);
            DB::table('household_members')->whereNull('is_mentally_challenged')->update(['is_mentally_challenged' => 'No']);
        } catch (\Throwable $e) {
            // For SQLite testing, recreate table if needed
            if (DB::getDriverName() === 'sqlite') {
                // SQLite: add new string columns if boolean cannot be altered easily
                // Check if already string, else recreate
                try {
                    Schema::table('household_members', function (Blueprint $table) {
                        if (!Schema::hasColumn('household_members', 'is_pwd_tmp')) {
                            $table->string('is_pwd_tmp', 50)->default('No');
                        }
                    });
                    DB::statement("UPDATE household_members SET is_pwd_tmp = CASE WHEN is_pwd = 1 OR is_pwd = '1' THEN 'Yes - Di na ka lakaw' ELSE 'No' END");
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->dropColumn('is_pwd');
                    });
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->string('is_pwd', 50)->default('No');
                    });
                    DB::statement("UPDATE household_members SET is_pwd = is_pwd_tmp");
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->dropColumn('is_pwd_tmp');
                    });

                    Schema::table('household_members', function (Blueprint $table) {
                        $table->string('is_mentally_challenged_tmp', 50)->default('No');
                    });
                    DB::statement("UPDATE household_members SET is_mentally_challenged_tmp = CASE WHEN is_mentally_challenged = 1 OR is_mentally_challenged = '1' THEN 'Yes - hasol sa katilingban' ELSE 'No' END");
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->dropColumn('is_mentally_challenged');
                    });
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->string('is_mentally_challenged', 50)->default('No');
                    });
                    DB::statement("UPDATE household_members SET is_mentally_challenged = is_mentally_challenged_tmp");
                    Schema::table('household_members', function (Blueprint $table) {
                        $table->dropColumn('is_mentally_challenged_tmp');
                    });
                } catch (\Throwable $e2) {}
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE household_members MODIFY COLUMN is_pwd TINYINT(1) NOT NULL DEFAULT 0");
            DB::statement("ALTER TABLE household_members MODIFY COLUMN is_mentally_challenged TINYINT(1) NOT NULL DEFAULT 0");
            // Convert back: Yes => 1, No => 0
            DB::table('household_members')->where('is_pwd', '!=', 'No')->update(['is_pwd' => 1]);
            DB::table('household_members')->where('is_pwd', 'No')->update(['is_pwd' => 0]);
            DB::table('household_members')->where('is_mentally_challenged', '!=', 'No')->update(['is_mentally_challenged' => 1]);
            DB::table('household_members')->where('is_mentally_challenged', 'No')->update(['is_mentally_challenged' => 0]);
        } catch (\Throwable $e) {}
    }
};
