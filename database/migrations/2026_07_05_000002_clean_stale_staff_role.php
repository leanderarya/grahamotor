<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First, update any 'staff' users to 'kasir' (if any exist)
        DB::table('users')
            ->where('role', 'staff')
            ->update(['role' => 'kasir']);

        // Then modify the ENUM to remove 'staff'
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir'");
        }
    }

    public function down(): void
    {
        // Re-add 'staff' to ENUM
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff', 'kasir') NOT NULL DEFAULT 'kasir'");
        }
    }
};
