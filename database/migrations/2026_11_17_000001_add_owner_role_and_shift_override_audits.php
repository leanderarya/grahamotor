<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'admin', 'kasir') NOT NULL DEFAULT 'kasir'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('shift_override_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->decimal('closing_cash_physical', 15, 2);
            $table->decimal('expected_cash', 15, 2);
            $table->decimal('cash_difference', 15, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at']);
        });

        Schema::create('user_management_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('user_management_audits') && DB::table('user_management_audits')->exists()) {
            throw new \RuntimeException('Cannot remove user management audit records during rollback.');
        }

        if (Schema::hasTable('shift_override_audits') && DB::table('shift_override_audits')->exists()) {
            throw new \RuntimeException('Cannot remove shift override audit records during rollback.');
        }

        if (Schema::hasTable('users') && DB::table('users')->where('role', 'owner')->exists()) {
            throw new \RuntimeException('Cannot remove the owner role while owner accounts exist.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir'");
        }

        Schema::dropIfExists('user_management_audits');
        Schema::dropIfExists('shift_override_audits');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
