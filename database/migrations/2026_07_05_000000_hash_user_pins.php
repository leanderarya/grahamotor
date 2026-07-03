<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // Hash all existing plaintext PINs
        $users = DB::table('users')
            ->whereNotNull('pin')
            ->where('pin', '!=', '')
            ->get();

        foreach ($users as $user) {
            // Only hash if not already hashed (check for bcrypt prefix)
            if (!str_starts_with($user->pin, '$2y$')) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['pin' => Hash::make($user->pin)]);
            }
        }
    }

    public function down(): void
    {
        // Cannot reverse — PINs are one-way hashed
        // This is acceptable for a security migration
    }
};
