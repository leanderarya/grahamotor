<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pin', 255)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('pin')->exists()) {
            throw new RuntimeException('Cannot make user PINs required while users without a PIN exist.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('pin', 255)->nullable(false)->change();
        });
    }
};
